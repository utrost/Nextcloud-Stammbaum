<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
final class Records
{
    public const TYPES = [
        'event' => [
            'marriage',
            'civil_marriage',
            'divorce',
            'separation',
            'adoption',
            'military_service',
            'occupation',
            'education',
            'residence',
            'migration',
            'baptism',
            'burial',
            'census',
            'probate',
            'will',
            'custom',
        ],
        'note' => ['note'],
        'contact' => ['email', 'phone', 'address', 'website', 'custom'],
        'attribute' => ['name_components', 'qualification', 'organization', 'religion', 'custom'],
    ];
    public function __construct(
        private Store $store,
        private Trees $trees,
        private People $people,
        private Evidence $evidence,
    ) {}
    public function row(string $tree, string $id, array $m): array
    {
        $r = $this->store->one('SELECT * FROM *PREFIX*stb_records WHERE tree_id = ? AND id = ?', [
            $tree,
            $id,
        ]);
        if (!$r || ($r['visibility'] !== 'members' && !Trees::management($m))) {
            throw Problem::missing();
        }
        $r['participants'] = array_map(function ($p) use ($tree, $m) {
            $person = $this->people->dto($this->people->row($tree, $p['person_id'], $m));
            return ['personId' => $p['person_id'], 'role' => $p['role'], 'person' => $person];
        }, $this->store->rows(
            'SELECT * FROM *PREFIX*stb_participants WHERE record_id = ? ORDER BY position, id',
            [$id],
        ));
        return $r;
    }
    public function dto(array $r, array $m): array
    {
        $original = json_decode($r['original_json'], true);
        if (!Trees::management($m) && ($original['visibility'] ?? 'members') !== 'members') {
            $original = null;
        }
        if ($original) {
            foreach ($original['participants'] ?? [] as $p) {
                try {
                    $this->people->row($r['tree_id'], $p['personId'], $m);
                } catch (Problem) {
                    $original = null;
                    break;
                }
            }
        }
        return json_decode($r['data_json'], true) + [
            'id' => $r['id'],
            'originId' => $r['origin_id'],
            'category' => $r['category'],
            'type' => $r['record_type'],
            'visibility' => $r['visibility'],
            'revision' => (int) $r['revision'],
            'preferred' => (bool) $r['preferred'],
            'participants' => $r['participants'],
            'createdAt' => (int) $r['created_at'],
            'updatedAt' => (int) $r['updated_at'],
            'actorId' => $r['actor_id'],
            'original' => $original,
        ];
    }
    public function get(string $tree, string $id, string $actor): array
    {
        $m = $this->trees->access($tree, $actor);
        return $this->dto($this->row($tree, $id, $m), $m);
    }
    public function list(
        string $tree,
        string $actor,
        string $person,
        string $category = '',
        int $page = 0,
    ): array {
        $m = $this->trees->access($tree, $actor);
        $this->people->row($tree, $person, $m);
        $where =
            'r.tree_id = ? AND EXISTS (SELECT 1 FROM *PREFIX*stb_participants a WHERE a.record_id = r.id AND a.person_id = ?)';
        $params = [$tree, $person];
        if ($category !== '') {
            $where .= ' AND r.category = ?';
            $params[] = Values::choice($category, array_keys(self::TYPES), 'category');
        }
        if (!Trees::management($m)) {
            $where .= " AND r.visibility = 'members'";
        }
        $where .=
            ' AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_participants a LEFT JOIN *PREFIX*stb_people p ON p.id = a.person_id WHERE a.record_id = r.id AND (p.id IS NULL OR p.deleted = 1' .
            (!Trees::management($m) ? " OR p.visibility <> 'members'" : '') .
            '))';
        $rows = $this->store->rows(
            'SELECT r.id FROM *PREFIX*stb_records r WHERE ' .
                $where .
                ' ORDER BY r.created_at DESC, r.id DESC LIMIT 26 OFFSET ' .
                max(0, min(10000, $page)) * 25,
            $params,
        );
        return [
            'items' => array_map(fn($r) => $this->get($tree, $r['id'], $actor), array_slice($rows, 0, 25)),
            'nextPage' => count($rows) > 25 ? $page + 1 : null,
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
                $this->evidence->assertEditableTarget($tree, 'record', $id, $actor);
            }
            $category = Values::choice($b['category'] ?? '', array_keys(self::TYPES), 'category');
            $type = Values::choice($b['type'] ?? '', self::TYPES[$category], 'type');
            $origin = Values::text($b['originId'] ?? '', 'originId', 36, true);
            $this->people->row($tree, $origin, $m);
            if ($old && ($old['category'] !== $category || $old['origin_id'] !== $origin)) {
                throw new Problem(
                    'immutable_origin',
                    'The original person and category cannot be reassigned.',
                );
            }
            $visibility = Values::choice(
                $b['visibility'] ?? 'members',
                ['members', 'management'],
                'visibility',
            );
            $confidential = $old && (json_decode($old['data_json'], true)['confidential'] ?? false) === true;
            if ($category === 'contact' || $type === 'religion' || $confidential) {
                if (!Trees::management($m)) {
                    throw new Problem(
                        'forbidden',
                        'Contacts and religious affiliation are management-only.',
                        403,
                    );
                }
                $visibility = 'management';
            }
            if (!Trees::management($m) && $visibility !== ($old['visibility'] ?? 'members')) {
                throw new Problem('forbidden', 'Only management can change visibility.', 403);
            }
            if (
                $old &&
                $old['visibility'] === 'management' &&
                $visibility === 'members' &&
                ($b['confirmRelease'] ?? false) !== true
            ) {
                throw new Problem('release_confirmation', 'Confirm release of this record to tree members.');
            }
            $participants = $b['participants'] ?? [['personId' => $origin, 'role' => 'subject']];
            if (
                !is_array($participants) ||
                !array_is_list($participants) ||
                count($participants) < 1 ||
                count($participants) > 32
            ) {
                throw new Problem('participants', 'Choose between one and 32 participants.');
            }
            $clean = [];
            $seen = [];
            foreach ($participants as $p) {
                if (!is_array($p)) {
                    throw new Problem('participants', 'Choose valid participants.');
                }
                $pid = Values::text($p['personId'] ?? '', 'personId', 36, true);
                if (isset($seen[$pid])) {
                    throw new Problem('duplicate_participant', 'Each person can participate only once.');
                }
                $seen[$pid] = true;
                $this->people->row($tree, $pid, $m);
                $clean[] = [
                    'personId' => $pid,
                    'role' => Values::text($p['role'] ?? 'subject', 'role', 100, true),
                ];
            }
            if (!isset($seen[$origin])) {
                throw new Problem('immutable_origin', 'The original person must remain a participant.');
            }
            if ($category !== 'event' && count($clean) !== 1) {
                throw new Problem('participants', 'Only events have shared participants.');
            }
            $expected = array_unique([
                ...array_keys($seen),
                ...array_column($old['participants'] ?? [], 'personId'),
            ]);
            sort($expected);
            $confirmed = $b['confirmedParticipants'] ?? [];
            if (!is_array($confirmed)) {
                throw new Problem('confirm_participants', 'Confirm every affected participant.');
            }
            sort($confirmed);
            if ($category === 'event' && $expected !== $confirmed) {
                throw new Problem('confirm_participants', 'Confirm every affected participant.');
            }
            $fields = $b['fields'] ?? [];
            if (!is_array($fields) || !array_is_list($fields) || count($fields) > 32) {
                throw new Problem('fields', 'Use at most 32 structured fields.');
            }
            $structured = [];
            foreach ($fields as $f) {
                if (!is_array($f)) {
                    throw new Problem('fields', 'Provide named structured fields.');
                }
                $structured[] = [
                    'label' => Values::text($f['label'] ?? '', 'label', 100, true),
                    'value' => Values::text($f['value'] ?? '', 'value', 2000),
                    'unit' => Values::text($f['unit'] ?? '', 'unit', 50),
                ];
            }
            $date = Values::date($b['date'] ?? ['kind' => 'exact', 'value' => '']);
            $ongoing = ($b['ongoing'] ?? false) === true;
            if ($ongoing && $date['end'] !== '') {
                throw new Problem('ongoing_end', 'An ongoing event cannot have an end date.');
            }
            $d = [
                'title' => Values::text($b['title'] ?? '', 'title', 250, true),
                'text' => Values::text($b['text'] ?? '', 'text', 20000),
                'date' => $date,
                'place' => Values::text($b['place'] ?? '', 'place', 500),
                'ongoing' => $ongoing,
                'fields' => $structured,
            ];
            if ($confidential) {
                $d['confidential'] = true;
            }
            if ($id) {
                $this->store->execute(
                    'UPDATE *PREFIX*stb_records SET record_type = ?, visibility = ?, data_json = ?, revision = revision + 1, updated_at = ? WHERE id = ?',
                    [$type, $visibility, Store::json($d), time(), $id],
                );
                $this->store->execute('DELETE FROM *PREFIX*stb_participants WHERE record_id = ?', [$id]);
                $this->participants($id, $clean);
            } else {
                $id = $this->store->receipt(
                    $actor,
                    'record:' . $tree,
                    (string) ($b['requestId'] ?? ''),
                    $b,
                    function () use (
                        $tree,
                        $origin,
                        $category,
                        $type,
                        $visibility,
                        $d,
                        $clean,
                        $actor,
                        &$created,
                    ) {
                        $created = true;
                        $id = Store::id();
                        $this->store->insert('stb_records', [
                            'id' => $id,
                            'tree_id' => $tree,
                            'origin_id' => $origin,
                            'category' => $category,
                            'record_type' => $type,
                            'visibility' => $visibility,
                            'data_json' => Store::json($d),
                            'original_json' => Store::json(
                                $d + ['participants' => $clean, 'visibility' => $visibility],
                            ),
                            'revision' => 1,
                            'preferred' => 0,
                            'actor_id' => $actor,
                            'created_at' => time(),
                            'updated_at' => time(),
                        ]);
                        $this->participants($id, $clean);
                        return $id;
                    },
                );
            }
            if ($old || $created) {
                $this->store->bump($tree);
                foreach ($clean as $p) {
                    $this->store->audit(
                        $tree,
                        $actor,
                        $old ? 'record_updated' : 'record_created',
                        $p['personId'],
                        [
                            'recordId' => $id,
                            'current' => $d,
                            'previous' => $old ? json_decode($old['data_json'], true) : null,
                        ],
                        'management',
                    );
                }
            }
            return $this->get($tree, $id, $actor);
        });
    }
    private function participants(string $id, array $people): void
    {
        foreach ($people as $i => $p) {
            $this->store->insert('stb_participants', [
                'id' => Store::id(),
                'record_id' => $id,
                'person_id' => $p['personId'],
                'role' => $p['role'],
                'position' => $i,
            ]);
        }
    }
    public function prefer(string $tree, string $id, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $id, $actor, $b) {
            $m = $this->trees->access($tree, $actor, 'editor');
            $r = $this->row($tree, $id, $m);
            $this->store->version($r, $b['revision'] ?? null);
            if ($r['category'] !== 'note') {
                throw new Problem('note_required', 'Choose a note as the biography.');
            }
            foreach (
                $this->store->rows(
                    'SELECT id FROM *PREFIX*stb_records WHERE origin_id = ? AND preferred = 1',
                    [$r['origin_id']],
                )
                as $prior
            ) {
                $this->row($tree, $prior['id'], $m);
            }
            $this->store->execute(
                'UPDATE *PREFIX*stb_records SET preferred = 0, revision = revision + 1 WHERE origin_id = ? AND preferred = 1',
                [$r['origin_id']],
            );
            $this->store->execute(
                'UPDATE *PREFIX*stb_records SET preferred = 1, revision = revision + 1 WHERE id = ?',
                [$id],
            );
            $this->store->bump($tree);
            $this->store->audit(
                $tree,
                $actor,
                'biography_preferred',
                $r['origin_id'],
                ['recordId' => $id],
                'management',
            );
            return $this->get($tree, $id, $actor);
        });
    }
}
