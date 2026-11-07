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
        str_replace('1 SEX F', '1 LDS_UNSUPPORTED Teacher', $bytes),
        str_replace('2 PLAC Example village', '2 PLAC Example village' . "\n" . '3 MAP', $bytes),
        str_replace('@I3@ INDI', '@I1@ INDI', $bytes),
        str_replace('1 CHIL @I3@', '1 CHIL @MISSING@', $bytes),
        str_replace('1 CHAR UTF-8', '1 CHAR ANSEL', $bytes),
        str_replace('2 VERS 5.5.1', '2 VERS 7.0', $bytes),
        str_replace('2 DATE ABT 1840', '4 DATE ABT 1840', $bytes),
        str_replace('1 CHAR UTF-8', '1 CHAR UTF-8' . "\xff", $bytes),
        str_replace('1 NAME Ada /Linden/', '1 NAME Ada /Linden/' . "\n" . '1 NAME Alice /Linden/', $bytes),
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
$many = str_replace('1 SEX F', str_repeat("1 ADOP unsupported\n", 220) . '1 SEX F', $bytes);
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

foreach (['5.5', '5.5.1'] as $version) {
    $ascii = str_replace(['VERS 5.5.1', 'CHAR UTF-8'], ['VERS ' . $version, 'CHAR ASCII'], $simple);
    $result = $parser->analyze($ascii);
    check(
        $result['report']['errors'] === 0 && $result['encoding'] === 'ASCII',
        'declared ASCII profile ' . $version,
    );
}
foreach (
    [
        str_replace('CHAR UTF-8', 'CHAR WINDOWS-1252', $simple),
        str_replace(['CHAR UTF-8', 'Test'], ['CHAR ASCII', 'Tést'], $simple),
        "\xff\xfe" . mb_convert_encoding($simple, 'UTF-16LE', 'UTF-8'),
        str_replace('CHAR UTF-8', 'CHAR UNICODE', $simple),
        "PK\x03\x04" . $simple,
    ]
    as $invalid
) {
    check($parser->analyze($invalid)['report']['errors'] > 0, 'invalid encoding declaration or ZIP rejected');
}
// Exercise the inclusive byte limit without exceeding line, node or interpretation bounds.
$remaining = Gedcom::MAX_BYTES - strlen($simple);
$padding = '';
while ($remaining > 0) {
    $length = min(16000, $remaining);
    if ($remaining > $length && $remaining - $length < 9) {
        $length -= 9;
    }
    $padding .= '0 _META ' . str_repeat('x', $length - 9) . "\n";
    $remaining -= $length;
}
$atLimit = str_replace('0 TRLR', $padding . '0 TRLR', $simple);
check(strlen($atLimit) === Gedcom::MAX_BYTES, 'exact 8 MiB fixture');
check($parser->analyze($atLimit)['report']['errors'] === 0, 'exact 8 MiB accepted');
check(
    $parser->analyze($atLimit . "\n")['report']['issues'][0]['code'] === 'file_size',
    '8 MiB plus one byte rejected',
);
echo "Declared encodings, both supported versions and inclusive byte limit passed.\n";

// Captured from the deployed alpha.15 parser before introducing alpha.3.
foreach (
    [
        [$bytes, '6dfaecc9f978ba8c988c45e4efbc0d0935b1a7d4ca7a4e6a59bb917386e234ac'],
        [$calendarBytes, '22026493c2611547915dd45b2c58704641d39bf68a534c2f939d52772fc23c40'],
        [$orphanBytes, '2a8796d49ae3ba47b2085e5cc3d9d375299616f2b73f77dd1a9a23022a63ce95'],
        [
            str_replace('1 SEX F', "1 OCCU Teacher\n2 DATE 1900", $bytes),
            '0817b3778488a7462f504c45d77380a15ce87a00228781a7d118e659c839b060',
        ],
    ]
    as [$input, $hash]
) {
    check(
        $parser->analyze($input, 'nc-gedcom-551-alpha2')['planHash'] === $hash,
        'alpha.2 stored plans remain identical',
    );
}
$extended = file_get_contents(__DIR__ . '/../fixtures/extended-family.ged');
$r = $parser->analyze($extended);
check($r['report']['complete'] && $r['report']['errors'] === 0, 'extended profile activates');
check(
    $parser->analyze($extended, 'nc-gedcom-551-alpha2')['report']['errors'] > 0,
    'previous profile stays closed',
);
check(
    count($r['plan']['people']['@I1@']['alternativeNames']) === 1,
    'different literal name retained as alternative',
);
check(
    $r['plan']['people']['@I1@']['data']['givenName'] === 'Ada',
    'explicit components drive provisional display',
);
check(
    $r['plan']['people']['@I1@']['alternativeNames'][0]['givenName'] === 'Adelaide',
    'literal name not lost',
);
check(count($r['plan']['relations']) === 1, 'marriage license does not imply partner edge');
check($r['plan']['people']['@I1@']['data']['deathDate']['value'] === '', 'burial never invents death date');
$records = $r['plan']['records'];
check(
    count(array_filter($records, fn($v) => $v['type'] === 'occupation')) === 2,
    'repeated occupations separate',
);
foreach ($records as $v) {
    if ($v['type'] === 'residence' || str_contains($v['data']['title'], 'RELI')) {
        check($v['visibility'] === 'management', 'contacts and religion confidential');
    }
}
check($r['report']['issueCounts']['warning:missing_media'] === 1, 'missing media visibly reported');
check(array_sum($r['report']['issueCounts']) === $r['report']['warnings'], 'complete grouped diagnostics');
check(
    str_contains($r['plan']['citations'][0]['data']['reasoning'], 'GEDCOM ROLE: Witness'),
    'citation context retained without inference',
);
foreach (
    [
        str_replace('2 TYPE Residence wording', '2 TYPE Residence wording\n2 TYPE Duplicate', $extended),
        str_replace('2 PLAC Example cemetery', '2 PLAC Example cemetery\n3 MAP Unsupported', $extended),
        str_replace('2 FORM jpg', '2 FORM jpg\n3 MEDI unsupported', $extended),
        str_replace('1 BURI', '1 FACT', $extended),
    ]
    as $invalid
) {
    check(
        $parser->analyze(str_replace('\\n', "\n", $invalid))['report']['errors'] > 0,
        'unsupported children and singleton conflicts block',
    );
}
echo "Extended semantic mappings, privacy and alpha.2 compatibility passed.\n";

$literalAttribute = $parser->analyze(str_replace('1 OCCU Teacher', '1 OCCU Y', $extended));
check(
    count(
        array_filter(
            $literalAttribute['plan']['records'],
            fn($r) => $r['type'] === 'occupation' && $r['data']['text'] === 'Y',
        ),
    ) === 1,
    'attribute Y remains literal wording rather than an event marker',
);
