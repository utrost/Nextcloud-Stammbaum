<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
final class People
{
    public function __construct(private Store $store, private Trees $trees) {}
    public function row(string $tree, string $id, array $membership, bool $deleted = false): array
    {
        $r = $this->store->one(
            'SELECT * FROM *PREFIX*stb_people WHERE tree_id = ? AND id = ? AND deleted = ?',
            [$tree, $id, $deleted ? 1 : 0],
        );
        if (!$r || ($r['visibility'] !== 'members' && !Trees::management($membership))) {
            throw Problem::missing();
        }
        return $r;
    }
    public function dto(array $r): array
    {
        return array_merge(json_decode($r['data_json'], true, 32, JSON_THROW_ON_ERROR), [
            'id' => $r['id'],
            'treeId' => $r['tree_id'],
            'revision' => (int) $r['revision'],
            'createdAt' => (int) $r['created_at'],
            'updatedAt' => (int) $r['updated_at'],
            'deleted' => (bool) $r['deleted'],
        ]);
    }
    public function get(string $tree, string $id, string $actor): array
    {
        $m = $this->trees->access($tree, $actor);
        return $this->dto($this->row($tree, $id, $m));
    }
    public function list(
        string $tree,
        string $actor,
        string $query = '',
        int $page = 0,
        bool $deleted = false,
    ): array {
        $m = $this->trees->access($tree, $actor, $deleted ? 'manager' : 'viewer');
        $query = Values::text($query, 'query', 200);
        $page = max(0, min(10000, $page));
        $where = 'tree_id = ? AND deleted = ?';
        $params = [$tree, $deleted ? 1 : 0];
        if (!Trees::management($m)) {
            $where .= ' AND visibility = ?';
            $params[] = 'members';
        }
        if ($query !== '') {
            $where .= " AND search_text LIKE ? ESCAPE '!'";
            $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)) . '%';
        }
        if ($deleted) {
            $rows = $this->store->rows(
                'SELECT * FROM *PREFIX*stb_people WHERE ' .
                    $where .
                    ' ORDER BY updated_at DESC, id DESC LIMIT 100',
                $params,
            );
            return ['items' => array_map(fn($r) => $this->dto($r), $rows), 'nextPage' => null];
        }
        $rows = $this->store->rows(
            'SELECT * FROM *PREFIX*stb_people WHERE ' .
                $where .
                ' ORDER BY family_name, given_name, id LIMIT 51 OFFSET ' .
                $page * 50,
            $params,
        );
        return [
            'items' => array_map(fn($r) => $this->dto($r), array_slice($rows, 0, 50)),
            'nextPage' => count($rows) > 50 ? $page + 1 : null,
        ];
    }
    private function parts(array $d): array
    {
        return [
            'name' => ['givenName' => $d['givenName'], 'familyName' => $d['familyName']],
            'birth_date' => $d['birthDate'],
            'death_date' => $d['deathDate'],
            'birth_place' => $d['birthPlace'],
            'death_place' => $d['deathPlace'],
        ];
    }
    private function hasValue(string $kind, mixed $value): bool
    {
        return match ($kind) {
            'name' => $value['givenName'] !== '' || $value['familyName'] !== '',
            'birth_date', 'death_date' => $value['value'] !== '' || $value['end'] !== '',
            default => $value !== '',
        };
    }
    private function assertions(string $tree, string $id, string $actor, array $d, ?array $old): void
    {
        $prior = $old ? $this->parts($old) : [];
        $release = $old && $old['visibility'] === 'management' && $d['visibility'] === 'members';
        foreach ($this->parts($d) as $kind => $value) {
            if (!$release && isset($prior[$kind]) && Store::json($prior[$kind]) === Store::json($value)) {
                continue;
            }
            $this->store->execute(
                'UPDATE *PREFIX*stb_claims SET preferred = 0 WHERE person_id = ? AND kind = ?',
                [$id, $kind],
            );
            if (!$this->hasValue($kind, $value)) {
                continue;
            }
            $this->store->insert('stb_claims', [
                'id' => Store::id(),
                'tree_id' => $tree,
                'person_id' => $id,
                'kind' => $kind,
                'value_json' => Store::json($value),
                'assessment' => 'unassessed',
                'visibility' => $d['visibility'],
                'preferred' => 1,
                'created_at' => time(),
                'actor_id' => $actor,
            ]);
        }
    }
    public function createInside(string $tree, string $actor, array $b, array $m): string
    {
        $d = Values::person($b);
        if ($d['visibility'] !== 'members' && !Trees::management($m)) {
            throw new Problem('forbidden', 'Only management can change visibility.', 403);
        }
        $id = Store::id();
        $now = time();
        $this->store->insert('stb_people', [
            'id' => $id,
            'tree_id' => $tree,
            'given_name' => $d['givenName'],
            'family_name' => $d['familyName'],
            'search_text' => mb_strtolower(
                $d['givenName'] . ' ' . $d['familyName'] . ' ' . $d['displayNote'],
            ),
            'data_json' => Store::json($d),
            'visibility' => $d['visibility'],
            'revision' => 1,
            'deleted' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertions($tree, $id, $actor, $d, null);
        $this->store->audit($tree, $actor, 'person_created', $id, $d, $d['visibility']);
        $this->store->bump($tree);
        return $id;
    }
    public function create(string $tree, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $actor, $b) {
            $m = $this->trees->access($tree, $actor, 'editor');
            $id = $this->store->receipt(
                $actor,
                'person:' . $tree,
                (string) ($b['requestId'] ?? ''),
                $b,
                fn() => $this->createInside($tree, $actor, $b, $m),
            );
            return $this->get($tree, $id, $actor);
        });
    }
    public function saveInside(
        string $tree,
        string $id,
        string $actor,
        array $d,
        array $old,
        bool $claims = true,
    ): void {
        if ($claims) {
            $this->assertions($tree, $id, $actor, $d, $old);
        }
        $search = $d['givenName'] . ' ' . $d['familyName'] . ' ' . $d['displayNote'];
        // Search includes permitted alternative names only; restricted assertions never enter the member projection.
        foreach (
            $this->store->rows(
                'SELECT value_json, visibility FROM *PREFIX*stb_claims WHERE person_id = ? AND kind = ?',
                [$id, 'name'],
            )
            as $c
        ) {
            if ($c['visibility'] === 'members' || $d['visibility'] === 'management') {
                $name = json_decode($c['value_json'], true);
                $search .= ' ' . $name['givenName'] . ' ' . $name['familyName'];
            }
        }
        $this->store->execute(
            'UPDATE *PREFIX*stb_people SET given_name = ?, family_name = ?, search_text = ?, data_json = ?, visibility = ?, revision = revision + 1, updated_at = ? WHERE id = ?',
            [
                $d['givenName'],
                $d['familyName'],
                mb_strtolower($search),
                Store::json($d),
                $d['visibility'],
                time(),
                $id,
            ],
        );
        $this->store->audit($tree, $actor, 'person_updated', $id, $d, $d['visibility']);
        $this->store->bump($tree);
    }
    public function update(string $tree, string $id, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $id, $actor, $b) {
            $m = $this->trees->access($tree, $actor, 'editor');
            $r = $this->row($tree, $id, $m);
            $this->store->version($r, $b['revision'] ?? null);
            $old = $this->dto($r);
            $d = Values::person($b);
            if ($d['visibility'] !== $old['visibility'] && !Trees::management($m)) {
                throw new Problem('forbidden', 'Only management can change visibility.', 403);
            }
            if (
                $old['visibility'] === 'management' &&
                $d['visibility'] === 'members' &&
                ($b['confirmRelease'] ?? false) !== true
            ) {
                throw new Problem(
                    'release_confirmation',
                    'Confirm release of these current values to tree members. Historical private values remain restricted.',
                );
            }
            $this->saveInside($tree, $id, $actor, $d, $old);
            return $this->get($tree, $id, $actor);
        });
    }
    public function claims(string $tree, string $id, string $actor): array
    {
        $m = $this->trees->access($tree, $actor);
        $this->row($tree, $id, $m);
        $where = 'person_id = ?';
        $params = [$id];
        if (!Trees::management($m)) {
            $where .= ' AND visibility = ?';
            $params[] = 'members';
        }
        return array_map(
            fn($r) => [
                'id' => $r['id'],
                'kind' => $r['kind'],
                'value' => json_decode($r['value_json'], true),
                'assessment' => $r['assessment'],
                'visibility' => $r['visibility'],
                'preferred' => (bool) $r['preferred'],
                'actorId' => $r['actor_id'],
                'createdAt' => (int) $r['created_at'],
            ],
            $this->store->rows(
                'SELECT * FROM *PREFIX*stb_claims WHERE ' .
                    $where .
                    ' ORDER BY created_at DESC, id DESC LIMIT 200',
                $params,
            ),
        );
    }
    public function prefer(string $tree, string $id, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $id, $actor, $b) {
            $m = $this->trees->access($tree, $actor, 'editor');
            $r = $this->row($tree, $id, $m);
            $this->store->version($r, $b['revision'] ?? null);
            $c = $this->store->one('SELECT * FROM *PREFIX*stb_claims WHERE id = ? AND person_id = ?', [
                $b['claimId'] ?? '',
                $id,
            ]);
            if (!$c || ($c['visibility'] !== 'members' && !Trees::management($m))) {
                throw Problem::missing();
            }
            if ($c['visibility'] === 'management' && $r['visibility'] === 'members') {
                throw new Problem(
                    'private_assertion',
                    'This private assertion cannot be released by choosing it. Edit the person with explicitly released values instead.',
                );
            }
            $d = json_decode($r['data_json'], true);
            $value = json_decode($c['value_json'], true);
            if ($c['kind'] === 'name') {
                $d['givenName'] = $value['givenName'];
                $d['familyName'] = $value['familyName'];
            } else {
                $field = [
                    'birth_date' => 'birthDate',
                    'death_date' => 'deathDate',
                    'birth_place' => 'birthPlace',
                    'death_place' => 'deathPlace',
                ][$c['kind']];
                $d[$field] = $value;
            }
            $this->store->execute(
                'UPDATE *PREFIX*stb_claims SET preferred = 0 WHERE person_id = ? AND kind = ?',
                [$id, $c['kind']],
            );
            $this->store->execute('UPDATE *PREFIX*stb_claims SET preferred = 1 WHERE id = ?', [$c['id']]);
            $this->saveInside($tree, $id, $actor, $d, $this->dto($r), false);
            return $this->get($tree, $id, $actor);
        });
    }
    public function assess(string $tree, string $id, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $id, $actor, $b) {
            $m = $this->trees->access($tree, $actor, 'editor');
            $r = $this->row($tree, $id, $m);
            $this->store->version($r, $b['revision'] ?? null);
            $c = $this->store->one('SELECT * FROM *PREFIX*stb_claims WHERE id = ? AND person_id = ?', [
                $b['claimId'] ?? '',
                $id,
            ]);
            if (!$c || ($c['visibility'] !== 'members' && !Trees::management($m))) {
                throw Problem::missing();
            }
            $assessment = Values::choice(
                $b['assessment'] ?? '',
                ['unassessed', 'supported', 'disputed', 'rejected'],
                'assessment',
            );
            $this->store->execute('UPDATE *PREFIX*stb_claims SET assessment = ? WHERE id = ?', [
                $assessment,
                $c['id'],
            ]);
            $this->store->execute('UPDATE *PREFIX*stb_people SET revision = revision + 1 WHERE id = ?', [
                $id,
            ]);
            $this->store->bump($tree);
            $this->store->audit(
                $tree,
                $actor,
                'assertion_assessed',
                $id,
                ['claimId' => $c['id'], 'assessment' => $assessment],
                $c['visibility'],
            );
            return $this->get($tree, $id, $actor);
        });
    }
    public function history(string $tree, string $id, string $actor, int $page = 0): array
    {
        $m = $this->trees->access($tree, $actor);
        $this->row($tree, $id, $m);
        $where = 'tree_id = ? AND subject_id = ?';
        $params = [$tree, $id];
        if (!Trees::management($m)) {
            $where .= ' AND visibility = ?';
            $params[] = 'members';
        }
        $rows = $this->store->rows(
            'SELECT * FROM *PREFIX*stb_history WHERE ' .
                $where .
                ' ORDER BY created_at DESC, id DESC LIMIT 26 OFFSET ' .
                max(0, min($page, 10000)) * 25,
            $params,
        );
        return [
            'items' => array_map(
                fn($r) => [
                    'id' => $r['id'],
                    'actorId' => $r['actor_id'],
                    'action' => $r['action'],
                    'data' => json_decode($r['data_json'], true),
                    'createdAt' => (int) $r['created_at'],
                ],
                array_slice($rows, 0, 25),
            ),
            'nextPage' => count($rows) > 25 ? $page + 1 : null,
        ];
    }
    public function impact(string $tree, string $id, string $actor): array
    {
        return $this->store->write(function () use ($tree, $id, $actor) {
            $m = $this->trees->access($tree, $actor, 'manager');
            $person = $this->row($tree, $id, $m);
            $counts = [];
            foreach (
                [
                    'contexts' => 'stb_context_people',
                    'records' => 'stb_participants',
                    'media' => 'stb_media_people',
                ]
                as $key => $table
            ) {
                $counts[$key] = (int) $this->store->one(
                    'SELECT COUNT(*) AS n FROM *PREFIX*' . $table . ' WHERE person_id = ?',
                    [$id],
                )['n'];
            }
            $counts['connections'] = (int) $this->store->one(
                'SELECT COUNT(*) AS n FROM *PREFIX*stb_relations WHERE tree_id = ? AND active = 1 AND (source_id = ? OR target_id = ?)',
                [$tree, $id, $id],
            )['n'];
            return $counts + [
                'revision' => (int) $person['revision'],
                'treeRevision' => $this->trees->get($tree, $actor)['revision'],
            ];
        });
    }
    public function remove(string $tree, string $id, string $actor, array $b, bool $restore = false): array
    {
        return $this->store->write(function () use ($tree, $id, $actor, $b, $restore) {
            $m = $this->trees->access($tree, $actor, 'manager');
            $r = $this->row($tree, $id, $m, $restore);
            $this->store->version($r, $b['revision'] ?? null);
            if (isset($b['treeRevision'])) {
                $this->store->version($this->trees->get($tree, $actor), $b['treeRevision']);
            }
            if (($b['confirmed'] ?? false) !== true) {
                throw new Problem('confirmation', 'Confirm the consequences before continuing.');
            }
            $this->store->execute(
                'UPDATE *PREFIX*stb_people SET deleted = ?, revision = revision + 1, updated_at = ? WHERE id = ?',
                [$restore ? 0 : 1, time(), $id],
            );
            if (!$restore) {
                $this->store->execute(
                    'UPDATE *PREFIX*stb_relations SET active = 0, revision = revision + 1 WHERE tree_id = ? AND (source_id = ? OR target_id = ?)',
                    [$tree, $id, $id],
                );
                $this->store->execute(
                    'UPDATE *PREFIX*stb_trees SET home_id = NULL WHERE id = ? AND home_id = ?',
                    [$tree, $id],
                );
            }
            $this->store->bump($tree);
            $this->store->audit(
                $tree,
                $actor,
                $restore ? 'person_restored' : 'person_deleted',
                $id,
                [],
                $r['visibility'],
            );
            return ['ok' => true];
        });
    }
}
