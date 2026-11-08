<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
/** Current-state projection. Original import nodes are never replayed. */
final class GedcomExport
{
    public const PROFILE = 'nc-gedcom-551-export-alpha2';
    public const WARNINGS = [
        'GEDCOM is not a backup: history, access rules and application settings are omitted.',
        'Complex families are represented as separate family links; native partnership markers and citation effects may not survive other software.',
        'Media metadata is preserved as descriptive notes; crops and preferred portraits are not portable.',
        'Living status, other sex, structured details and competing assertions have limited portable equivalents.',
        'Only unchanged scalar root vendor extensions can be included with full privacy options; other extensions are omitted.',
    ];
    public function __construct(
        private Store $store,
        private Trees $trees,
        private People $people,
        private Records $records,
        private Evidence $evidence,
        private Media $media,
    ) {}
    private function eligible(array $r, array $o): bool
    {
        return ($r['visibility'] ?? 'members') === 'members' || $o['management'];
    }
    public function project(string $tree, string $actor, array $o): array
    {
        $m = $this->trees->access($tree, $actor);
        $people = [];
        $records = [];
        $media = [];
        $relations = [];
        $claims = [];
        $sources = [];
        $citations = [];
        foreach (
            $this->store->rows(
                'SELECT * FROM *PREFIX*stb_people WHERE tree_id = ? AND deleted = 0 ORDER BY id',
                [$tree],
            )
            as $r
        ) {
            if (!$this->eligible($r, $o) || (!Trees::management($m) && $r['visibility'] !== 'members')) {
                continue;
            }
            $p = $this->people->dto($r);
            if (!$o['living'] && $p['livingStatus'] !== 'deceased') {
                continue;
            }
            $people[$p['id']] = $p;
            if (count($people) > 2000) {
                throw new Problem('export_limit', 'Export exceeds the supported capacity.', 413);
            }
        }
        $allPeople = fn(array $ids) => !array_diff($ids, array_keys($people));
        foreach (
            $this->store->rows(
                'SELECT * FROM *PREFIX*stb_relations WHERE tree_id = ? AND active = 1 ORDER BY id',
                [$tree],
            )
            as $r
        ) {
            if ($allPeople([$r['source_id'], $r['target_id']])) {
                $relations[$r['id']] = $r;
            }
        }
        foreach (
            $this->store->rows('SELECT * FROM *PREFIX*stb_claims WHERE tree_id = ? ORDER BY created_at, id', [
                $tree,
            ])
            as $r
        ) {
            if (
                isset($people[$r['person_id']]) &&
                $this->eligible($r, $o) &&
                (Trees::management($m) || $r['visibility'] === 'members') &&
                $r['assessment'] !== 'rejected'
            ) {
                $claims[$r['id']] = $r;
            }
        }
        // A former preferred name is history, even if its assertion remains supported.
        // Imported alternatives that have never been preferred remain current alternatives.
        $historicalNames = [];
        foreach (
            $this->store->rows(
                "SELECT subject_id, data_json, visibility FROM *PREFIX*stb_history WHERE tree_id = ? AND action IN ('person_created', 'person_updated')",
                [$tree],
            )
            as $history
        ) {
            if (
                !$this->eligible($history, $o) ||
                (!Trees::management($m) && $history['visibility'] !== 'members') ||
                !isset($people[$history['subject_id']])
            ) {
                continue;
            }
            $data = json_decode($history['data_json'], true);
            if (isset($data['givenName'], $data['familyName'])) {
                $historicalNames[$history['subject_id']][
                    Store::json([$data['givenName'], $data['familyName']])
                ] = true;
            }
        }
        foreach ($claims as $id => &$claim) {
            $name = $claim['kind'] === 'name' ? json_decode($claim['value_json'], true) : null;
            $claim['activeAlternative'] =
                $name !== null &&
                !$claim['preferred'] &&
                !isset(
                    $historicalNames[$claim['person_id']][
                        Store::json([$name['givenName'], $name['familyName']])
                    ],
                );
            if (!$claim['preferred'] && !$claim['activeAlternative']) {
                unset($claims[$id]);
            }
        }
        unset($claim);
        foreach (
            $this->store->rows('SELECT id FROM *PREFIX*stb_records WHERE tree_id = ? ORDER BY id', [$tree])
            as $r
        ) {
            try {
                $v = $this->records->get($tree, $r['id'], $actor);
            } catch (Problem $e) {
                if ($e->status === 404) {
                    continue;
                }
                throw $e;
            }
            if (
                $this->eligible($v, $o) &&
                $allPeople(array_column($v['participants'], 'personId')) &&
                isset($people[$v['originId']])
            ) {
                $records[$v['id']] = $v;
            }
        }
        foreach (
            $this->store->rows(
                "SELECT id FROM *PREFIX*stb_media WHERE tree_id = ? AND status = 'ready' ORDER BY id",
                [$tree],
            )
            as $r
        ) {
            try {
                $v = $this->media->get($tree, $r['id'], $actor);
            } catch (Problem $e) {
                if ($e->status === 404) {
                    continue;
                }
                throw $e;
            }
            $ids = array_merge(
                array_column($v['assignments'], 'personId'),
                array_filter(array_column($v['metadata']['people'], 'personId')),
            );
            if (
                $this->eligible($v, $o) &&
                $allPeople($ids) &&
                (!$v['metadata']['eventId'] || isset($records[$v['metadata']['eventId']]))
            ) {
                $media[$v['id']] = $v;
            }
        }
        foreach (
            $this->store->rows('SELECT id FROM *PREFIX*stb_sources WHERE tree_id = ? ORDER BY id', [$tree])
            as $r
        ) {
            try {
                $source = $this->evidence->source($tree, $r['id'], $actor);
            } catch (Problem $e) {
                if ($e->status === 404) {
                    continue;
                }
                throw $e;
            }
            if ($this->eligible($source, $o)) {
                $sources[$source['id']] = $source;
            }
        }
        $contexts = [];
        foreach (
            $this->store->rows('SELECT * FROM *PREFIX*stb_contexts WHERE tree_id = ? ORDER BY id', [$tree])
            as $context
        ) {
            if (
                !$this->eligible($context, $o) ||
                (!Trees::management($m) && $context['visibility'] !== 'members')
            ) {
                continue;
            }
            $members = $this->store->rows(
                'SELECT person_id FROM *PREFIX*stb_context_people WHERE context_id = ?',
                [$context['id']],
            );
            if ($allPeople(array_column($members, 'person_id'))) {
                $contexts[$context['id']] = $context;
            }
        }
        foreach (
            $this->store->rows('SELECT id FROM *PREFIX*stb_citations WHERE tree_id = ? ORDER BY id', [$tree])
            as $r
        ) {
            try {
                $c = $this->evidence->citation($tree, $r['id'], $actor);
            } catch (Problem $e) {
                if ($e->status === 404) {
                    continue;
                }
                throw $e;
            }
            if (!$this->eligible($c, $o) || !$this->eligible($c['source'], $o)) {
                continue;
            }
            foreach ($c['targets'] as $target) {
                if (!$allPeople($target['personIds'])) {
                    continue 2;
                }
                $set = match ($target['kind']) {
                    'person' => $people,
                    'claim' => $claims,
                    'relation' => $relations,
                    'record' => $records,
                    'context' => $contexts,
                    default => [],
                };
                if (!isset($set[$target['targetId']])) {
                    continue 2;
                }
            }
            $citations[$c['id']] = $c;
            $sources[$c['sourceId']] = $c['source'];
        }
        // Import provenance identifies vital statements without relying on editable titles.
        $maps = $this->store->rows(
            'SELECT m.*, n.data_json AS node_json FROM *PREFIX*stb_import_maps m JOIN *PREFIX*stb_imports i ON i.id = m.run_id JOIN *PREFIX*stb_import_nodes n ON n.run_id = m.run_id AND n.ordinal = m.ordinal WHERE i.tree_id = ? ORDER BY m.ordinal',
            [$tree],
        );
        foreach ($maps as $map) {
            $node = json_decode($map['node_json'], true);
            if (
                $map['kind'] === 'record' &&
                isset($records[$map['object_id']]) &&
                in_array($node['tag'], ['BIRT', 'DEAT'], true)
            ) {
                $records[$map['object_id']]['vitalKind'] = $node['tag'] === 'BIRT' ? 'birth' : 'death';
            }
        }
        // Unchanged scalar root extensions only, never pointers, children or private partial output.
        if ($o['living'] && $o['management']) {
            $runs = [];
            foreach ($maps as $map) {
                $kind = $map['kind'];
                $id = $map['object_id'];
                $owner =
                    $kind === 'person'
                        ? $people[$id] ?? null
                        : ($kind === 'source'
                            ? $sources[$id] ?? null
                            : null);
                $root = json_decode($map['node_json'], true);
                if (!$owner || $owner['revision'] !== 1 || $root['level'] !== 0) {
                    continue;
                }
                if (
                    $kind === 'person' &&
                    array_filter(
                        $records,
                        fn($r) => in_array($id, array_column($r['participants'], 'personId'), true) &&
                            $r['revision'] !== 1,
                    )
                ) {
                    continue;
                }
                if (!isset($runs[$map['run_id']])) {
                    $nodes = $this->store->rows(
                        'SELECT data_json FROM *PREFIX*stb_import_nodes WHERE run_id = ? ORDER BY ordinal',
                        [$map['run_id']],
                    );
                    $runs[$map['run_id']] = array_map(fn($n) => json_decode($n['data_json'], true), $nodes);
                }
                $nodes = $runs[$map['run_id']];
                $parents = array_column($nodes, 'parent');
                foreach ($nodes as $node) {
                    if (
                        $node['parent'] !== $root['ordinal'] ||
                        !preg_match('/^_[A-Z0-9_]+$/D', $node['tag']) ||
                        str_contains($node['value'], '@') ||
                        in_array($node['ordinal'], $parents, true)
                    ) {
                        continue;
                    }
                    if ($kind === 'person') {
                        $people[$id]['extensions'][] = [$node['tag'], $node['value']];
                    } else {
                        $sources[$id]['extensions'][] = [$node['tag'], $node['value']];
                    }
                }
            }
        }
        $count =
            count($people) +
            count($records) +
            count($media) +
            count($relations) +
            count($claims) +
            count($sources) +
            count($citations);
        if ($count > 50000 || ($o['media'] && array_sum(array_column($media, 'size')) > 67108864)) {
            throw new Problem('export_limit', 'Export exceeds the supported capacity.', 413);
        }
        return compact('people', 'records', 'media', 'relations', 'claims', 'sources', 'citations');
    }
    public static function date(array $d): string
    {
        $format = static function (string $v): string {
            if ($v === '') {
                return '';
            }
            $p = explode('-', $v);
            $months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
            return (isset($p[2]) ? (int) $p[2] . ' ' : '') .
                (isset($p[1]) ? $months[(int) $p[1] - 1] . ' ' : '') .
                $p[0];
        };
        if (($d['kind'] ?? '') === 'literal') {
            return '(' . str_replace(['(', ')'], ['[', ']'], $d['value']) . ')';
        }
        $v = $format($d['value'] ?? '');
        $end = $format($d['end'] ?? '');
        return match ($d['kind'] ?? 'exact') {
            'about' => 'ABT ' . $v,
            'before' => 'BEF ' . $v,
            'after' => 'AFT ' . $v,
            'calculated' => 'CAL ' . $v,
            'estimated' => 'EST ' . $v,
            'between' => ($v !== '' ? 'BET ' . $v : '') . ' AND ' . $end,
            'period' => ($v !== '' ? 'FROM ' . $v : '') . ($end !== '' ? ' TO ' . $end : ''),
            default => $v,
        };
    }
    private static function line(int $level, string $tag, string $value = ''): string
    {
        // Escape literal at signs and split UTF-8 without breaking code points or GEDCOM lines.
        $out = '';
        $first = true;
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $value)) as $part) {
            $chunks = mb_str_split(str_replace('@', '@@', $part), 50);
            if (!$chunks) {
                $chunks = [''];
            }
            foreach ($chunks as $i => $chunk) {
                $out .=
                    ($first ? $level : $level + 1) .
                    ' ' .
                    ($first ? $tag : ($i === 0 ? 'CONT' : 'CONC')) .
                    ($chunk !== '' ? ' ' . $chunk : '') .
                    "\r\n";
                $first = false;
            }
        }
        return $out;
    }
    public function render(array $x): array
    {
        $ged =
            "0 HEAD\r\n1 SOUR NEXTCLOUD_STAMMBAUM\r\n1 GEDC\r\n2 VERS 5.5.1\r\n2 FORM LINEAGE-LINKED\r\n1 CHAR UTF-8\r\n1 SUBM @SUB1@\r\n0 @SUB1@ SUBM\r\n1 NAME Stammbaum export\r\n";
        $pid = [];
        $sid = [];
        $mid = [];
        $families = [];
        $links = [];
        foreach (array_keys($x['people']) as $i => $id) {
            $pid[$id] = 'I' . ($i + 1);
        }
        foreach (array_keys($x['sources']) as $i => $id) {
            $sid[$id] = 'S' . ($i + 1);
        }
        foreach (array_keys($x['media']) as $i => $id) {
            $mid[$id] = 'O' . ($i + 1);
        }
        foreach ($x['relations'] as $id => $r) {
            $fid = 'F' . (count($families) + 1);
            $a = $r['source_id'];
            $b = $r['target_id'];
            $family = "0 @$fid@ FAM\r\n";
            if ($r['kind'] === 'parent') {
                $tag = $x['people'][$a]['sex'] === 'female' ? 'WIFE' : 'HUSB';
                $family .= "1 $tag @{$pid[$a]}@\r\n1 CHIL @{$pid[$b]}@\r\n";
                $links[$a][] = "1 FAMS @$fid@\r\n";
                $ped = match ($r['parent_type']) {
                    'biological' => 'birth',
                    'adoptive' => 'adopted',
                    'foster' => 'foster',
                    default => null,
                };
                $links[$b][] = "1 FAMC @$fid@\r\n" . ($ped ? "2 PEDI $ped\r\n" : '');
                if (!$ped) {
                    $family .= self::line(1, 'NOTE', 'Parent relationship: ' . $r['parent_type']);
                }
            } else {
                $family .= "1 HUSB @{$pid[$a]}@\r\n1 WIFE @{$pid[$b]}@\r\n";
                $links[$a][] = "1 FAMS @$fid@\r\n";
                $links[$b][] = "1 FAMS @$fid@\r\n";
                // Standard spouse slots do not prove a partnership. This narrow
                // marker preserves the explicit edge in the native alpha.4 importer.
                $family .= "1 _STB_PARTNER Y\r\n";
            }
            $families[$id] = $family;
        }
        foreach ($x['people'] as $id => $p) {
            $ged .=
                "0 @{$pid[$id]}@ INDI\r\n" .
                self::line(
                    1,
                    'NAME',
                    str_replace('/', ' ', $p['givenName']) .
                        ' /' .
                        str_replace('/', ' ', $p['familyName']) .
                        '/',
                );
            $ged .=
                '1 SEX ' .
                match ($p['sex']) {
                    'female' => 'F',
                    'male' => 'M',
                    default => 'U',
                } .
                "\r\n";
            foreach (['birth' => 'BIRT', 'death' => 'DEAT'] as $key => $tag) {
                $date = $p[$key . 'Date'];
                $place = $p[$key . 'Place'];
                $updated = $p['updatedAt'];
                foreach ($x['records'] as $record) {
                    if (
                        ($record['vitalKind'] ?? '') === $key &&
                        $record['originId'] === $id &&
                        $record['updatedAt'] > $updated
                    ) {
                        $date = $record['date'];
                        $place = $record['place'];
                        $updated = $record['updatedAt'];
                    }
                }
                $d = self::date($date);
                if (
                    trim($d) !== '' ||
                    $place !== '' ||
                    ($key === 'death' && $p['livingStatus'] === 'deceased')
                ) {
                    $ged .=
                        "1 $tag\r\n" .
                        ($d !== '' ? self::line(2, 'DATE', $d) : '') .
                        ($place !== '' ? self::line(2, 'PLAC', $place) : '');
                }
            }
            foreach ($x['claims'] as $c) {
                if (
                    $c['person_id'] === $id &&
                    $c['kind'] === 'name' &&
                    !$c['preferred'] &&
                    ($c['activeAlternative'] ?? false)
                ) {
                    $name = json_decode($c['value_json'], true);
                    $ged .= self::line(1, 'NAME', $name['givenName'] . ' /' . $name['familyName'] . '/');
                }
            }
            if (!empty($p['biography'])) {
                $ged .= self::line(1, 'NOTE', $p['biography']);
            }
            foreach ($x['records'] as $r) {
                if (
                    isset($r['vitalKind']) ||
                    !in_array($id, array_column($r['participants'], 'personId'), true)
                ) {
                    continue;
                }
                if ($r['category'] === 'event') {
                    $tag = match ($r['type']) {
                        'baptism' => 'BAPM',
                        'burial' => 'BURI',
                        'occupation' => 'OCCU',
                        'residence' => 'RESI',
                        'census' => 'CENS',
                        'probate' => 'PROB',
                        'will' => 'WILL',
                        default => 'EVEN',
                    };
                    $ged .= "1 $tag\r\n" . self::line(2, 'TYPE', $r['title']);
                    if (!empty($r['date']['value']) || !empty($r['date']['end'])) {
                        $ged .= self::line(2, 'DATE', self::date($r['date']));
                    }
                    if (!empty($r['place'])) {
                        $ged .= self::line(2, 'PLAC', $r['place']);
                    }
                    if (!empty($r['text'])) {
                        $ged .= self::line(2, 'NOTE', $r['text']);
                    }
                } else {
                    $ged .= self::line(1, 'NOTE', $r['title'] . ': ' . ($r['text'] ?? ''));
                }
                foreach ($r['fields'] ?? [] as $field) {
                    $ged .= self::line(
                        1,
                        'NOTE',
                        $field['label'] . ': ' . $field['value'] . ' ' . $field['unit'],
                    );
                }
                foreach ($r['participants'] as $participant) {
                    if ($participant['personId'] === $id) {
                        $ged .= self::line(1, 'NOTE', 'Event role: ' . $participant['role']);
                    }
                }
            }
            foreach ($x['citations'] as $c) {
                $targets = array_unique(array_merge(...array_map(fn($t) => $t['personIds'], $c['targets'])));
                if (!in_array($id, $targets, true)) {
                    continue;
                }
                $ged .= "1 SOUR @{$sid[$c['sourceId']]}@\r\n";
                foreach (
                    ['locator' => 'PAGE', 'quotation' => 'TEXT', 'reasoning' => 'NOTE']
                    as $key => $tag
                ) {
                    if (!empty($c[$key])) {
                        $ged .=
                            ($tag === 'TEXT' ? "2 DATA\r\n" : '') .
                            self::line($tag === 'TEXT' ? 3 : 2, $tag, $c[$key]);
                    }
                }
                foreach (['documentDate', 'accessDate'] as $field) {
                    if (!empty($c[$field]['value']) || !empty($c[$field]['end'])) {
                        $ged .= self::line(2, 'NOTE', $field . ': ' . self::date($c[$field]));
                    }
                }
                foreach (['reliability', 'url'] as $field) {
                    if (!empty($c[$field])) {
                        $ged .= self::line(2, 'NOTE', $field . ': ' . $c[$field]);
                    }
                }
                foreach ($c['targets'] as $target) {
                    $ged .= self::line(
                        2,
                        'NOTE',
                        'Target: ' . $target['kind'] . ' · ' . $target['effect'] . ' · ' . $target['label'],
                    );
                }
            }
            foreach ($x['media'] as $mediaId => $v) {
                if (
                    in_array(
                        $id,
                        array_merge(
                            array_column($v['assignments'], 'personId'),
                            array_column($v['metadata']['people'], 'personId'),
                        ),
                        true,
                    )
                ) {
                    $ged .= "1 OBJE @{$mid[$mediaId]}@\r\n";
                }
            }
            foreach ($p['extensions'] ?? [] as [$tag, $value]) {
                $ged .= self::line(1, $tag, $value);
            }
            $ged .= implode('', $links[$id] ?? []);
        }
        $ged .= implode('', $families);
        foreach ($x['sources'] as $id => $s) {
            $ged .= "0 @{$sid[$id]}@ SOUR\r\n";
            foreach (
                ['title' => 'TITL', 'author' => 'AUTH', 'publication' => 'PUBL', 'description' => 'TEXT']
                as $key => $tag
            ) {
                if (!empty($s[$key])) {
                    $ged .= self::line(1, $tag, $s[$key]);
                }
            }
            foreach ($s['extensions'] ?? [] as [$tag, $value]) {
                $ged .= self::line(1, $tag, $value);
            }
            foreach (['repository', 'callNumber', 'url'] as $key) {
                if (!empty($s[$key])) {
                    $ged .= self::line(1, 'NOTE', $key . ': ' . $s[$key]);
                }
            }
        }
        foreach ($x['media'] as $id => $v) {
            $ext = match ($v['mime']) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                default => 'pdf',
            };
            $ged .=
                "0 @{$mid[$id]}@ OBJE\r\n1 FILE media/$id.$ext\r\n2 FORM $ext\r\n" .
                self::line(2, 'TITL', $v['title']);
            $m = $v['metadata'];
            foreach (['description', 'event', 'place', 'source', 'credit'] as $key) {
                if ($m[$key] !== '') {
                    $ged .= self::line(1, 'NOTE', $key . ': ' . $m[$key]);
                }
            }
            if ($m['date']['value'] !== '' || $m['date']['end'] !== '') {
                $ged .= self::line(1, 'NOTE', 'Subject date: ' . self::date($m['date']));
            }
            foreach ($m['people'] as $p) {
                $ged .= self::line(
                    1,
                    'NOTE',
                    $p['association'] .
                        ': ' .
                        ($p['name'] ?: 'Unidentified person') .
                        ($p['page'] !== '' ? ' (page ' . $p['page'] . ')' : ''),
                );
            }
        }
        return [
            'gedcom' => $ged . "0 TRLR\r\n",
            'report' => [
                'profile' => self::PROFILE,
                'warnings' => self::WARNINGS,
                'counts' => array_map('count', $x),
            ],
        ];
    }
}
