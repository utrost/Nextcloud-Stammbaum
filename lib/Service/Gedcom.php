<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;

/** Deliberately closed semantic whitelist. Never fetches input references. */
final class Gedcom
{
    public const PROFILE = 'nc-gedcom-551-alpha4';
    public const PROFILES = [
        'nc-gedcom-551-alpha1',
        'nc-gedcom-551-alpha2',
        'nc-gedcom-551-alpha3',
        self::PROFILE,
    ];
    public const MAX_BYTES = 8388608;
    private array $nodes = [],
        $children = [],
        $roots = [],
        $xref = [],
        $errors = [],
        $warnings = [];
    private int $errorCount = 0,
        $warningCount = 0;
    private string $encoding = '';
    private array $plan = [];
    private int $units = 0;
    private array $issueCounts = [];
    private bool $legacy = false;
    private bool $extended = false;
    private bool $nativePartner = false;

    private function issue(string $code, int $line = 0, bool $warning = false): void
    {
        $key = ($warning ? 'warning:' : 'error:') . $code;
        $this->issueCounts[$key] = ($this->issueCounts[$key] ?? 0) + 1;
        if ($warning) {
            $this->warningCount++;
            if (count($this->warnings) < 200) {
                $this->warnings[] = ['code' => $code, 'line' => $line, 'severity' => 'warning'];
            }
        } else {
            $this->errorCount++;
            if (count($this->errors) < 200) {
                $this->errors[] = ['code' => $code, 'line' => $line, 'severity' => 'error'];
            }
        }
    }
    private function fail(string $code, int $line = 0): never
    {
        throw new Problem($code, 'The GEDCOM input cannot be interpreted safely.', 422, ['line' => $line]);
    }
    public function analyze(string $bytes, string $profile = self::PROFILE): array
    {
        if (!in_array($profile, self::PROFILES, true)) {
            $this->fail('profile_unavailable');
        }
        $this->legacy = $profile === 'nc-gedcom-551-alpha1';
        $this->extended = in_array($profile, ['nc-gedcom-551-alpha3', self::PROFILE], true);
        $this->nativePartner = $profile === self::PROFILE;
        // Fresh instance state also makes repeated use by a long-lived worker deterministic.
        $this->nodes = $this->children = $this->roots = $this->xref = $this->errors = $this->warnings = [];
        $this->errorCount = $this->warningCount = 0;
        $this->units = 0;
        $this->issueCounts = [];
        $this->encoding = '';
        $this->plan = [
            'people' => [],
            'relations' => [],
            'contexts' => [],
            'records' => [],
            'sources' => [],
            'citations' => [],
        ];
        $complete = true;
        try {
            $this->parse($bytes);
            $this->interpret();
        } catch (Problem $e) {
            $complete = false;
            $this->issue($e->errorCode, (int) ($e->details['line'] ?? 0));
        }
        $counts = array_map('count', $this->plan);
        $report = [
            'complete' => $complete,
            'errors' => $this->errorCount,
            'warnings' => $this->warningCount,
            'issues' => array_slice([...$this->errors, ...$this->warnings], 0, 200),
            'counts' => $counts,
            'lines' => count($this->nodes),
            'classifications' => array_count_values(array_column($this->nodes, 'classification')),
            'preview' => array_map(
                fn($p) => [
                    'givenName' => $p['data']['givenName'],
                    'familyName' => $p['data']['familyName'],
                    'visibility' => $p['data']['visibility'],
                ],
                array_slice(array_values($this->plan['people']), 0, 20),
            ),
        ];
        if ($this->extended) {
            $report['issueCounts'] = $this->issueCounts;
        }
        return [
            'encoding' => $this->encoding,
            'report' => $report,
            'nodes' => array_values($this->nodes),
            'plan' => $this->plan,
            'planHash' => hash('sha256', Store::json([$profile, $this->plan, $report])),
        ];
    }
    private function parse(string $bytes): void
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            $this->fail('file_size');
        }
        $offset = 0;
        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            $this->encoding = str_starts_with($bytes, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
            $offset = 2;
        } else {
            $this->encoding = 'UTF-8';
            if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
                $offset = 3;
            }
        }
        $raw = substr($bytes, $offset);
        if (!mb_check_encoding($raw, $this->encoding)) {
            $this->fail('invalid_encoding');
        }
        $text = mb_convert_encoding($raw, 'UTF-8', $this->encoding);
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
            $this->fail('invalid_characters');
        }
        $lines = preg_split('/(\r\n|\r|\n)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $stack = [];
        $parsed = [];
        $children = [];
        $roots = [];
        $xref = [];
        for ($i = 0, $ordinal = 1; $i < count($lines); $i += 2, $ordinal++) {
            $line = $lines[$i];
            if ($line === '' && $i === count($lines) - 1) {
                break;
            }
            if ($ordinal > 100000) {
                $this->fail('line_limit', $ordinal);
            }
            if (mb_strlen($line) > 16384) {
                $this->fail('line_length', $ordinal);
            }
            if (
                !preg_match('/^(0|[1-9][0-9]*) (?:(@[^@\s]+@) )?([A-Z_][A-Z0-9_]*)(?: (.*))?$/Du', $line, $m)
            ) {
                $this->fail('line_syntax', $ordinal);
            }
            $level = (int) $m[1];
            if ($level > 64 || ($level > 0 && !isset($stack[$level - 1]))) {
                $this->fail('level_jump', $ordinal);
            }
            $pointer = $m[2] ?? '';
            $tag = $m[3];
            $value = $m[4] ?? '';
            if ($pointer !== '' && ($level !== 0 || isset($xref[$pointer]))) {
                $this->fail('duplicate_or_nested_xref', $ordinal);
            }
            if ($pointer !== '') {
                $xref[$pointer] = $ordinal;
            }
            $parent = $level === 0 ? null : $stack[$level - 1];
            $parsed[$ordinal] = [
                'ordinal' => $ordinal,
                'parent' => $parent,
                'level' => $level,
                'tag' => $tag,
                'xref' => $pointer,
                'value' => $value,
                'byteOffset' => $offset,
                'classification' => 'UNMAPPED',
            ];
            if ($parent === null) {
                $roots[] = $ordinal;
            } else {
                $children[$parent][] = $ordinal;
            }
            foreach (array_keys($stack) as $depth) {
                if ($depth >= $level) {
                    unset($stack[$depth]);
                }
            }
            $stack[$level] = $ordinal;
            $offset += strlen(mb_convert_encoding($line . ($lines[$i + 1] ?? ''), $this->encoding, 'UTF-8'));
        }
        // Invalid grammar never exposes a fabricated partial hierarchy.
        $this->nodes = $parsed;
        $this->children = $children;
        $this->roots = $roots;
        $this->xref = $xref;
        if (!$roots || $parsed[$roots[0]]['tag'] !== 'HEAD' || $parsed[end($roots)]['tag'] !== 'TRLR') {
            $this->fail('header_trailer');
        }
        if (count($parsed) > 50000) {
            $this->fail('interpretation_budget');
        }
        if (count(array_filter($parsed, fn($n) => $n['level'] === 0 && $n['tag'] === 'INDI')) > 2000) {
            $this->fail('person_limit');
        }
    }
    private function mark(int $id, string $classification = 'NORMALIZED'): void
    {
        // Count repeated projection work too, including shared text expansion.
        $this->units += 1 + intdiv(strlen($this->nodes[$id]['value']), 256);
        if ($this->units > 50000) {
            $this->fail('interpretation_budget', $id);
        }
        $this->nodes[$id]['classification'] = $classification;
    }
    private function structural(int $id, string $reason): void
    {
        $this->mark($id, 'STRUCTURAL');
        $this->nodes[$id]['reason'] = $reason;
        foreach ($this->children[$id] ?? [] as $c) {
            $this->structural($c, $reason);
        }
    }
    private function structuralText(int $id): void
    {
        $this->mark($id, 'STRUCTURAL');
        $this->nodes[$id]['reason'] = 'unreferenced_note';
        foreach ($this->children[$id] ?? [] as $child) {
            if (in_array($this->nodes[$child]['tag'], ['CONT', 'CONC'], true)) {
                $this->structuralText($child);
            }
        }
    }
    private function all(int $id, string $tag): array
    {
        return array_values(
            array_filter($this->children[$id] ?? [], fn($c) => $this->nodes[$c]['tag'] === $tag),
        );
    }
    private function single(int $id, string $tag): ?int
    {
        $items = $this->all($id, $tag);
        if (count($items) > 1) {
            $this->issue('repeated_' . $tag, $items[1]);
        }
        return $items[0] ?? null;
    }
    private function literal(int $id, int $max = 20000, bool $calendar = false): string
    {
        $this->mark($id);
        $v = $this->nodes[$id]['value'];
        foreach ($this->children[$id] ?? [] as $c) {
            if (in_array($this->nodes[$c]['tag'], ['CONT', 'CONC'], true)) {
                $v .= ($this->nodes[$c]['tag'] === 'CONT' ? "\n" : '') . $this->literalRaw($c);
            }
        }
        // Pointer-like scalar text is never silently interpreted as plain prose.
        $pointerText = $calendar && !$this->legacy ? preg_replace('/@#D[A-Z0-9 ]+@/', '', $v) : $v;
        if (preg_match('/(?<!@)@[^@]+@(?!@)/', $pointerText)) {
            $this->fail('unexpected_pointer', $id);
        }
        return Values::text(str_replace('@@', '@', $v), 'GEDCOM text', $max);
    }
    private function literalRaw(int $id): string
    {
        $this->mark($id);
        $v = $this->nodes[$id]['value'];
        foreach ($this->children[$id] ?? [] as $c) {
            if (!in_array($this->nodes[$c]['tag'], ['CONT', 'CONC'], true)) {
                continue;
            }
            $v .= ($this->nodes[$c]['tag'] === 'CONT' ? "\n" : '') . $this->literalRaw($c);
        }
        return $v;
    }
    private function scalar(int $id, string $tag, int $max = 250): string
    {
        $c = $this->single($id, $tag);
        return $c === null ? '' : $this->literal($c, $max);
    }
    private function pointer(int $id, string $tag): string
    {
        $this->mark($id);
        $v = $this->nodes[$id]['value'];
        if (!isset($this->xref[$v]) || $this->nodes[$this->xref[$v]]['tag'] !== $tag) {
            $this->fail('unresolved_reference', $id);
        }
        return $v;
    }
    private function restriction(int $id): string
    {
        $v = $this->scalar($id, 'RESN');
        if ($v !== '' && !in_array(strtolower($v), ['confidential', 'privacy', 'locked'], true)) {
            $this->issue('restriction_value', $id);
        }
        return $v !== '' ? 'management' : 'members';
    }
    private function date(?int $id): array
    {
        if ($id === null) {
            return Values::date(['kind' => 'exact', 'value' => '']);
        }
        $v = $this->literal($id, 250, true);
        $convert = static function (string $v): ?string {
            $months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
            if (preg_match('/^[0-9]{4}$/D', $v)) {
                return $v;
            }
            if (preg_match('/^(?:([0-9]{1,2}) )?([A-Z]{3}) ([0-9]{4})$/D', $v, $m)) {
                $month = array_search($m[2], $months, true);
                if ($month !== false) {
                    return $m[3] .
                        '-' .
                        sprintf('%02d', $month + 1) .
                        (($m[1] ?? '') !== '' ? '-' . sprintf('%02d', (int) $m[1]) : '');
                }
            }
            return null;
        };
        $kind = 'exact';
        $start = $v;
        $end = '';
        if (preg_match('/^(ABT|BEF|AFT|CAL|EST) (.+)$/D', $v, $m)) {
            $kind = [
                'ABT' => 'about',
                'BEF' => 'before',
                'AFT' => 'after',
                'CAL' => 'calculated',
                'EST' => 'estimated',
            ][$m[1]];
            $start = $m[2];
        } elseif (preg_match('/^BET (.+) AND (.+)$/D', $v, $m)) {
            $kind = 'between';
            $start = $m[1];
            $end = $m[2];
        } elseif (preg_match('/^FROM (.+) TO (.+)$/D', $v, $m)) {
            $kind = 'period';
            $start = $m[1];
            $end = $m[2];
        } elseif (preg_match('/^(FROM|TO) (.+)$/D', $v, $m)) {
            $kind = 'period';
            $start = $m[1] === 'FROM' ? $m[2] : '';
            $end = $m[1] === 'TO' ? $m[2] : '';
        }
        $a = $start === '' ? '' : $convert($start);
        $b = $end === '' ? '' : $convert($end);
        if ($a !== null && $b !== null) {
            try {
                return Values::date(['kind' => $kind, 'value' => $a, 'end' => $b]);
            } catch (Problem) {
                /* Preserve invalid calendar wording without inventing a date. */
            }
        }
        $this->issue('literal_date', $id, true);
        return Values::date(['kind' => 'literal', 'value' => $v]);
    }
    private function note(int $id): array
    {
        $ref = $this->nodes[$id]['value'];
        $visibility = 'members';
        if (preg_match('/^@[^@]+@$/D', $ref)) {
            $target = $this->xref[$this->pointer($id, 'NOTE')];
            $visibility = $this->restriction($target);
            return [$this->literal($target), $visibility];
        }
        return [$this->literal($id), $visibility];
    }
    private function citations(int $id, string $target, string $visibility): void
    {
        foreach ($this->all($id, 'SOUR') as $c) {
            $source = $this->pointer($c, 'SOUR');
            $data = $this->single($c, 'DATA');
            if ($data !== null) {
                $this->mark($data);
                if ($this->nodes[$data]['value'] !== '') {
                    $this->issue('citation_data_value', $data);
                }
            }
            $quay = $this->scalar($c, 'QUAY');
            if ($quay !== '' && !in_array($quay, ['0', '1', '2', '3'], true)) {
                $this->issue('citation_quality', $c);
            }
            $notes = [];
            if ($this->extended) {
                $event = $this->single($c, 'EVEN');
                if ($event !== null) {
                    $notes[] = 'GEDCOM EVEN: ' . $this->literal($event, 1000);
                    $role = $this->scalar($event, 'ROLE', 1000);
                    if ($role !== '') {
                        $notes[] = 'GEDCOM ROLE: ' . $role;
                    }
                }
            }
            foreach ($this->all($c, 'NOTE') as $n) {
                [$text, $privacy] = $this->note($n);
                $notes[] = $text;
                if ($privacy === 'management') {
                    $visibility = 'management';
                }
            }
            $this->plan['citations'][] = [
                'line' => $c,
                'source' => $source,
                'target' => $target,
                'visibility' => $visibility,
                'data' => [
                    'locator' => $this->scalar($c, 'PAGE', 500),
                    'quotation' => $data === null ? '' : $this->scalar($data, 'TEXT', 8000),
                    'url' => '',
                    'documentDate' => $this->date($data === null ? null : $this->single($data, 'DATE')),
                    'accessDate' => $this->date(null),
                    'reliability' => 'unassessed',
                    'reasoning' => Values::text(
                        implode("\n", [...$quay !== '' ? ['GEDCOM QUAY: ' . $quay] : [], ...$notes]),
                        'reasoning',
                        4000,
                    ),
                ],
            ];
        }
    }
    private function record(
        int $id,
        array $persons,
        string $title,
        string $type,
        string $visibility,
        bool $note = false,
    ): void {
        $this->mark($id);
        $text = [];
        $confidential = $this->extended && $this->nodes[$id]['tag'] === 'RELI';
        if ($note) {
            [$value, $privacy] = $this->note($id);
            $text[] = $value;
            if ($privacy === 'management') {
                $visibility = 'management';
            }
        } else {
            $literalAttribute =
                $this->extended &&
                in_array(
                    $this->nodes[$id]['tag'],
                    ['OCCU', 'EDUC', 'RESI', 'NATI', 'RELI', 'DSCR', 'PROP', 'TITL', 'FACT'],
                    true,
                );
            if (
                !in_array($this->nodes[$id]['value'], ['', 'Y'], true) ||
                ($literalAttribute && $this->nodes[$id]['value'] === 'Y')
            ) {
                if (
                    $this->extended &&
                    !in_array($this->nodes[$id]['tag'], ['BIRT', 'DEAT', 'MARR', 'DIV'], true)
                ) {
                    $text[] = $this->literal($id, 15000);
                    $this->issue('literal_event_wording', $id, true);
                } else {
                    $this->issue('event_value', $id);
                }
            }
            if ($this->extended) {
                foreach (['TYPE', 'AGE', 'AGNC', 'CAUS', 'RELI'] as $tag) {
                    $value = $this->scalar($id, $tag, 1000);
                    if ($value !== '') {
                        $text[] = $tag . ': ' . $value;
                    }
                    if ($tag === 'RELI' && $value !== '') {
                        $confidential = true;
                        $visibility = 'management';
                    }
                }
                if ($this->nodes[$id]['tag'] === 'FACT' && $this->scalar($id, 'TYPE', 1000) === '') {
                    $this->issue('fact_type_required', $id);
                }
                foreach (['HUSB', 'WIFE'] as $role) {
                    $detail = $this->single($id, $role);
                    if ($detail !== null) {
                        $this->mark($detail);
                        if ($this->nodes[$detail]['value'] !== '') {
                            $this->issue('event_role_value', $detail);
                        }
                        $text[] = $role . ' AGE: ' . $this->scalar($detail, 'AGE', 1000);
                    }
                }
                foreach (['ADDR', 'PHON', 'EMAIL', 'FAX', 'WWW'] as $tag) {
                    foreach ($this->all($id, $tag) as $detail) {
                        $text[] = $tag . ': ' . $this->literal($detail, 2000);
                        if ($tag === 'ADDR') {
                            foreach (['ADR1', 'ADR2', 'ADR3', 'CITY', 'STAE', 'POST', 'CTRY'] as $part) {
                                $value = $this->scalar($detail, $part, 1000);
                                if ($value !== '') {
                                    $text[] = $part . ': ' . $value;
                                }
                            }
                        }
                        $confidential = true;
                        $visibility = 'management';
                    }
                }
            }
            foreach ($this->all($id, 'NOTE') as $n) {
                [$value, $privacy] = $this->note($n);
                $text[] = $value;
                if ($privacy === 'management') {
                    $visibility = 'management';
                }
            }
        }
        if ($this->restriction($id) === 'management') {
            $visibility = 'management';
        }
        $this->plan['records'][] = [
            'line' => $id,
            'people' => $persons,
            'visibility' => $visibility,
            'category' => $note ? 'note' : 'event',
            'type' => $note ? 'note' : $type,
            'data' => [
                'title' => $title,
                'text' => Values::text(implode("\n", $text), 'text', 20000),
                'date' => $this->date($note ? null : $this->single($id, 'DATE')),
                'place' => $note ? '' : $this->scalar($id, 'PLAC', 500),
                'ongoing' => false,
                'fields' => [],
            ],
        ];
        if ($this->extended && !$note) {
            $index = array_key_last($this->plan['records']);
            if ($this->nodes[$id]['tag'] === 'RELI') {
                $this->plan['records'][$index]['category'] = 'attribute';
                $this->plan['records'][$index]['type'] = 'religion';
            }
            if ($confidential) {
                $this->plan['records'][$index]['data']['confidential'] = true;
                $this->issue('confidential_record', $id, true);
            }
        }
        $this->citations($id, 'record:' . $id, $visibility);
        if ($this->extended && !$note) {
            $this->mediaReferences($id, $persons, $visibility);
        }
    }
    private function detailRecord(
        int $id,
        array $persons,
        string $title,
        string $type,
        string $visibility,
        array $fields,
    ): void {
        $this->plan['records'][] = [
            'line' => $id,
            'people' => $persons,
            'visibility' => $visibility,
            'category' => count($persons) > 1 ? 'event' : 'attribute',
            'type' => count($persons) > 1 ? 'custom' : $type,
            'data' => [
                'title' => $title,
                'text' => '',
                'date' => $this->date(null),
                'place' => '',
                'ongoing' => false,
                'fields' => $fields,
            ],
        ];
    }
    private function mediaReferences(int $owner, array $persons, string $visibility): void
    {
        foreach ($this->all($owner, 'OBJE') as $ref) {
            $id = $ref;
            if (preg_match('/^@[^@]+@$/D', $this->nodes[$ref]['value'])) {
                $id = $this->xref[$this->pointer($ref, 'OBJE')];
            } else {
                $this->mark($ref);
                if ($this->nodes[$ref]['value'] !== '') {
                    $this->issue('media_object_value', $ref);
                }
            }
            $this->mark($id);
            $privacy = $this->restriction($id) === 'management' ? 'management' : $visibility;
            $fields = [
                [
                    'label' => 'Availability',
                    'value' => 'Missing: referenced file has not been supplied',
                    'unit' => '',
                ],
            ];
            foreach (['FILE', 'FORM', 'TITL'] as $tag) {
                foreach ($this->all($id, $tag) as $part) {
                    $fields[] = ['label' => $tag, 'value' => $this->literal($part, 2000), 'unit' => ''];
                    if ($tag === 'FILE') {
                        foreach (['FORM', 'TITL'] as $sub) {
                            $value = $this->scalar($part, $sub, 1000);
                            if ($value !== '') {
                                $fields[] = ['label' => $sub, 'value' => $value, 'unit' => ''];
                            }
                        }
                    }
                }
            }
            foreach ($this->all($id, 'NOTE') as $note) {
                [$value, $private] = $this->note($note);
                if ($private === 'management') {
                    $privacy = 'management';
                }
                $fields[] = ['label' => 'NOTE', 'value' => Values::text($value, 'value', 2000), 'unit' => ''];
            }
            if (!$this->all($id, 'FILE')) {
                $this->issue('media_file_required', $ref);
            }
            if (count($fields) > 32) {
                $this->issue('media_field_limit', $ref);
            }
            $this->detailRecord($ref, $persons, 'Missing media reference', 'custom', $privacy, $fields);
            $this->issue('missing_media', $ref, true);
        }
    }
    private function additionalFacts(int $id, array $persons, string $visibility, bool $family = false): void
    {
        $tags = $family
            ? ['ENGA', 'MARB', 'MARC', 'MARL', 'MARS', 'ANUL', 'DIVF', 'CENS', 'RESI', 'EVEN']
            : [
                'CHR',
                'BAPM',
                'BURI',
                'CREM',
                'BARM',
                'BASM',
                'BLES',
                'CHRA',
                'CONF',
                'FCOM',
                'ORDN',
                'NATU',
                'EMIG',
                'IMMI',
                'CENS',
                'PROB',
                'WILL',
                'GRAD',
                'RETI',
                'EVEN',
                'OCCU',
                'EDUC',
                'RESI',
                'NATI',
                'RELI',
                'DSCR',
                'PROP',
                'TITL',
                'FACT',
            ];
        $types = [
            'CHR' => 'baptism',
            'BAPM' => 'baptism',
            'BURI' => 'burial',
            'OCCU' => 'occupation',
            'EDUC' => 'education',
            'RESI' => 'residence',
            'EMIG' => 'migration',
            'IMMI' => 'migration',
            'CENS' => 'census',
            'PROB' => 'probate',
            'WILL' => 'will',
        ];
        foreach ($tags as $tag) {
            foreach ($this->all($id, $tag) as $event) {
                $this->record(
                    $event,
                    $persons,
                    'GEDCOM ' .
                        $tag .
                        ($this->nodes[$event]['value'] === 'N' ? ' (negated)' : ' (imported assertion)'),
                    $types[$tag] ?? 'custom',
                    $tag === 'RELI' ? 'management' : $visibility,
                );
            }
        }
        $this->mediaReferences($id, $persons, $visibility);
        foreach (['RIN', 'REFN'] as $tag) {
            foreach ($this->all($id, $tag) as $metadata) {
                $this->literal($metadata, 1000);
                $this->nodes[$metadata]['classification'] = 'STRUCTURAL';
                $this->nodes[$metadata]['reason'] = 'administrative_identifier';
                if ($tag === 'REFN') {
                    $this->scalar($metadata, 'TYPE', 1000);
                }
            }
        }
    }
    private function interpret(): void
    {
        $head = $this->roots[0];
        $this->mark($head, 'STRUCTURAL');
        if (
            count(array_filter($this->roots, fn($i) => $this->nodes[$i]['tag'] === 'HEAD')) !== 1 ||
            count(array_filter($this->roots, fn($i) => $this->nodes[$i]['tag'] === 'TRLR')) !== 1
        ) {
            $this->fail('header_trailer');
        }
        $gedc = $this->single($head, 'GEDC');
        if ($gedc === null || !in_array($this->scalar($gedc, 'VERS'), ['5.5', '5.5.1'], true)) {
            $this->fail('gedcom_version');
        }
        $this->mark($gedc, 'STRUCTURAL');
        if ($this->scalar($gedc, 'FORM') !== 'LINEAGE-LINKED') {
            $this->fail('gedcom_form');
        }
        $char = $this->scalar($head, 'CHAR');
        if (str_starts_with($this->encoding, 'UTF-16')) {
            if ($char !== 'UNICODE') {
                $this->fail('encoding_declaration');
            }
        } elseif (!in_array($char, ['UTF-8', 'ASCII'], true)) {
            $this->fail('unsupported_encoding');
        }
        if ($char === 'ASCII') {
            foreach ($this->nodes as $n) {
                if (preg_match('/[^\x00-\x7F]/', $n['value'] . $n['xref'])) {
                    $this->fail('encoding_declaration', $n['ordinal']);
                }
            }
            $this->encoding = 'ASCII';
        }
        foreach ($this->children[$head] ?? [] as $c) {
            if (
                in_array(
                    $this->nodes[$c]['tag'],
                    ['SOUR', 'DEST', 'DATE', 'FILE', 'COPR', 'LANG', 'SUBM', 'SUBN'],
                    true,
                )
            ) {
                $this->structural($c, 'header_metadata');
            }
        }
        foreach ($this->roots as $id) {
            $n = $this->nodes[$id];
            if (in_array($n['tag'], ['HEAD', 'TRLR'], true)) {
                $this->mark($id, 'STRUCTURAL');
                if ($n['xref'] !== '' || $n['value'] !== '') {
                    $this->issue('root_value', $id);
                }
                continue;
            }
            if ($n['tag'] === 'SUBM' || $n['tag'] === 'SUBN') {
                $this->structural($id, 'administrative_metadata');
                continue;
            }
            if (in_array($n['tag'], ['INDI', 'FAM', 'SOUR', 'NOTE'], true) && $n['xref'] === '') {
                $this->fail('missing_xref', $id);
            }
            if ($n['tag'] === 'INDI') {
                $this->person($id);
            }
            if ($n['tag'] === 'SOUR') {
                $this->source($id);
            }
        }
        if (!$this->plan['people']) {
            $this->issue('no_people');
        }
        foreach ($this->roots as $id) {
            if ($this->nodes[$id]['tag'] === 'FAM') {
                $this->family($id);
            }
        }
        // Individual-side memberships may only corroborate explicit family-side membership.
        $contexts = array_column($this->plan['contexts'], null, 'xref');
        foreach ($this->plan['people'] as $xref => $p) {
            foreach (['FAMC' => 'children', 'FAMS' => 'parents'] as $tag => $group) {
                $seen = [];
                foreach ($this->all($p['line'], $tag) as $ref) {
                    $family = $this->pointer($ref, 'FAM');
                    if (isset($seen[$family])) {
                        $this->issue('duplicate_family_reference', $ref);
                    }
                    $seen[$family] = true;
                    $context = $contexts[$family] ?? null;
                    if (!$context || !in_array($xref, $context[$group], true)) {
                        $this->issue('unmatched_family_membership', $ref);
                    }
                }
            }
        }
        $edges = [];
        foreach ($this->plan['relations'] as $r) {
            if ($r['kind'] === 'parent') {
                $edges[$r['source']][] = $r['target'];
            }
        }
        $done = [];
        $active = [];
        $visit = function (string $p) use (&$visit, &$done, &$active, $edges): void {
            if (isset($active[$p])) {
                $this->fail('parent_cycle');
            }
            if (isset($done[$p])) {
                return;
            }
            $active[$p] = true;
            foreach ($edges[$p] ?? [] as $child) {
                $visit($child);
            }
            unset($active[$p]);
            $done[$p] = true;
        };
        foreach (array_keys($this->plan['people']) as $p) {
            $visit($p);
        }
        foreach ($this->nodes as $id => $n) {
            if ($this->nodes[$id]['classification'] !== 'UNMAPPED') {
                continue;
            }
            if (str_starts_with($n['tag'], '_')) {
                $this->structural($id, 'uninterpreted_vendor_extension');
                $this->issue('vendor_extension', $id, true);
            } elseif ($n['tag'] === 'NOTE' && $n['level'] === 0) {
                // An unreferenced standalone note is retained, never promoted to evidence.
                if ($this->legacy) {
                    $this->structural($id, 'unreferenced_note');
                } else {
                    $this->structuralText($id);
                    $this->restriction($id);
                    foreach ($this->all($id, 'RESN') as $restriction) {
                        $this->structuralText($restriction);
                    }
                }
                $this->issue('orphan_note', $id, true);
            } else {
                $this->issue('unsupported_standard_field', $id);
            }
        }
    }
    private function person(int $id): void
    {
        $this->mark($id);
        $n = $this->nodes[$id];
        if ($n['value'] !== '') {
            $this->issue('root_value', $id);
        }
        $privacy = $this->restriction($id);
        $name = $this->single($id, 'NAME');
        $given = '';
        $family = '';
        $alternativeName = null;
        if ($name !== null) {
            $v = $this->literal($name, 500);
            if (
                preg_match('/^(.*?)\/([^\/]*)\/(.*?)$/Du', $v, $m) &&
                ($this->extended || trim($m[3]) === '')
            ) {
                $given = trim($m[1]);
                $family = trim($m[2]);
            } elseif (!str_contains($v, '/')) {
                $given = $v;
            } else {
                $this->issue('name_structure', $name);
            }
            $literalName = ['givenName' => $given, 'familyName' => $family];
            foreach (['GIVN' => 'given', 'SURN' => 'family'] as $tag => $key) {
                $value = $this->scalar($name, $tag, 200);
                if ($value !== '' && $$key !== '' && $value !== $$key) {
                    $this->issue('conflicting_name_components', $name, $this->extended);
                    if ($this->extended) {
                        $alternativeName = $literalName;
                    }
                }
                if ($value !== '') {
                    $$key = $value;
                }
            }
            $type = $this->scalar($name, 'TYPE');
            if ($type !== '' && strtolower($type) !== 'birth') {
                $this->issue('unsupported_name_type', $name);
            }
            if ($this->extended) {
                $fields = [['label' => 'NAME', 'value' => $v, 'unit' => '']];
                foreach (['NPFX', 'NSFX', 'SPFX', 'NICK'] as $tag) {
                    $value = $this->scalar($name, $tag, 200);
                    if ($value !== '') {
                        $fields[] = ['label' => $tag, 'value' => $value, 'unit' => ''];
                    }
                }
                if ($alternativeName !== null) {
                    foreach (['GIVN' => $given, 'SURN' => $family] as $tag => $value) {
                        $fields[] = ['label' => $tag, 'value' => $value, 'unit' => ''];
                    }
                }
                if (count($fields) > 1 || (isset($m[3]) && trim($m[3]) !== '')) {
                    $this->detailRecord(
                        $name,
                        [$n['xref']],
                        'Imported name components',
                        'name_components',
                        $privacy,
                        $fields,
                    );
                }
            }
            $this->citations($name, 'claim:' . $n['xref'] . ':name', $privacy);
            if ($given === '' && $family === '' && $this->all($name, 'SOUR')) {
                $this->issue('empty_name_citation', $name);
            }
        }
        $sex = $this->scalar($id, 'SEX');
        if (!in_array($sex, ['', 'M', 'F', 'U'], true)) {
            $this->issue('sex_value', $id);
        }
        $p = [
            'givenName' => $given,
            'familyName' => $family,
            'confirmUnnamed' => true,
            'visibility' => $privacy,
            'sex' => ['M' => 'male', 'F' => 'female'][$sex] ?? 'unknown',
            'livingStatus' => 'unknown',
        ];
        $claimLines = ['name' => $name ?? $id];
        foreach (['BIRT' => 'birth', 'DEAT' => 'death'] as $tag => $field) {
            $event = $this->single($id, $tag);
            if ($event === null) {
                continue;
            }
            // Restricted vital fields make the whole person private in this bounded profile.
            if ($this->restriction($event) === 'management') {
                $p['visibility'] = 'management';
            }
            $p[$field . 'Date'] = $this->date($this->single($event, 'DATE'));
            $p[$field . 'Place'] = $this->scalar($event, 'PLAC', 500);
            $claimLines[$field . '_date'] = $this->single($event, 'DATE') ?? $event;
            $claimLines[$field . '_place'] = $this->single($event, 'PLAC') ?? $event;
            if ($tag === 'DEAT') {
                $p['livingStatus'] = 'deceased';
            }
            $this->record(
                $event,
                [$n['xref']],
                $tag === 'BIRT' ? 'Birth (imported assertion)' : 'Death (imported assertion)',
                'custom',
                $p['visibility'],
            );
        }
        $this->plan['people'][$n['xref']] = [
            'line' => $id,
            'nameLine' => $name,
            'claimLines' => $claimLines,
            'data' => Values::person($p),
        ];
        if ($this->extended && $alternativeName !== null) {
            $this->plan['people'][$n['xref']]['alternativeNames'] = [$alternativeName];
        }
        foreach ($this->all($id, 'NOTE') as $note) {
            $this->record($note, [$n['xref']], 'Imported note', 'note', $p['visibility'], true);
        }
        $this->citations($id, 'person:' . $n['xref'], $p['visibility']);
        if ($this->extended) {
            $this->additionalFacts($id, [$n['xref']], $p['visibility']);
        }
    }
    private function source(int $id): void
    {
        $this->mark($id);
        if ($this->nodes[$id]['value'] !== '') {
            $this->issue('root_value', $id);
        }
        $title = $this->scalar($id, 'TITL', 250);
        if ($title === '') {
            $this->issue('source_title_required', $id);
        }
        $this->plan['sources'][$this->nodes[$id]['xref']] = [
            'line' => $id,
            'visibility' => $this->restriction($id),
            'data' => [
                'title' => $title,
                'author' => $this->scalar($id, 'AUTH', 250),
                'publication' => $this->scalar($id, 'PUBL', 1000),
                'description' => $this->scalar($id, 'TEXT', 4000),
                'type' => '',
                'repository' => '',
                'callNumber' => '',
                'url' => '',
            ],
        ];
    }
    private function family(int $id): void
    {
        $this->mark($id);
        $n = $this->nodes[$id];
        if ($n['value'] !== '') {
            $this->issue('root_value', $id);
        }
        $parents = [];
        $children = [];
        foreach (['HUSB', 'WIFE'] as $tag) {
            $this->single($id, $tag);
        }
        foreach ($this->children[$id] ?? [] as $ref) {
            if (in_array($this->nodes[$ref]['tag'], ['HUSB', 'WIFE'], true)) {
                $parents[] = $this->pointer($ref, 'INDI');
            }
        }
        foreach ($this->all($id, 'CHIL') as $ref) {
            $children[] = $this->pointer($ref, 'INDI');
        }
        if (count([...$parents, ...$children]) !== count(array_unique([...$parents, ...$children]))) {
            $this->issue('duplicate_family_member', $id);
        }
        if (count([...$parents, ...$children]) > 400) {
            $this->issue('family_member_limit', $id);
        }
        if (!$parents && !$children) {
            $this->issue('empty_family_context', $id);
        }
        $privacy = $this->restriction($id);
        $relations = [];
        foreach ($children as $child) {
            $p = $this->plan['people'][$child];
            $type = 'unknown';
            $matches = [];
            foreach ($this->all($p['line'], 'FAMC') as $ref) {
                if ($this->pointer($ref, 'FAM') === $n['xref']) {
                    $matches[] = $ref;
                }
            }
            if (count($matches) > 1) {
                $this->issue('duplicate_family_reference', $matches[1]);
            }
            if ($matches) {
                $pedi = strtolower($this->scalar($matches[0], 'PEDI'));
                if (!in_array($pedi, ['', 'birth', 'adopted', 'foster'], true)) {
                    $this->issue('unsupported_pedigree', $matches[0]);
                }
                $type =
                    ['birth' => 'biological', 'adopted' => 'adoptive', 'foster' => 'foster'][$pedi] ??
                    'unknown';
            }
            foreach ($parents as $parent) {
                $key = $parent . '|' . $child . '|parent';
                if (
                    isset($this->plan['relations'][$key]) &&
                    $this->plan['relations'][$key]['parentType'] !== $type
                ) {
                    $this->issue('conflicting_parent_type', $id);
                }
                $this->plan['relations'][$key] = [
                    'line' => $id,
                    'source' => $parent,
                    'target' => $child,
                    'kind' => 'parent',
                    'parentType' => $type,
                ];
                $relations[] = $key;
            }
        }
        if ($this->nativePartner) {
            $marker = $this->single($id, '_STB_PARTNER');
            if ($marker !== null) {
                if (
                    $this->literal($marker, 1) !== 'Y' ||
                    count($parents) !== 2 ||
                    $this->all($id, 'MARR') ||
                    $this->all($id, 'DIV')
                ) {
                    $this->issue('native_partner_marker', $marker);
                } else {
                    $pair = $parents;
                    sort($pair);
                    $key = implode('|', $pair) . '|partner|' . $id;
                    $this->plan['relations'][$key] = [
                        'line' => $marker,
                        'source' => $pair[0],
                        'target' => $pair[1],
                        'kind' => 'partner',
                        'parentType' => 'unknown',
                    ];
                    $relations[] = $key;
                }
            }
        }
        foreach (['MARR' => 'marriage', 'DIV' => 'divorce'] as $tag => $type) {
            $event = $this->single($id, $tag);
            if ($event === null) {
                continue;
            }
            if (count($parents) !== 2) {
                $this->issue('family_event_needs_two_participants', $event);
                continue;
            }
            $this->record($event, $parents, ucfirst($type) . ' (imported assertion)', $type, $privacy);
            if (end($this->plan['records'])['visibility'] === 'management') {
                $privacy = 'management';
            }
            $pair = $parents;
            sort($pair);
            // Separate FAM records may represent separate partnership episodes.
            $key = implode('|', $pair) . '|partner|' . $id;
            $this->plan['relations'][$key] = [
                'line' => $event,
                'source' => $pair[0],
                'target' => $pair[1],
                'kind' => 'partner',
                'parentType' => 'unknown',
            ];
            $relations[] = $key;
        }
        // Context visibility protects its records, but parent edges have no separate visibility.
        // Therefore private family membership conservatively restricts every participant.
        if ($privacy === 'management') {
            foreach ([...$parents, ...$children] as $p) {
                $this->plan['people'][$p]['data']['visibility'] = 'management';
            }
        }
        $this->plan['contexts'][] = [
            'line' => $id,
            'xref' => $n['xref'],
            'parents' => $parents,
            'children' => $children,
            'visibility' => $privacy,
            'relations' => array_values(array_unique($relations)),
        ];
        $this->citations($id, 'context:' . $id, $privacy);
        if ($this->extended) {
            $this->additionalFacts(
                $id,
                array_values(array_unique([...$parents, ...$children])),
                $privacy,
                true,
            );
        }
    }
}
