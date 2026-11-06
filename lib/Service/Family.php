<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
final class Family
{
    public function __construct(
        private Store $store,
        private Trees $trees,
        private People $people,
        private Contexts $contexts,
    ) {}
    private function edges(string $tree, array $m, ?string $person = null, int $limit = 401): array
    {
        $sql =
            'SELECT r.* FROM *PREFIX*stb_relations r INNER JOIN *PREFIX*stb_people p ON p.id = r.source_id INNER JOIN *PREFIX*stb_people q ON q.id = r.target_id WHERE r.tree_id = ? AND r.active = 1 AND p.deleted = 0 AND q.deleted = 0';
        $params = [$tree];
        if (!Trees::management($m)) {
            $sql .= ' AND p.visibility = ? AND q.visibility = ?';
            array_push($params, 'members', 'members');
        }
        if ($person !== null) {
            $sql .= ' AND (r.source_id = ? OR r.target_id = ?)';
            array_push($params, $person, $person);
        }
        return $this->store->rows($sql . ' ORDER BY r.created_at, r.id LIMIT ' . $limit, $params);
    }
    public function list(string $tree, string $person, string $actor): array
    {
        $m = $this->trees->access($tree, $actor);
        $this->people->row($tree, $person, $m);
        $rows = $this->edges($tree, $m, $person);
        return [
            'items' => array_map(function ($r) use ($tree, $person, $m) {
                $other = $r['source_id'] === $person ? $r['target_id'] : $r['source_id'];
                return $this->edgeDto($r) + [
                    'person' => $this->people->dto($this->people->row($tree, $other, $m)),
                ];
            }, array_slice($rows, 0, 400)),
            'limited' => count($rows) > 400,
        ];
    }
    private function edgeDto(array $r): array
    {
        return [
            'id' => $r['id'],
            'sourceId' => $r['source_id'],
            'targetId' => $r['target_id'],
            'kind' => $r['kind'],
            'parentType' => $r['parent_type'],
            'revision' => (int) $r['revision'],
        ];
    }
    private function biologicalCycle(string $tree, string $parent, string $child): bool
    {
        $queue = [$child];
        $seen = [];
        $budget = 0;
        while ($queue) {
            $id = array_shift($queue);
            if ($id === $parent) {
                return true;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (++$budget > 10000) {
                throw new Problem(
                    'graph_limit',
                    'This connection needs review because the ancestry check exceeded its safe limit.',
                    409,
                );
            }
            foreach (
                $this->store->rows(
                    'SELECT target_id FROM *PREFIX*stb_relations WHERE tree_id = ? AND source_id = ? AND kind = ? AND parent_type = ? AND active = 1',
                    [$tree, $id, 'parent', 'biological'],
                )
                as $r
            ) {
                $queue[] = $r['target_id'];
            }
        }
        return false;
    }
    public function connect(string $tree, string $person, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $person, $actor, $b) {
            $m = $this->trees->access($tree, $actor, 'editor');
            $focus = $this->people->row($tree, $person, $m);
            // A retry is recognized before its old versions are rejected, but current access is always checked.
            $id = $this->store->receipt(
                $actor,
                'relative:' . $tree . ':' . $person,
                (string) ($b['requestId'] ?? ''),
                $b,
                function () use ($tree, $person, $actor, $b, $m, $focus) {
                    $this->store->version($focus, $b['revision'] ?? null);
                    $direction = Values::choice(
                        $b['direction'] ?? '',
                        ['parent', 'child', 'partner'],
                        'direction',
                    );
                    if (isset($b['person']) && is_array($b['person'])) {
                        $other = $this->people->createInside($tree, $actor, $b['person'], $m);
                    } else {
                        $other = Values::text($b['personId'] ?? '', 'personId', 36, true);
                        $target = $this->people->row($tree, $other, $m);
                        $this->store->version($target, $b['targetRevision'] ?? null);
                    }
                    if ($other === $person) {
                        throw new Problem('self_connection', 'A person cannot be connected to themselves.');
                    }
                    $kind = $direction === 'partner' ? 'partner' : 'parent';
                    $type =
                        $kind === 'partner'
                            ? 'unknown'
                            : Values::choice(
                                $b['parentType'] ?? 'unknown',
                                ['unknown', 'biological', 'adoptive', 'foster', 'step', 'other'],
                                'parentType',
                            );
                    [$source, $target] = $direction === 'parent' ? [$other, $person] : [$person, $other];
                    if ($kind === 'partner' && strcmp($source, $target) > 0) {
                        [$source, $target] = [$target, $source];
                    }
                    $duplicate = $this->store->one(
                        'SELECT id FROM *PREFIX*stb_relations WHERE tree_id = ? AND source_id = ? AND target_id = ? AND kind = ? AND parent_type = ? AND active = 1',
                        [$tree, $source, $target, $kind, $type],
                    );
                    if ($duplicate && ($kind === 'parent' || ($b['confirmEpisode'] ?? false) !== true)) {
                        throw new Problem(
                            'duplicate_connection',
                            $kind === 'parent'
                                ? 'This parent connection already exists.'
                                : 'Confirm that this is a separate partnership episode.',
                            409,
                        );
                    }
                    if (
                        $kind === 'parent' &&
                        $type === 'biological' &&
                        $this->biologicalCycle($tree, $source, $target)
                    ) {
                        throw new Problem(
                            'ancestry_cycle',
                            'This would create a biological ancestry loop.',
                            409,
                        );
                    }
                    $id = Store::id();
                    $this->store->insert('stb_relations', [
                        'id' => $id,
                        'tree_id' => $tree,
                        'source_id' => $source,
                        'target_id' => $target,
                        'kind' => $kind,
                        'parent_type' => $type,
                        'revision' => 1,
                        'active' => 1,
                        'created_at' => time(),
                    ]);
                    $this->store->execute(
                        'UPDATE *PREFIX*stb_people SET revision = revision + 1 WHERE id = ? OR id = ?',
                        [$source, $target],
                    );
                    $this->store->bump($tree);
                    // Connection history contains references; keep it management-only until dependency-aware history is implemented.
                    $this->store->audit(
                        $tree,
                        $actor,
                        'connection_created',
                        $person,
                        ['relationId' => $id, 'kind' => $kind, 'parentType' => $type],
                        'management',
                    );
                    return $id;
                },
            );
            $r = $this->store->one(
                'SELECT * FROM *PREFIX*stb_relations WHERE id = ? AND tree_id = ? AND active = 1',
                [$id, $tree],
            );
            if (!$r) {
                throw Problem::missing();
            }
            $this->people->row($tree, $r['source_id'], $m);
            $this->people->row($tree, $r['target_id'], $m);
            return $this->edgeDto($r);
        });
    }
    public function remove(string $tree, string $id, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $id, $actor, $b) {
            $m = $this->trees->access($tree, $actor, 'editor');
            $r = $this->store->one(
                'SELECT * FROM *PREFIX*stb_relations WHERE id = ? AND tree_id = ? AND active = 1',
                [$id, $tree],
            );
            if (!$r) {
                throw Problem::missing();
            }
            $this->people->row($tree, $r['source_id'], $m);
            $this->people->row($tree, $r['target_id'], $m);
            $this->store->version($r, $b['revision'] ?? null);
            $this->store->execute(
                'UPDATE *PREFIX*stb_relations SET active = 0, revision = revision + 1 WHERE id = ?',
                [$id],
            );
            $this->store->execute(
                'UPDATE *PREFIX*stb_people SET revision = revision + 1 WHERE id = ? OR id = ?',
                [$r['source_id'], $r['target_id']],
            );
            $this->store->bump($tree);
            $this->store->audit(
                $tree,
                $actor,
                'connection_removed',
                null,
                ['relationId' => $id],
                'management',
            );
            return ['ok' => true];
        });
    }
    public function graph(
        string $tree,
        string $actor,
        string $focus,
        string $mode = 'family',
        int $up = 3,
        int $down = 2,
    ): array {
        $m = $this->trees->access($tree, $actor);
        $mode = Values::choice($mode, ['family', 'ancestors', 'descendants'], 'mode');
        $up = max(0, min(8, $up));
        $down = max(0, min(8, $down));
        if ($focus === '') {
            return ['people' => [], 'relations' => [], 'limited' => false, 'focusId' => null];
        }
        $groups = [];
        $first = $this->people->dto($this->people->row($tree, $focus, $m));
        $nodes = [$focus => $first];
        $levels = [$focus => 0];
        $edges = [];
        $limited = false;
        $cache = [];
        $load = function ($id) use ($tree, $m, &$cache, &$limited) {
            if (!isset($cache[$id])) {
                $e = $this->edges($tree, $m, $id);
                if (count($e) > 400) {
                    $limited = true;
                }
                $cache[$id] = array_slice($e, 0, 400);
            }
            return $cache[$id];
        };
        $add = function ($id, $level) use ($tree, $m, &$nodes, &$levels, &$limited) {
            if (isset($nodes[$id])) {
                return true;
            }
            if (count($nodes) >= 200) {
                $limited = true;
                return false;
            }
            $nodes[$id] = $this->people->dto($this->people->row($tree, $id, $m));
            $levels[$id] = $level;
            return true;
        };
        foreach (
            [['up', $mode === 'descendants' ? 0 : $up], ['down', $mode === 'ancestors' ? 0 : $down]]
            as [$direction, $depth]
        ) {
            $queue = [[$focus, 0]];
            $seen = [];
            while ($queue) {
                [$id, $distance] = array_shift($queue);
                if (isset($seen[$id]) || $distance >= $depth) {
                    continue;
                }
                $seen[$id] = true;
                foreach ($load($id) as $e) {
                    if ($e['kind'] !== 'parent') {
                        continue;
                    }
                    $next = null;
                    if ($direction === 'up' && $e['target_id'] === $id) {
                        $next = $e['source_id'];
                    }
                    if ($direction === 'down' && $e['source_id'] === $id) {
                        $next = $e['target_id'];
                    }
                    if ($next !== null && $add($next, ($direction === 'up' ? -1 : 1) * ($distance + 1))) {
                        $queue[] = [$next, $distance + 1];
                    }
                }
            }
        }
        if ($mode === 'family') {
            $contextPage = $this->contexts->list($tree, $actor, $focus);
            if ($contextPage['nextPage'] !== null) {
                $limited = true;
            }
            foreach ($contextPage['items'] as $context) {
                $focusRole = array_values(
                    array_filter($context['members'], fn($p) => $p['personId'] === $focus),
                )[0]['role'];
                $members = [];
                foreach ($context['members'] as $member) {
                    $level = $member['role'] === $focusRole ? 0 : ($member['role'] === 'parent' ? -1 : 1);
                    if (
                        ($level >= 0 || $up > 0) &&
                        ($level <= 0 || $down > 0) &&
                        $add($member['personId'], $level)
                    ) {
                        $members[] = [
                            'personId' => $member['personId'],
                            'role' => $member['role'],
                            'description' => $member['description'],
                        ];
                    }
                }
                $groups[] = ['id' => $context['id'], 'label' => $context['label'], 'members' => $members];
            }

            // Focus siblings arise only from explicit visible parents.
            foreach ($load($focus) as $e) {
                if ($e['kind'] === 'parent' && $e['target_id'] === $focus && isset($nodes[$e['source_id']])) {
                    foreach ($load($e['source_id']) as $s) {
                        if ($s['kind'] === 'parent' && $s['source_id'] === $e['source_id']) {
                            $add($s['target_id'], 0);
                        }
                    }
                }
            }
            foreach (array_keys($nodes) as $id) {
                foreach ($load($id) as $e) {
                    if ($e['kind'] === 'partner') {
                        $other = $e['source_id'] === $id ? $e['target_id'] : $e['source_id'];
                        $add($other, $levels[$id]);
                    }
                }
            }
        }
        foreach (array_keys($nodes) as $id) {
            foreach ($load($id) as $e) {
                if (isset($nodes[$e['source_id']], $nodes[$e['target_id']])) {
                    if (count($edges) >= 1000 && !isset($edges[$e['id']])) {
                        $limited = true;
                        continue;
                    }
                    $edges[$e['id']] = $this->edgeDto($e);
                }
            }
        }
        foreach ($nodes as $id => &$p) {
            $p['level'] = $levels[$id];
        }
        unset($p);
        return [
            'people' => array_values($nodes),
            'relations' => array_values($edges),
            'limited' => $limited,
            'focusId' => $focus,
            'contexts' => $groups,
        ];
    }
}
