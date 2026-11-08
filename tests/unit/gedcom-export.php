<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/Service/GedcomExport.php';
use OCA\Stammbaum\Service\GedcomExport;
function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
check(GedcomExport::date(['kind' => 'about', 'value' => '1900-02']) === 'ABT FEB 1900', 'partial date');
check(
    GedcomExport::date(['kind' => 'between', 'value' => '1900', 'end' => '1902']) === 'BET 1900 AND 1902',
    'range',
);
$export = (new ReflectionClass(GedcomExport::class))->newInstanceWithoutConstructor();
$empty = ['kind' => 'exact', 'value' => '', 'end' => ''];
$p = [
    'id' => 'p',
    'givenName' => 'Current @Alma',
    'familyName' => 'Example',
    'sex' => 'other',
    'livingStatus' => 'deceased',
    'birthDate' => ['kind' => 'exact', 'value' => '1900', 'end' => ''],
    'birthPlace' => 'Old place',
    'deathDate' => $empty,
    'deathPlace' => '',
    'updatedAt' => 10,
];
$x = [
    'people' => ['p' => $p],
    'records' => [
        'r' => [
            'id' => 'r',
            'originId' => 'p',
            'vitalKind' => 'birth',
            'date' => ['kind' => 'exact', 'value' => '1901', 'end' => ''],
            'place' => 'New place',
            'updatedAt' => 11,
        ],
    ],
    'media' => [],
    'relations' => [],
    'claims' => [],
    'sources' => [],
    'citations' => [],
];
$r = $export->render($x);
check(substr_count($r['gedcom'], '1 BIRT') === 1, 'one vital event');
check(str_contains($r['gedcom'], '1901'), 'newer assertion');
check(!str_contains($r['gedcom'], 'Old place'), 'stale aggregate omitted');
check(str_contains($r['gedcom'], 'Current @@Alma'), 'at escaped');
check(str_contains($r['gedcom'], '1 SEX U'), 'sex normalization');
check(str_contains($r['gedcom'], '1 DEAT'), 'deceased assertion');
$x['people']['p']['updatedAt'] = 12;
$r = $export->render($x);
check(str_contains($r['gedcom'], '1900'), 'new aggregate');
check(!str_contains($r['gedcom'], '1901'), 'stale statement omitted');
echo "GEDCOM export date, escaping and vital precedence checks passed.\n";
