<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/Service/Problem.php';
require __DIR__ . '/../../lib/Service/Values.php';
require __DIR__ . '/../../lib/Service/Store.php';
require __DIR__ . '/../../lib/Service/Gedcom.php';
use OCA\Stammbaum\Service\Gedcom;
function check(bool $v, string $message): void
{
    if (!$v) {
        throw new RuntimeException($message);
    }
}
$parser = new Gedcom();
$bytes = file_get_contents(__DIR__ . '/../fixtures/family.ged');
$r = $parser->analyze($bytes);
check($r['report']['errors'] === 0, json_encode($r['report']));
check(count($r['plan']['people']) === 3, 'three people');
check(count($r['plan']['relations']) === 3, 'two parent edges and one explicit partnership');
check($r['plan']['people']['@I1@']['data']['sex'] === 'female', 'HUSB never infers sex');
check($r['plan']['people']['@I2@']['data']['sex'] === 'unknown', 'WIFE never infers sex');
check($r['plan']['people']['@I1@']['data']['birthDate']['kind'] === 'about', 'partial approximate date');
check($r['plan']['relations']['@I1@|@I3@|parent']['parentType'] === 'adoptive', 'explicit pedigree');
check(
    $r['plan']['records'][1]['data']['text'] ===
        "First line with an escaped @ sign.\nSecond line, continued.",
    'continuations and literal escaping',
);
check($r['plan']['citations'][0]['data']['reliability'] === 'unassessed', 'QUAY not promoted to reliability');
check($r['plan']['citations'][0]['data']['documentDate']['value'] === '1841', 'separate document date');
check($r['planHash'] === $parser->analyze($bytes)['planHash'], 'determinism across reuse');
foreach (['UTF-16LE' => "\xff\xfe", 'UTF-16BE' => "\xfe\xff"] as $encoding => $bom) {
    $input =
        $bom . mb_convert_encoding(str_replace('1 CHAR UTF-8', '1 CHAR UNICODE', $bytes), $encoding, 'UTF-8');
    $result = $parser->analyze($input);
    check($result['report']['errors'] === 0, 'UTF16 support');
    check(
        $result['nodes'][1]['byteOffset'] === 2 + strlen(mb_convert_encoding("0 HEAD\n", $encoding, 'UTF-8')),
        'original byte offsets',
    );
}
foreach (
    [
        str_replace('1 SEX F', '1 OCCU Teacher', $bytes),
        str_replace('2 PLAC Example village', '2 PLAC Example village' . "\n" . '3 MAP', $bytes),
        str_replace('@I3@ INDI', '@I1@ INDI', $bytes),
        str_replace('1 CHIL @I3@', '1 CHIL @MISSING@', $bytes),
        str_replace('1 CHAR UTF-8', '1 CHAR ANSEL', $bytes),
        str_replace('2 VERS 5.5.1', '2 VERS 7.0', $bytes),
        str_replace('2 DATE ABT 1840', '4 DATE ABT 1840', $bytes),
        str_replace('1 CHAR UTF-8', '1 CHAR UTF-8' . "\xff", $bytes),
        str_replace('1 NAME Ada /Linden/', '1 NAME Ada /Linden/' . "\n" . '1 NAME Alice /Linden/', $bytes),
        str_replace('2 GIVN Ada', '2 GIVN Other', $bytes),
        str_replace('1 CHIL @I3@', '1 CHIL @I1@', $bytes),
    ]
    as $i => $invalid
) {
    check($parser->analyze($invalid)['report']['errors'] > 0, 'blocked input ' . $i);
}
$vendor = $parser->analyze(
    str_replace('1 SEX F', "1 _CUSTOM preserved\n2 TEXT extension content\n1 SEX F", $bytes),
);
check(
    $vendor['report']['errors'] === 0 && $vendor['report']['warnings'] === 1,
    'vendor subtree preserved with warning',
);
$private = $parser->analyze(str_replace('0 @F1@ FAM', "0 @F1@ FAM\n1 RESN privacy", $bytes));
foreach ($private['plan']['people'] as $p) {
    check($p['data']['visibility'] === 'management', 'private context cannot leak via edges');
}
$noMarriage = preg_replace('/1 MARR\n2 DATE BET 1860 AND 1864\n2 PLAC Example town\n/', '', $bytes);
check(count($parser->analyze($noMarriage)['plan']['relations']) === 2, 'co-parents do not imply partnership');
$many = str_replace('1 SEX F', str_repeat("1 OCCU unsupported\n", 220) . '1 SEX F', $bytes);
$report = $parser->analyze($many)['report'];
check(
    $report['errors'] === 220 && count($report['issues']) === 200,
    'bounded diagnostics with complete totals',
);
echo "GEDCOM parser semantics, preservation, encoding and rejection tests passed.\n";

$head = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n";
$simple = $head . "0 @I1@ INDI\n1 NAME Test /Example/\n0 TRLR\n";
$people = $head;
for ($i = 1; $i <= 2000; $i++) {
    $people .= "0 @I$i@ INDI\n1 NAME Person$i /Example/\n";
}
check($parser->analyze($people . "0 TRLR\n")['report']['errors'] === 0, '2000 people accepted');
check(
    $parser->analyze($people . "0 @I2001@ INDI\n1 NAME Extra\n0 TRLR\n")['report']['issues'][0]['code'] ===
        'person_limit',
    'person limit',
);
check(
    $parser->analyze(str_repeat('x', 8388609))['report']['issues'][0]['code'] === 'file_size',
    '8 MiB boundary',
);
check(
    $parser->analyze($head . str_repeat("0 _META\n", 100001) . "0 TRLR\n")['report']['issues'][0]['code'] ===
        'line_limit',
    '100000 line boundary',
);
$long = str_replace(
    '1 NAME Test /Example/',
    '1 _V ' . str_repeat('x', 16379) . "\n1 NAME Test /Example/",
    $simple,
);
check($parser->analyze($long)['report']['errors'] === 0, '16384 character line accepted');
check(
    $parser->analyze(str_replace(str_repeat('x', 16379), str_repeat('x', 16380), $long))['report'][
        'issues'
    ][0]['code'] === 'line_length',
    'line character bound',
);
$deep = "1 _ROOT\n";
for ($i = 2; $i <= 64; $i++) {
    $deep .= "$i _CHILD\n";
}
check(
    $parser->analyze(str_replace('1 NAME Test /Example/', $deep . '1 NAME Test /Example/', $simple))[
        'report'
    ]['errors'] === 0,
    'depth 64 accepted',
);
check(
    $parser->analyze(
        str_replace('1 NAME Test /Example/', $deep . "65 _CHILD\n1 NAME Test /Example/", $simple),
    )['report']['issues'][0]['code'] === 'level_jump',
    'depth 65 rejected',
);
// Keep byte input under 8 MiB while exceeding repeated interpretation work.
$budget = str_replace(
    '1 NAME Test /Example/',
    str_repeat('1 _META ' . str_repeat('x', 256) . "\n", 25001) . '1 NAME Test /Example/',
    $simple,
);
check(
    $parser->analyze($budget)['report']['issues'][0]['code'] === 'interpretation_budget',
    '50000 interpretation unit boundary',
);
$crlf = "\xef\xbb\xbf" . str_replace("\n", "\r\n", $simple);
$nodes = $parser->analyze($crlf)['nodes'];
check($nodes[0]['byteOffset'] === 3 && $nodes[1]['byteOffset'] === 11, 'UTF8 BOM and CRLF byte offsets');
$cycle = str_replace('1 FAMS @F1@', "1 FAMS @F1@\n1 FAMC @F2@", $bytes);
$cycle = str_replace('0 @S1@ SOUR', "0 @F2@ FAM\n1 HUSB @I3@\n1 CHIL @I1@\n1 CHIL @I2@\n0 @S1@ SOUR", $cycle);
check(
    in_array('parent_cycle', array_column($parser->analyze($cycle)['report']['issues'], 'code'), true),
    'parent cycle blocks',
);
$privateEvent = $parser->analyze(str_replace('1 MARR', "1 MARR\n2 RESN privacy", $bytes));
check(
    $privateEvent['plan']['people']['@I1@']['data']['visibility'] === 'management',
    'private marriage cannot expose a partner edge',
);
echo "GEDCOM resource boundaries and restricted-event projection passed.\n";
$reordered = $parser->analyze(str_replace("1 HUSB @I1@\n1 WIFE @I2@", "1 WIFE @I2@\n1 HUSB @I1@", $bytes));
check($reordered['plan']['contexts'][0]['parents'] === ['@I2@', '@I1@'], 'recorded parent order');
$calendarBytes = str_replace('ABT 1840', '@#DJULIAN@ 12 FEB 1840', $bytes);
$calendar = $parser->analyze($calendarBytes);
check(
    $calendar['report']['errors'] === 0 && $calendar['report']['warnings'] > 0,
    'calendar escapes remain literal with warnings',
);
check(
    $calendar['plan']['people']['@I1@']['data']['birthDate'] === [
        'kind' => 'literal',
        'value' => '@#DJULIAN@ 12 FEB 1840',
        'end' => '',
    ],
    'calendar wording is unchanged',
);
$orphan = str_replace('0 TRLR', "0 @N1@ NOTE Unlinked wording\n1 CONT Second line\n0 TRLR", $bytes);
check($parser->analyze($orphan)['report']['errors'] === 0, 'orphan text is preserved');
$orphanBytes = str_replace(
    '0 TRLR',
    "0 @N1@ NOTE Unlinked wording\n1 OCCU unsupported standard field\n0 TRLR",
    $bytes,
);
$unsupportedOrphan = $parser->analyze($orphanBytes);
check(
    $unsupportedOrphan['report']['errors'] === 1 && $unsupportedOrphan['report']['warnings'] === 1,
    'orphan note does not hide unsupported standard fields',
);
// Captured from the deployed alpha.8 binary, before adding alpha.2 interpretation.
foreach (
    [
        [$bytes, 'f8407f2363fcaff18d772d9506a4b3982f0071430901dac18a5d1737aa25048e'],
        [$calendarBytes, '0d9d8db78874c099da112ca2c404690cb06a0684b5470e9735b6a70b51c053a9'],
        [$orphanBytes, '38fb07cfca6abd3a526a708260d9589ab742058f13a330a3ab8062a08d9a6f83'],
    ]
    as [$input, $hash]
) {
    check(
        $parser->analyze($input, 'nc-gedcom-551-alpha1')['planHash'] === $hash,
        'old profile remains exactly reproducible',
    );
}
echo "Calendar/orphan-note diagnostics and exact alpha.1 compatibility passed.\n";
check(
    $parser->analyze(str_replace('ABT 1840', 'ABT @#DJULIAN@ 12 FEB 1840', $bytes))['report']['errors'] === 0,
    'calendar escapes inside approximated wording stay literal',
);
