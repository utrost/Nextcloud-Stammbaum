<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
final class Evidence
{
    public function __construct(private Store $store, private Trees $trees, private People $people) {}
    private function visible(array $row, array $m): void
    {
        if ($row['visibility'] !== 'members' && !Trees::management($m)) {
            throw Problem::missing();
        }
    }
    private function sourceRow(string $tree, string $id, array $m): array
    {
        $r = $this->store->one('SELECT * FROM *PREFIX*stb_sources WHERE tree_id = ? AND id = ?', [
            $tree,
            $id,
        ]);
        if (!$r) {
            throw Problem::missing();
        }
        $this->visible($r, $m);
        return $r;
    }
    private function sourceDto(array $r): array
    {
        return json_decode($r['data_json'], true) + [
            'id' => $r['id'],
            'revision' => (int) $r['revision'],
            'visibility' => $r['visibility'],
        ];
    }
    public function source(string $tree, string $id, string $actor): array
    {
        return $this->sourceDto($this->sourceRow($tree, $id, $this->trees->access($tree, $actor)));
    }
    public function citation(string $tree, string $id, string $actor): array
    {
        return $this->citationDto($this->citationRow($tree, $id, $this->trees->access($tree, $actor)));
    }
    public function sources(string $tree, string $actor, string $query = '', int $page = 0): array
    {
        $m = $this->trees->access($tree, $actor);
        $page = max(0, min(10000, $page));
        $where = 'tree_id = ?';
        $params = [$tree];
        if (!Trees::management($m)) {
            $where .= ' AND visibility = ?';
            $params[] = 'members';
        }
        if ($query !== '') {
            $where .= " AND search_title LIKE ? ESCAPE '!'";
            $params[] =
                '%' .
                str_replace(
                    ['!', '%', '_'],
                    ['!!', '!%', '!_'],
                    mb_strtolower(Values::text($query, 'query', 200)),
                ) .
                '%';
        }
        $rows = $this->store->rows(
            'SELECT * FROM *PREFIX*stb_sources WHERE ' .
                $where .
                ' ORDER BY title, id LIMIT 51 OFFSET ' .
                $page * 50,
            $params,
        );
        return [
            'items' => array_map(fn($r) => $this->sourceDto($r), array_slice($rows, 0, 50)),
            'nextPage' => count($rows) > 50 && $page < 10000 ? $page + 1 : null,
        ];
    }
    private function url(mixed $input): string
    {
        $url = Values::text($input ?? '', 'url', 1500);
        if (
            $url !== '' &&
            (!filter_var($url, FILTER_VALIDATE_URL) ||
                !in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true))
        ) {
            throw new Problem('invalid_url', 'Use an HTTP or HTTPS address.');
        }
        return $url;
    }
    public function saveSource(string $tree, ?string $id, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $id, $actor, $b) {
            $m = $this->trees->access($tree, $actor, 'editor');
            $old = null;
            $created = false;
            if ($id) {
                $old = $this->sourceRow($tree, $id, $m);
                $this->store->version($old, $b['revision'] ?? null);
                foreach (
                    $this->store->rows('SELECT id FROM *PREFIX*stb_citations WHERE source_id = ?', [$id])
                    as $c
                ) {
                    try {
                        $this->citationRow($tree, $c['id'], $m);
                    } catch (Problem) {
                        throw new Problem(
                            'shared_private',
                            'This source affects evidence you cannot access.',
                            403,
                        );
                    }
                }
            }
            $visibility = Values::choice(
                $b['visibility'] ?? 'members',
                ['members', 'management'],
                'visibility',
            );
            if (!Trees::management($m) && $visibility !== ($old['visibility'] ?? 'members')) {
                throw new Problem('forbidden', 'Only management can change visibility.', 403);
            }
            $d = [
                'title' => Values::text($b['title'] ?? '', 'title', 250, true),
                'type' => Values::text($b['type'] ?? '', 'type', 100),
                'author' => Values::text($b['author'] ?? '', 'author', 250),
                'publication' => Values::text($b['publication'] ?? '', 'publication', 1000),
                'repository' => Values::text($b['repository'] ?? '', 'repository', 250),
                'callNumber' => Values::text($b['callNumber'] ?? '', 'callNumber', 250),
                'url' => $this->url($b['url'] ?? ''),
                'description' => Values::text($b['description'] ?? '', 'description', 4000),
            ];
            if ($id) {
                $this->store->execute(
                    'UPDATE *PREFIX*stb_sources SET title = ?, search_title = ?, data_json = ?, visibility = ?, revision = revision + 1, updated_at = ? WHERE id = ?',
                    [$d['title'], mb_strtolower($d['title']), Store::json($d), $visibility, time(), $id],
                );
            } else {
                $id = $this->store->receipt(
                    $actor,
                    'source:' . $tree,
                    (string) ($b['requestId'] ?? ''),
                    $b,
                    function () use ($tree, $d, $visibility, &$created) {
                        $created = true;
                        $id = Store::id();
                        $this->store->insert('stb_sources', [
                            'id' => $id,
                            'tree_id' => $tree,
                            'title' => $d['title'],
                            'search_title' => mb_strtolower($d['title']),
                            'data_json' => Store::json($d),
                            'visibility' => $visibility,
                            'revision' => 1,
                            'created_at' => time(),
                            'updated_at' => time(),
                        ]);
                        return $id;
                    },
                );
            }
            if ($old || $created) {
                $this->store->bump($tree);
                $this->store->audit(
                    $tree,
                    $actor,
                    $old ? 'source_updated' : 'source_created',
                    null,
                    ['sourceId' => $id],
                    'management',
                );
            }
            return $this->sourceDto($this->sourceRow($tree, $id, $m));
        });
    }
    private function targetPerson(string $tree, string $id, array $m): array
    {
        $p = $this->store->one('SELECT * FROM *PREFIX*stb_people WHERE tree_id = ? AND id = ?', [$tree, $id]);
        if (!$p || ((int) $p['deleted'] === 1 && !Trees::management($m))) {
            throw Problem::missing();
        }
        $this->visible($p, $m);
        return $this->people->dto($p);
    }
    private function target(string $tree, array $t, array $m): array
    {
        $kind = Values::choice(
            $t['kind'] ?? '',
            ['person', 'claim', 'relation', 'record', 'context'],
            'kind',
        );
        $id = Values::text($t['targetId'] ?? ($t['target_id'] ?? ''), 'targetId', 36, true);
        $effect = Values::choice(
            $t['effect'] ?? 'reference',
            ['reference', 'supports', 'contradicts'],
            'effect',
        );
        $persons = [];
        if ($kind === 'person') {
            $p = $this->targetPerson($tree, $id, $m);
            $persons = [$id];
            $label = trim($p['givenName'] . ' ' . $p['familyName']);
            if ($effect !== 'reference') {
                throw new Problem('target_effect', 'Person-level citations are general references.');
            }
        } elseif ($kind === 'claim') {
            $c = $this->store->one('SELECT * FROM *PREFIX*stb_claims WHERE tree_id = ? AND id = ?', [
                $tree,
                $id,
            ]);
            if (!$c) {
                throw Problem::missing();
            }
            $this->visible($c, $m);
            $p = $this->targetPerson($tree, $c['person_id'], $m);
            $persons = [$p['id']];
            $label = trim($p['givenName'] . ' ' . $p['familyName']) . ' · ' . $c['kind'];
        } elseif ($kind === 'context') {
            $r = $this->store->one('SELECT * FROM *PREFIX*stb_contexts WHERE tree_id = ? AND id = ?', [
                $tree,
                $id,
            ]);
            if (!$r) {
                throw Problem::missing();
            }
            $this->visible($r, $m);
            foreach (
                $this->store->rows('SELECT person_id FROM *PREFIX*stb_context_people WHERE context_id = ?', [
                    $id,
                ])
                as $p
            ) {
                $this->targetPerson($tree, $p['person_id'], $m);
                $persons[] = $p['person_id'];
            }
            $label = $r['label'];
        } elseif ($kind === 'record') {
            $r = $this->store->one('SELECT * FROM *PREFIX*stb_records WHERE tree_id = ? AND id = ?', [
                $tree,
                $id,
            ]);
            if (!$r) {
                throw Problem::missing();
            }
            $this->visible($r, $m);
            foreach (
                $this->store->rows('SELECT person_id FROM *PREFIX*stb_participants WHERE record_id = ?', [
                    $id,
                ])
                as $part
            ) {
                $this->targetPerson($tree, $part['person_id'], $m);
                $persons[] = $part['person_id'];
            }
            $label = json_decode($r['data_json'], true)['title'];
        } else {
            $r = $this->store->one('SELECT * FROM *PREFIX*stb_relations WHERE tree_id = ? AND id = ?', [
                $tree,
                $id,
            ]);
            if (!$r) {
                throw Problem::missing();
            }
            foreach ([$r['source_id'], $r['target_id']] as $pid) {
                $this->targetPerson($tree, $pid, $m);
                $persons[] = $pid;
            }
            $label = $r['kind'] . ' · ' . $r['parent_type'];
        }
        return [
            'kind' => $kind,
            'targetId' => $id,
            'effect' => $effect,
            'label' => $label,
            'personIds' => $persons,
        ];
    }
    private function citationRow(string $tree, string $id, array $m): array
    {
        $r = $this->store->one('SELECT * FROM *PREFIX*stb_citations WHERE tree_id = ? AND id = ?', [
            $tree,
            $id,
        ]);
        if (!$r) {
            throw Problem::missing();
        }
        $this->visible($r, $m);
        $r['source'] = $this->sourceDto($this->sourceRow($tree, $r['source_id'], $m));
        $r['targets'] = array_map(
            fn($t) => $this->target($tree, $t, $m),
            $this->store->rows('SELECT * FROM *PREFIX*stb_targets WHERE citation_id = ? ORDER BY id', [$id]),
        );
        return $r;
    }
    private function citationDto(array $r): array
    {
        return json_decode($r['data_json'], true) + [
            'id' => $r['id'],
            'sourceId' => $r['source_id'],
            'source' => $r['source'],
            'targets' => $r['targets'],
            'visibility' => $r['visibility'],
            'revision' => (int) $r['revision'],
        ];
    }
    public function citations(
        string $tree,
        string $actor,
        string $person = '',
        string $source = '',
        int $page = 0,
    ): array {
        $m = $this->trees->access($tree, $actor);
        if ($person !== '') {
            $this->people->row($tree, $person, $m);
        }
        if ($source !== '') {
            $this->sourceRow($tree, $source, $m);
        }
        $where = 'c.tree_id = ?';
        $params = [$tree];
        if ($source !== '') {
            $where .= ' AND c.source_id = ?';
            $params[] = $source;
        }
        // Authorization precedes pagination: hidden targets must not create empty pages/count signals.
        if (!Trees::management($m)) {
            $where .=
                " AND c.visibility = 'members' AND EXISTS (SELECT 1 FROM *PREFIX*stb_sources s WHERE s.id = c.source_id AND s.visibility = 'members')";
            $where .= " AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_targets t WHERE t.citation_id = c.id AND (
                (t.kind = 'context' AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_contexts v WHERE v.id = t.target_id AND v.tree_id = c.tree_id AND v.visibility = 'members' AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_context_people a LEFT JOIN *PREFIX*stb_people p ON p.id = a.person_id WHERE a.context_id = v.id AND (p.id IS NULL OR p.deleted = 1 OR p.visibility <> 'members')))) OR
                (t.kind = 'record' AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_records v WHERE v.id = t.target_id AND v.tree_id = c.tree_id AND v.visibility = 'members' AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_participants a LEFT JOIN *PREFIX*stb_people p ON p.id = a.person_id WHERE a.record_id = v.id AND (p.id IS NULL OR p.deleted = 1 OR p.visibility <> 'members')))) OR
                (t.kind = 'person' AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_people p WHERE p.id = t.target_id AND p.tree_id = c.tree_id AND p.deleted = 0 AND p.visibility = 'members')) OR
                (t.kind = 'claim' AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_claims a INNER JOIN *PREFIX*stb_people p ON p.id = a.person_id WHERE a.id = t.target_id AND a.tree_id = c.tree_id AND a.visibility = 'members' AND p.deleted = 0 AND p.visibility = 'members')) OR
                (t.kind = 'relation' AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_relations r INNER JOIN *PREFIX*stb_people p ON p.id = r.source_id INNER JOIN *PREFIX*stb_people q ON q.id = r.target_id WHERE r.id = t.target_id AND r.tree_id = c.tree_id AND p.deleted = 0 AND q.deleted = 0 AND p.visibility = 'members' AND q.visibility = 'members'))))";
        }
        if ($person !== '') {
            $where .=
                " AND EXISTS (SELECT 1 FROM *PREFIX*stb_targets t LEFT JOIN *PREFIX*stb_claims a ON a.id = t.target_id AND t.kind = 'claim' LEFT JOIN *PREFIX*stb_relations r ON r.id = t.target_id AND t.kind = 'relation' WHERE t.citation_id = c.id AND ((t.kind = 'person' AND t.target_id = ?) OR a.person_id = ? OR r.source_id = ? OR r.target_id = ? OR (t.kind = 'record' AND EXISTS (SELECT 1 FROM *PREFIX*stb_participants rp WHERE rp.record_id = t.target_id AND rp.person_id = ?)) OR (t.kind = 'context' AND EXISTS (SELECT 1 FROM *PREFIX*stb_context_people cp WHERE cp.context_id = t.target_id AND cp.person_id = ?))))";
            array_push($params, $person, $person, $person, $person, $person, $person);
        }
        $candidates = $this->store->rows(
            'SELECT c.id FROM *PREFIX*stb_citations c WHERE ' .
                $where .
                ' ORDER BY c.created_at DESC, c.id DESC LIMIT 51 OFFSET ' .
                max(0, min(10000, $page)) * 50,
            $params,
        );
        $items = [];
        foreach (array_slice($candidates, 0, 50) as $candidate) {
            try {
                $r = $this->citationRow($tree, $candidate['id'], $m);
            } catch (Problem) {
                continue;
            }
            if (
                $person !== '' &&
                !array_filter($r['targets'], fn($t) => in_array($person, $t['personIds'], true))
            ) {
                continue;
            }
            $items[] = $this->citationDto($r);
        }
        return ['items' => $items, 'nextPage' => count($candidates) > 50 ? $page + 1 : null];
    }
    public function saveCitation(string $tree, ?string $id, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $id, $actor, $b) {
            $m = $this->trees->access($tree, $actor, 'editor');
            $old = null;
            $created = false;
            if ($id) {
                $old = $this->citationRow($tree, $id, $m);
                $this->store->version($old, $b['revision'] ?? null);
            }
            $source = Values::text($b['sourceId'] ?? '', 'sourceId', 36, true);
            $this->sourceRow($tree, $source, $m);
            $visibility = Values::choice(
                $b['visibility'] ?? 'members',
                ['members', 'management'],
                'visibility',
            );
            if (!Trees::management($m) && $visibility !== ($old['visibility'] ?? 'members')) {
                throw new Problem('forbidden', 'Only management can change visibility.', 403);
            }
            $targets = $b['targets'] ?? [];
            if (!is_array($targets) || !array_is_list($targets) || count($targets) > 32) {
                throw new Problem('targets', 'Choose at most 32 evidence targets.');
            }
            $clean = [];
            $seen = [];
            foreach ($targets as $target) {
                if (!is_array($target)) {
                    throw new Problem('targets', 'Choose valid evidence targets.');
                }
                $t = $this->target($tree, $target, $m);
                $identity = $t['kind'] . ':' . $t['targetId'];
                if (isset($seen[$identity])) {
                    throw new Problem('duplicate_target', 'Each evidence target may occur once.');
                }
                $seen[$identity] = true;
                $clean[] = $t;
            }
            $d = [
                'locator' => Values::text($b['locator'] ?? '', 'locator', 500),
                'quotation' => Values::text($b['quotation'] ?? '', 'quotation', 8000),
                'url' => $this->url($b['url'] ?? ''),
                'documentDate' => Values::date($b['documentDate'] ?? ['kind' => 'exact', 'value' => '']),
                'accessDate' => Values::date($b['accessDate'] ?? ['kind' => 'exact', 'value' => '']),
                'reliability' => Values::choice(
                    $b['reliability'] ?? 'unassessed',
                    ['unassessed', 'low', 'medium', 'high'],
                    'reliability',
                ),
                'reasoning' => Values::text($b['reasoning'] ?? '', 'reasoning', 4000),
            ];
            if ($id) {
                $this->store->execute(
                    'UPDATE *PREFIX*stb_citations SET source_id = ?, data_json = ?, visibility = ?, revision = revision + 1, updated_at = ? WHERE id = ?',
                    [$source, Store::json($d), $visibility, time(), $id],
                );
                $this->store->execute('DELETE FROM *PREFIX*stb_targets WHERE citation_id = ?', [$id]);
                $this->writeTargets($id, $clean);
            } else {
                $id = $this->store->receipt(
                    $actor,
                    'citation:' . $tree,
                    (string) ($b['requestId'] ?? ''),
                    $b,
                    function () use ($tree, $source, $d, $visibility, $clean, &$created) {
                        $created = true;
                        $id = Store::id();
                        $this->store->insert('stb_citations', [
                            'id' => $id,
                            'tree_id' => $tree,
                            'source_id' => $source,
                            'data_json' => Store::json($d),
                            'visibility' => $visibility,
                            'revision' => 1,
                            'created_at' => time(),
                            'updated_at' => time(),
                        ]);
                        $this->writeTargets($id, $clean);
                        return $id;
                    },
                );
            }
            if ($old || $created) {
                $this->store->bump($tree);
                $this->store->audit(
                    $tree,
                    $actor,
                    $old ? 'citation_updated' : 'citation_created',
                    null,
                    ['citationId' => $id],
                    'management',
                );
            }
            return $this->citationDto($this->citationRow($tree, $id, $m));
        });
    }
    public function assertEditableTarget(string $tree, string $kind, string $id, string $actor): void
    {
        foreach (
            $this->store->rows(
                'SELECT citation_id FROM *PREFIX*stb_targets WHERE kind = ? AND target_id = ?',
                [$kind, $id],
            )
            as $r
        ) {
            try {
                $this->citation($tree, $r['citation_id'], $actor);
            } catch (Problem) {
                throw new Problem('shared_private', 'This change affects evidence you cannot access.', 403);
            }
        }
    }
    private function writeTargets(string $id, array $targets): void
    {
        foreach ($targets as $t) {
            $this->store->insert('stb_targets', [
                'id' => Store::id(),
                'citation_id' => $id,
                'kind' => $t['kind'],
                'target_id' => $t['targetId'],
                'effect' => $t['effect'],
            ]);
        }
    }
}
