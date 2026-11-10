<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/Service/ResearchTasks.php';

$method = new ReflectionMethod(\OCA\Stammbaum\Service\ResearchTasks::class, 'ics');
$id = '11111111-2222-4333-8444-555555555555';
$title = str_repeat('Müller, ', 30) . 'Quelle; Foto';
$text = "Zeile eins\nZeile zwei\\drei";
$ics = $method->invoke(null, $id, $title, $text, '2026-11-10');
assert(str_contains($ics, 'BEGIN:VTODO'));
assert(str_contains($ics, 'UID:stb-' . $id . '@stammbaum.nextcloud'));
assert(str_contains($ics, 'DUE;VALUE=DATE:20261110'));
foreach (explode("\r\n", trim($ics)) as $line) {
    assert(strlen($line) <= 75, 'iCalendar line exceeds 75 octets');
    assert(mb_check_encoding($line, 'UTF-8'), 'iCalendar fold split a UTF-8 character');
}
$unfolded = str_replace("\r\n ", '', $ics);
assert(str_contains($unfolded, 'SUMMARY:' . str_repeat('Müller\\, ', 30) . 'Quelle\\; Foto'));
assert(str_contains($unfolded, 'DESCRIPTION:Zeile eins\\nZeile zwei\\\\drei'));
echo "Research task iCalendar UTF-8 folding, escaping and due date passed.\n";
