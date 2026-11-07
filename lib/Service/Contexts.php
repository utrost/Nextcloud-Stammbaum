<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
final class Contexts
{
    public function __construct(
        private Store $store,
        private Trees $trees,
        private People $people,
        private Evidence $evidence,
    ) {}
    private function row(string $tree, string $id, array $m): array
    {
        $r = $this->store->one('SELECT * FROM *PREFIX*stb_contexts WHERE tree_id = ? AND id = ?', [
            $tree,
            $id,
        ]);
        if (!$r || ($r['visibility'] !== 'members' && !Trees::management($m))) {
            throw Problem::missing();
        }
        $r['members'] = array_map(
            fn($p) => [
                'personId' => $p['person_id'],
                'role' => $p['role'],
                'description' => $p['description'],
                'person' => $this->people->dto($this->people->row($tree, $p['person_id'], $m)),
            ],
            $this->store->rows(
                'SELECT * FROM *PREFIX*stb_context_people WHERE context_id = ? ORDER BY position, id',
                [$id],
            ),
        );
        $r['relations'] = [];
        foreach (json_decode($r['relation_ids'], true) as $rid) {
            $rel = $this->store->one('SELECT * FROM *PREFIX*stb_relations WHERE id = ? AND tree_id = ?', [
                $rid,
                $tree,
            ]);
            if ($rel) {
                $this->people->row($tree, $rel['source_id'], $m);
                $this->people->row($tree, $rel['target_id'], $m);
                $r['relations'][] = [
                    'id' => $rid,
                    'sourceId' => $rel['source_id'],
                    'targetId' => $rel['target_id'],
                    'kind' => $rel['kind'],
                    'parentType' => $rel['parent_type'],
                    'active' => (bool) $rel['active'],
                ];
            }
        }
        return $r;
    }
    private function dto(array $r): array
    {
        return [
            'id' => $r['id'],
            'label' => $r['label'],
            'visibility' => $r['visibility'],
            'revision' => (int) $r['revision'],
            'members' => $r['members'],
            'relations' => $r['relations'],
        ];
    }
    public function get(string $tree, string $id, string $actor): array
    {
        return $this->dto($this->row($tree, $id, $this->trees->access($tree, $actor)));
    }
    private function visibleScope(string $tree, array $m, string $person): array
    {
        $where = 'c.tree_id = ?';
        $params = [$tree];
        if ($person !== '') {
            $where .=
                ' AND EXISTS (SELECT 1 FROM *PREFIX*stb_context_people a WHERE a.context_id = c.id AND a.person_id = ?)';
            $params[] = $person;
        }
        if (!Trees::management($m)) {
            $where .= " AND c.visibility = 'members'";
        }
        $where .=
            ' AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_context_people a LEFT JOIN *PREFIX*stb_people p ON p.id = a.person_id WHERE a.context_id = c.id AND (p.id IS NULL OR p.deleted = 1' .
            (!Trees::management($m) ? " OR p.visibility <> 'members'" : '') .
            '))';
        return [$where, $params];
    }
    public function hasOutsideMember(string $tree, array $m, string $person, array $visible): bool
    {
        // Boundary discovery needs an authorized existence check, not up to
        // twenty fully hydrated contexts for each of two hundred graph people.
        [$where, $params] = $this->visibleScope($tree, $m, $person);
        $slots = implode(',', array_fill(0, count($visible), '?'));
        return $this->store->one(
            'SELECT c.id FROM *PREFIX*stb_contexts c WHERE ' .
                $where .
                ' AND EXISTS (SELECT 1 FROM *PREFIX*stb_context_people outside_member WHERE outside_member.context_id = c.id AND outside_member.person_id NOT IN (' .
                $slots .
                ')) LIMIT 1',
            array_merge($params, $visible),
        ) !== null;
    }
    public function list(string $tree, string $actor, string $person = '', int $page = 0): array
    {
        $m = $this->trees->access($tree, $actor);
        if ($person !== '') {
            $this->people->row($tree, $person, $m);
        }
        [$where, $params] = $this->visibleScope($tree, $m, $person);
        $rows = $this->store->rows(
            'SELECT c.id FROM *PREFIX*stb_contexts c WHERE ' .
                $where .
                ' ORDER BY c.label,c.id LIMIT 21 OFFSET ' .
                max(0, min(10000, $page)) * 20,
            $params,
        );
        return [
            'items' => array_map(fn($r) => $this->get($tree, $r['id'], $actor), array_slice($rows, 0, 20)),
            'nextPage' => count($rows) > 20 ? $page + 1 : null,
        ];
    }
    public function save(string $tree, ?string $id, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $id, $actor, $b) {
            $m = $this->trees->access($tree, $actor, 'editor');
            $old = null;
            $created = false;
            if ($id) {
                $old = $this->row($tree, $id, $m);
                $this->store->version($old, $b['revision'] ?? null);
                $this->evidence->assertEditableTarget($tree, 'context', $id, $actor);
            }
            $label = Values::text($b['label'] ?? '', 'label', 250, true);
            $visibility = Values::choice(
                $b['visibility'] ?? 'members',
                ['members', 'management'],
                'visibility',
            );
            if (!Trees::management($m) && $visibility !== ($old['visibility'] ?? 'members')) {
                throw new Problem('forbidden', 'Only management can change visibility.', 403);
            }
            if (
                $old &&
                $old['visibility'] === 'management' &&
                $visibility === 'members' &&
                ($b['confirmRelease'] ?? false) !== true
            ) {
                throw new Problem('release_confirmation', 'Confirm release of this context to tree members.');
            }
            $members = $b['members'] ?? [];
            if (
                !is_array($members) ||
                !array_is_list($members) ||
                count($members) < 1 ||
                count($members) > 400
            ) {
                throw new Problem('context_members', 'Choose between one and 400 context members.');
            }
            $clean = [];
            $seen = [];
            foreach ($members as $p) {
                if (!is_array($p)) {
                    throw new Problem('context_members', 'Choose valid members.');
                }
                $pid = Values::text($p['personId'] ?? '', 'personId', 36, true);
                if (isset($seen[$pid])) {
                    throw new Problem('duplicate_member', 'Each person may appear only once in a context.');
                }
                $seen[$pid] = true;
                $this->people->row($tree, $pid, $m);
                $clean[] = [
                    'personId' => $pid,
                    'role' => Values::choice($p['role'] ?? 'child', ['parent', 'child'], 'role'),
                    'description' => Values::text($p['description'] ?? '', 'description', 250),
                ];
            }
            $rels = $b['relationIds'] ?? [];
            if (!is_array($rels) || !array_is_list($rels) || count($rels) > 1000) {
                throw new Problem('context_relations', 'Choose up to 1,000 existing connections.');
            }
            $active = [];
            foreach ($rels as $rid) {
                $rid = Values::text($rid, 'relationId', 36, true);
                $r = $this->store->one('SELECT * FROM *PREFIX*stb_relations WHERE tree_id = ? AND id = ?', [
                    $tree,
                    $rid,
                ]);
                if (!$r) {
                    throw Problem::missing();
                }
                if (!isset($seen[$r['source_id']], $seen[$r['target_id']])) {
                    throw new Problem(
                        'context_relations',
                        'Connection endpoints must belong to the context.',
                    );
                }
                if ((int) $r['active'] === 1) {
                    $active[$rid] = $rid;
                }
            }
            if ($id) {
                $this->store->execute(
                    'UPDATE *PREFIX*stb_contexts SET label = ?, visibility = ?, relation_ids = ?, revision = revision + 1, updated_at = ? WHERE id = ?',
                    [$label, $visibility, Store::json(array_values($active)), time(), $id],
                );
                $this->store->execute('DELETE FROM *PREFIX*stb_context_people WHERE context_id = ?', [$id]);
                $this->members($id, $clean);
            } else {
                $id = $this->store->receipt(
                    $actor,
                    'context:' . $tree,
                    (string) ($b['requestId'] ?? ''),
                    $b,
                    function () use ($tree, $label, $visibility, $active, $clean, &$created) {
                        $created = true;
                        $id = Store::id();
                        $this->store->insert('stb_contexts', [
                            'id' => $id,
                            'tree_id' => $tree,
                            'label' => $label,
                            'visibility' => $visibility,
                            'relation_ids' => Store::json(array_values($active)),
                            'revision' => 1,
                            'created_at' => time(),
                            'updated_at' => time(),
                        ]);
                        $this->members($id, $clean);
                        return $id;
                    },
                );
            }
            if ($old || $created) {
                $this->store->bump($tree);
                $this->store->audit(
                    $tree,
                    $actor,
                    $old ? 'context_updated' : 'context_created',
                    null,
                    ['contextId' => $id, 'members' => $clean],
                    'management',
                );
            }
            return $this->get($tree, $id, $actor);
        });
    }
    private function members(string $id, array $members): void
    {
        foreach ($members as $i => $m) {
            $this->store->insert('stb_context_people', [
                'id' => Store::id(),
                'context_id' => $id,
                'person_id' => $m['personId'],
                'role' => $m['role'],
                'description' => $m['description'],
                'position' => $i,
            ]);
        }
    }
}
