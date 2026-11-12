<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/Service/Problem.php';
require __DIR__ . '/../../lib/Service/CalendarAppointments.php';

$class = \OCA\Stammbaum\Service\CalendarAppointments::class;
$ics = new ReflectionMethod($class, 'ics');
$next = new ReflectionMethod($class, 'nextAnniversary');
$date = new ReflectionMethod($class, 'date');
$timedStart = new ReflectionMethod($class, 'timedStart');
$id = '11111111-2222-4333-8444-555555555555';
$base = [
    'date' => '2028-02-29',
    'time' => '',
    'timezone' => '',
    'title' => str_repeat('Müller, ', 30) . 'Quelle; Foto',
    'location' => "Berlin\nArchiv",
    'mode' => 'anniversary',
];
$text = $ics->invoke(null, $id, $base, 'https://example.invalid/#person=fictional');
assert(str_contains($text, 'BEGIN:VEVENT'));
assert(str_contains($text, 'UID:stb-event-' . $id . '@stammbaum.nextcloud'));
assert(str_contains($text, 'DTSTART;VALUE=DATE:20280229'));
assert(str_contains($text, 'DTEND;VALUE=DATE:20280301'));
assert(str_contains($text, 'RRULE:FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=29'));
assert(!str_contains($text, 'ATTENDEE:'));
foreach (explode("\r\n", trim($text)) as $line) {
    assert(strlen($line) <= 75, 'iCalendar line exceeds 75 octets');
    assert(mb_check_encoding($line, 'UTF-8'), 'iCalendar fold split a UTF-8 character');
}
$unfolded = str_replace("\r\n ", '', $text);
assert(str_contains($unfolded, 'LOCATION:Berlin\\nArchiv'));
assert(str_contains($unfolded, 'SUMMARY:' . str_repeat('Müller\\, ', 30) . 'Quelle\\; Foto'));
$timed = $ics->invoke(
    null,
    $id,
    array_merge($base, [
        'date' => '2026-11-12',
        'time' => '16:30',
        'timezone' => 'Europe/Berlin',
        'mode' => 'appointment',
    ]),
    'https://example.invalid/',
);
assert(str_contains($timed, 'DTSTART:20261112T153000Z'));
assert(str_contains($timed, 'DTEND:20261112T163000Z'));
assert(!str_contains($timed, 'RRULE:'));
foreach ([['2026-03-29', '02:30'], ['2026-10-25', '02:30']] as [$day, $hour]) {
    try {
        $timedStart->invoke(null, $day, $hour, 'Europe/Berlin');
        throw new RuntimeException('Nonexistent or ambiguous local time accepted');
    } catch (\OCA\Stammbaum\Service\Problem $e) {
        assert($e->errorCode === 'invalid_date');
    }
}
assert(substr($next->invoke(null, '2000-02-29', 'Europe/Berlin'), 5) === '02-29');
try {
    $date->invoke(null, '2026-02-29');
    throw new RuntimeException('Invalid date accepted');
} catch (\OCA\Stammbaum\Service\Problem $e) {
    assert($e->errorCode === 'invalid_date');
}
echo "Calendar VEVENT escaping, leap recurrence and timezone serialization passed.\n";
