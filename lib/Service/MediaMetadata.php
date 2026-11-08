<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
/** Descriptive metadata never changes an original or automatically creates genealogy records. */
final class MediaMetadata
{
    public function __construct(private Store $store, private People $people, private Records $records) {}
    public static function empty(): array
    {
        return [
            'description' => '',
            'date' => ['kind' => 'exact', 'value' => '', 'end' => ''],
            'people' => [],
            'eventId' => '',
            'event' => '',
            'place' => '',
            'source' => '',
            'credit' => '',
        ];
    }
    public function clean(string $tree, mixed $input, array $m): array
    {
        if (!is_array($input) || array_is_list($input)) {
            throw new Problem('metadata', 'Provide descriptive metadata.');
        }
        $d = self::empty();
        foreach (
            [
                'description' => 4000,
                'eventId' => 36,
                'event' => 250,
                'place' => 500,
                'source' => 2000,
                'credit' => 500,
            ]
            as $key => $limit
        ) {
            $d[$key] = Values::text($input[$key] ?? '', $key, $limit);
        }
        $d['date'] = Values::date($input['date'] ?? $d['date']);
        $people = $input['people'] ?? [];
        if (!is_array($people) || !array_is_list($people) || count($people) > 32) {
            throw new Problem('metadata_people', 'Use at most 32 named people.');
        }
        $seen = [];
        foreach ($people as $p) {
            if (!is_array($p)) {
                throw new Problem('metadata_people', 'Provide valid named people.');
            }
            $id = Values::text($p['personId'] ?? '', 'personId', 36);
            $name = Values::text($p['name'] ?? '', 'name', 250);
            $association = Values::choice(
                $p['association'] ?? 'shown',
                ['shown', 'mentioned'],
                'association',
            );
            $page = Values::text($p['page'] ?? '', 'page', 100);
            if ($id !== '') {
                $person = $this->people->dto($this->people->row($tree, $id, $m));
                if ($name === '') {
                    $name = trim($person['givenName'] . ' ' . $person['familyName']);
                }
                if (isset($seen[$id . ':' . $association])) {
                    throw new Problem('duplicate_assignment', 'Each person and association may occur once.');
                }
                $seen[$id . ':' . $association] = true;
            }
            $d['people'][] = [
                'personId' => $id,
                'name' => $name,
                'association' => $association,
                'page' => $page,
            ];
        }
        if ($d['eventId'] !== '') {
            $event = $this->records->row($tree, $d['eventId'], $m);
            if ($event['category'] !== 'event') {
                throw Problem::missing();
            }
            if ($d['event'] === '') {
                $d['event'] = json_decode($event['data_json'], true)['title'];
            }
        }
        return $d;
    }
    public function read(string $tree, array $row, array $m): array
    {
        $d = json_decode($row['metadata_json'] ?? 'null', true) ?? self::empty();
        foreach ($d['people'] as &$p) {
            if ($p['personId'] !== '') {
                $p['person'] = $this->people->dto($this->people->row($tree, $p['personId'], $m));
            }
        }
        unset($p);
        if ($d['eventId'] !== '') {
            $event = $this->records->row($tree, $d['eventId'], $m);
            if ($event['category'] !== 'event') {
                throw Problem::missing();
            }
            $d['linkedEvent'] = $this->records->dto($event, $m);
        }
        return $d;
    }
    public function events(string $tree, string $actor, string $query, int $page, Trees $trees): array
    {
        $m = $trees->access($tree, $actor);
        $where = "v.tree_id = ? AND v.category = 'event'";
        $params = [$tree];
        if (!Trees::management($m)) {
            $where .= " AND v.visibility = 'members'";
        }
        $where .=
            ' AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_participants a LEFT JOIN *PREFIX*stb_people p ON p.id = a.person_id AND p.tree_id = v.tree_id WHERE a.record_id = v.id AND (p.id IS NULL OR p.deleted = 1' .
            (!Trees::management($m) ? " OR p.visibility <> 'members'" : '') .
            '))';
        // Events store their title in their existing JSON contract. Escape wildcard input.
        if ($query !== '') {
            $where .= " AND LOWER(v.data_json) LIKE ? ESCAPE '!'";
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
            'SELECT v.id FROM *PREFIX*stb_records v WHERE ' .
                $where .
                ' ORDER BY v.created_at DESC, v.id DESC LIMIT 26 OFFSET ' .
                max(0, min(10000, $page)) * 25,
            $params,
        );
        return [
            'items' => array_map(
                fn($r) => $this->records->get($tree, $r['id'], $actor),
                array_slice($rows, 0, 25),
            ),
            'nextPage' => count($rows) > 25 ? $page + 1 : null,
        ];
    }
    public function write(string $id, array $d): void
    {
        $this->store->execute('DELETE FROM *PREFIX*stb_media_refs WHERE media_id = ?', [$id]);
        $ids = array_unique(array_filter(array_column($d['people'], 'personId')));
        foreach ($ids as $pid) {
            $this->store->insert('stb_media_refs', [
                'media_id' => $id,
                'kind' => 'person',
                'target_id' => $pid,
            ]);
        }
        if ($d['eventId'] !== '') {
            $this->store->insert('stb_media_refs', [
                'media_id' => $id,
                'kind' => 'event',
                'target_id' => $d['eventId'],
            ]);
        }
        $complete =
            ($d['date']['value'] !== '' || $d['date']['end'] !== '') &&
            count($d['people']) > 0 &&
            $d['place'] !== '';
        $this->store->execute(
            'UPDATE *PREFIX*stb_media SET metadata_json = ?, search_people = ?, search_event = ?, search_place = ?, metadata_complete = ? WHERE id = ?',
            [
                Store::json($d),
                mb_strtolower(implode('\n', array_column($d['people'], 'name'))),
                mb_strtolower($d['event']),
                mb_strtolower($d['place']),
                $complete ? 1 : 0,
                $id,
            ],
        );
    }
    /** Same dependency predicate as read(), applied before pagination or filtering. */
    public static function visibleSql(array $m): string
    {
        $privatePerson = Trees::management($m) ? '' : " OR p.visibility <> 'members'";
        $privateEvent = Trees::management($m) ? '' : " OR e.visibility <> 'members'";
        return " AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_media_refs x LEFT JOIN *PREFIX*stb_people p ON p.id = x.target_id AND p.tree_id = v.tree_id WHERE x.media_id = v.id AND x.kind = 'person' AND (p.id IS NULL OR p.deleted = 1$privatePerson))" .
            " AND NOT EXISTS (SELECT 1 FROM *PREFIX*stb_media_refs x LEFT JOIN *PREFIX*stb_records e ON e.id = x.target_id AND e.tree_id = v.tree_id WHERE x.media_id = v.id AND x.kind = 'event' AND (e.id IS NULL OR e.category <> 'event'$privateEvent OR EXISTS (SELECT 1 FROM *PREFIX*stb_participants a LEFT JOIN *PREFIX*stb_people p ON p.id = a.person_id AND p.tree_id = v.tree_id WHERE a.record_id = e.id AND (p.id IS NULL OR p.deleted = 1$privatePerson))))";
    }
}
