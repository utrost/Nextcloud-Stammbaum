<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCP\App\IAppManager;
use OCP\Calendar\{ICalendar, ICreateFromString, IManager};
use OCP\Constants;
use OCP\IURLGenerator;
use OCP\IUserManager;

/** Explicit, account-owned VEVENT publication through Nextcloud's public calendar interface. */
final class CalendarAppointments
{
    public function __construct(
        private Store $store,
        private Trees $trees,
        private People $people,
        private IManager $calendars,
        private IAppManager $apps,
        private IUserManager $users,
        private IURLGenerator $urls,
        private ContactLinks $contacts,
    ) {}

    private function source(
        string $tree,
        string $actor,
        string $person,
        bool $includeLiving,
        bool $includeManagement,
    ): array {
        $m = $this->trees->access($tree, $actor);
        if (!Trees::management($m) && !(bool) $m['export_allowed']) {
            throw new Problem('forbidden', 'Export permission is required to copy genealogy details.', 403);
        }
        $row = $this->people->row($tree, $person, $m);
        $p = $this->people->dto($row);
        if ($p['livingStatus'] !== 'deceased' && !$includeLiving) {
            throw new Problem(
                'review_required',
                'Review inclusion of a living person or unknown living status.',
            );
        }
        if ($p['visibility'] === 'management' && !$includeManagement) {
            throw new Problem('review_required', 'Review inclusion of a management-only person.');
        }
        return [$m, $row, $p];
    }

    private function calendar(string $actor, string $uri): ICalendar
    {
        if (!preg_match('/\A[a-zA-Z0-9_.-]{1,160}\z/D', $uri) || str_starts_with($uri, 'contact_birthdays')) {
            throw Problem::missing();
        }
        $user = $this->users->get($actor);
        if (!$user || !$this->apps->isEnabledForUser('calendar', $user)) {
            throw new Problem('unavailable', 'Nextcloud Calendar is not available for this account.', 409);
        }
        foreach ($this->calendars->getCalendarsForPrincipal('principals/users/' . $actor, [$uri]) as $c) {
            if (
                $c->getUri() === $uri &&
                !$c->isDeleted() &&
                $c instanceof ICreateFromString &&
                $c->getPermissions() & Constants::PERMISSION_CREATE
            ) {
                return $c;
            }
        }
        throw Problem::missing();
    }

    public function collections(string $actor): array
    {
        $this->trees->active($actor);
        $user = $this->users->get($actor);
        if (!$user || !$this->apps->isEnabledForUser('calendar', $user)) {
            return ['available' => false, 'items' => []];
        }
        $items = [];
        foreach ($this->calendars->getCalendarsForPrincipal('principals/users/' . $actor) as $c) {
            if (
                !$c->isDeleted() &&
                $c instanceof ICreateFromString &&
                $c->getPermissions() & Constants::PERMISSION_CREATE &&
                preg_match('/\A[a-zA-Z0-9_.-]{1,160}\z/D', $c->getUri()) &&
                !str_starts_with($c->getUri(), 'contact_birthdays')
            ) {
                $items[] = ['uri' => $c->getUri(), 'name' => $c->getDisplayName() ?: $c->getUri()];
            }
            if (count($items) >= 50) {
                break;
            }
        }
        return ['available' => true, 'items' => $items];
    }

    private static function date(string $value): DateTimeImmutable
    {
        if (
            !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) ||
            (int) substr($value, 0, 4) < 1 ||
            !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
        ) {
            throw new Problem('invalid_date', 'Choose a valid exact calendar date.');
        }
        return new DateTimeImmutable($value . ' 00:00:00', new DateTimeZone('UTC'));
    }

    private static function nextAnniversary(string $source, string $timezone): string
    {
        $monthDay = substr($source, 5);
        $today = (new DateTimeImmutable('today', new DateTimeZone($timezone)))->format('Y-m-d');
        $year = (int) substr($today, 0, 4);
        for ($candidate = $year; $candidate <= $year + 8; $candidate++) {
            $date = sprintf('%04d-%s', $candidate, $monthDay);
            if (
                $date >= $today &&
                checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), $candidate)
            ) {
                return $date;
            }
        }
        throw new Problem('invalid_date', 'An anniversary date could not be determined.');
    }

    private static function timedStart(string $date, string $time, string $timezone): DateTimeImmutable
    {
        $zone = new DateTimeZone($timezone);
        $local = new DateTimeImmutable($date . ' ' . $time, $zone);
        if ($local->format('Y-m-d H:i') !== $date . ' ' . $time) {
            throw new Problem('invalid_date', 'This time does not exist in the selected timezone.');
        }
        // A repeated wall time has two possible instants. Require a different
        // appointment time instead of silently choosing one offset.
        $wall = (new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('UTC')))->getTimestamp();
        $transitions =
            $zone->getTransitions($local->getTimestamp() - 86400, $local->getTimestamp() + 86400) ?: [];
        for ($i = 1; $i < count($transitions); $i++) {
            $before = (int) $transitions[$i - 1]['offset'];
            $after = (int) $transitions[$i]['offset'];
            $transition = (int) $transitions[$i]['ts'];
            if ($after < $before && $wall >= $transition + $after && $wall < $transition + $before) {
                throw new Problem(
                    'invalid_date',
                    'This time occurs twice in the selected timezone. Choose another time.',
                );
            }
        }
        return $local;
    }

    public function review(string $tree, string $actor, array $b): array
    {
        $person = $b['personId'] ?? null;
        $uri = $b['collection'] ?? null;
        if (!is_string($person) || !is_string($uri)) {
            throw Problem::missing();
        }
        $this->calendar($actor, $uri);
        $includeLiving = ($b['includeLiving'] ?? false) === true;
        $includeManagement = ($b['includeManagement'] ?? false) === true;
        [$m, $row, $p] = $this->source($tree, $actor, $person, $includeLiving, $includeManagement);
        $mode = Values::choice($b['mode'] ?? 'appointment', ['appointment', 'anniversary'], 'mode');
        $anniversaryKind = '';
        $sourceDate = '';
        $time = '';
        $timezone = '';
        if ($mode === 'anniversary') {
            $anniversaryKind = Values::choice(
                $b['anniversaryKind'] ?? '',
                ['birth', 'death'],
                'anniversaryKind',
            );
            $dateValue = $p[$anniversaryKind . 'Date'] ?? null;
            if (
                !is_array($dateValue) ||
                ($dateValue['kind'] ?? '') !== 'exact' ||
                !is_string($dateValue['value'] ?? null)
            ) {
                throw new Problem('invalid_date', 'Only an exact full date can supply an anniversary.');
            }
            $sourceDate = $dateValue['value'];
            self::date($sourceDate);
            $timezone = Values::text($b['timezone'] ?? 'UTC', 'timezone', 100, true);
            if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                throw new Problem('invalid_date', 'Choose a supported timezone.');
            }
            $date = self::nextAnniversary($sourceDate, $timezone);
        } else {
            $raw = $b['date'] ?? null;
            if (!is_string($raw)) {
                throw new Problem('invalid_date', 'Choose a valid exact calendar date.');
            }
            self::date($raw);
            $date = $raw;
            $time = Values::text($b['time'] ?? '', 'time', 5);
            if ($time !== '') {
                if (!preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', $time)) {
                    throw new Problem('invalid_date', 'Choose a valid appointment time.');
                }
                $timezone = Values::text($b['timezone'] ?? '', 'timezone', 100, true);
                if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                    throw new Problem('invalid_date', 'Choose a supported timezone.');
                }
                self::timedStart($date, $time, $timezone);
            }
        }
        $name = trim($p['givenName'] . ' ' . $p['familyName']) ?: 'Unnamed person';
        $title = Values::text($b['title'] ?? '', 'title', 200, true);
        $location = Values::text($b['location'] ?? '', 'location', 300);
        $birthdaySignal = false;
        if ($mode === 'anniversary' && $anniversaryKind === 'birth') {
            $birthdaySignal = $this->contacts->birthdaySignal($tree, $actor, $person)[
                'linkedContactBirthday'
            ];
        }
        return [
            'personId' => $person,
            'personName' => $name,
            'collection' => $uri,
            'mode' => $mode,
            'anniversaryKind' => $anniversaryKind,
            'sourceDate' => $sourceDate,
            'date' => $date,
            'time' => $time,
            'timezone' => $timezone,
            'title' => $title,
            'location' => $location,
            'includeLiving' => $includeLiving,
            'includeManagement' => $includeManagement,
            'sourceRevision' => (int) $row['revision'],
            'memberRevision' => (int) $m['revision'],
            'treeRevision' => $this->trees->get($tree, $actor)['revision'],
            'linkedContactBirthday' => $birthdaySignal,
            'expiresAt' => time() + 300,
            'audienceWarning' =>
                'This calendar has separate sharing permissions. Its readers and synchronized devices can retain the copied details.',
        ];
    }

    private static function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\r", "\n", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value,
        );
    }

    private static function ics(string $id, array $r, string $backlink): string
    {
        $date = self::date($r['date']);
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Stammbaum//Calendar 1//EN',
            'BEGIN:VEVENT',
            'UID:stb-event-' . $id . '@stammbaum.nextcloud',
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'SUMMARY:' . self::escape($r['title']),
            'DESCRIPTION:' . self::escape($backlink),
        ];
        if ($r['location'] !== '') {
            $lines[] = 'LOCATION:' . self::escape($r['location']);
        }
        if ($r['time'] !== '') {
            $utc = self::timedStart($r['date'], $r['time'], $r['timezone'])->setTimezone(
                new DateTimeZone('UTC'),
            );
            $lines[] = 'DTSTART:' . $utc->format('Ymd\THis\Z');
            $lines[] = 'DTEND:' . $utc->add(new DateInterval('PT1H'))->format('Ymd\THis\Z');
        } else {
            $lines[] = 'DTSTART;VALUE=DATE:' . $date->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:' . $date->add(new DateInterval('P1D'))->format('Ymd');
        }
        if ($r['mode'] === 'anniversary') {
            $lines[] =
                'RRULE:FREQ=YEARLY;BYMONTH=' .
                (int) substr($r['date'], 5, 2) .
                ';BYMONTHDAY=' .
                (int) substr($r['date'], 8, 2);
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        $folded = [];
        foreach ($lines as $line) {
            $first = true;
            do {
                $part = mb_strcut($line, 0, $first ? 75 : 74, 'UTF-8');
                $folded[] = ($first ? '' : ' ') . $part;
                $line = substr($line, strlen($part));
                $first = false;
            } while ($line !== '');
        }
        return implode("\r\n", $folded) . "\r\n";
    }

    private function found(ICalendar $c, string $id): bool
    {
        return (bool) $c->search(
            '',
            [],
            ['uid' => 'stb-event-' . $id . '@stammbaum.nextcloud', 'types' => ['VEVENT']],
            1,
        );
    }

    public function create(string $tree, string $actor, array $b): array
    {
        $review = $b['review'] ?? null;
        $requestId = $b['requestId'] ?? null;
        if (
            !is_array($review) ||
            !is_string($requestId) ||
            !preg_match('/\A[a-zA-Z0-9-]{8,80}\z/D', $requestId)
        ) {
            throw new Problem('review_required', 'Review this calendar event before creating it.');
        }
        if (($b['acknowledged'] ?? false) !== true) {
            throw new Problem('acknowledgement', 'Acknowledge the calendar audience before creating it.');
        }
        $current = $this->review($tree, $actor, $review);
        foreach (
            [
                'personId',
                'collection',
                'mode',
                'anniversaryKind',
                'sourceDate',
                'date',
                'time',
                'timezone',
                'title',
                'location',
                'includeLiving',
                'includeManagement',
                'sourceRevision',
                'memberRevision',
                'treeRevision',
                'linkedContactBirthday',
            ]
            as $key
        ) {
            if (($review[$key] ?? null) !== $current[$key]) {
                throw new Problem(
                    'conflict',
                    'The research or your access changed. Review the event again.',
                    412,
                );
            }
        }
        if (
            !is_int($review['expiresAt'] ?? null) ||
            $review['expiresAt'] < time() ||
            $review['expiresAt'] > time() + 300
        ) {
            throw new Problem('review_expired', 'Review this calendar event again.', 412);
        }
        if ($current['linkedContactBirthday'] && ($b['acknowledgedDuplicate'] ?? false) !== true) {
            throw new Problem(
                'acknowledgement',
                'Acknowledge the possible duplicate birthday before creating it.',
            );
        }
        $hash = hash(
            'sha256',
            Store::json(
                array_intersect_key(
                    $current,
                    array_flip([
                        'personId',
                        'collection',
                        'mode',
                        'anniversaryKind',
                        'sourceDate',
                        'date',
                        'time',
                        'timezone',
                        'title',
                        'location',
                        'includeLiving',
                        'includeManagement',
                        'sourceRevision',
                        'memberRevision',
                    ]),
                ),
            ),
        );
        $row = $this->store->write(function () use ($tree, $actor, $requestId, $current, $hash) {
            $old = $this->store->one(
                'SELECT * FROM *PREFIX*stb_calendar_links WHERE actor_id = ? AND tree_id = ? AND request_id = ?',
                [$actor, $tree, $requestId],
            );
            if ($old) {
                if ($old['payload_hash'] !== $hash) {
                    throw new Problem(
                        'conflict',
                        'This event request was already used for different details.',
                        409,
                    );
                }
                return $old;
            }
            $recent = $this->store->one(
                'SELECT COUNT(*) AS n FROM *PREFIX*stb_calendar_links WHERE actor_id = ? AND created_at >= ?',
                [$actor, time() - 3600],
            );
            if ((int) $recent['n'] >= 20) {
                throw new Problem('rate_limit', 'Too many new calendar requests. Try again later.', 429);
            }
            $id = Store::id();
            $now = time();
            $this->store->insert('stb_calendar_links', [
                'id' => $id,
                'tree_id' => $tree,
                'actor_id' => $actor,
                'person_id' => $current['personId'],
                'collection_uri' => $current['collection'],
                'object_name' => $id . '.ics',
                'request_id' => $requestId,
                'payload_hash' => $hash,
                'source_revision' => $current['sourceRevision'],
                'member_revision' => $current['memberRevision'],
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return $this->store->one('SELECT * FROM *PREFIX*stb_calendar_links WHERE id = ?', [$id]);
        });
        if ($row['status'] === 'linked') {
            $calendar = $this->calendar($actor, $row['collection_uri']);
            return [
                'id' => $row['id'],
                'status' => $this->found($calendar, $row['id']) ? 'linked' : 'destination-changed',
            ];
        }
        if ($row['status'] !== 'pending' || (int) $row['created_at'] < time() - 300) {
            throw new Problem('review_expired', 'Review this calendar event again.', 412);
        }
        // A fresh authority check immediately precedes the external write.
        [$member, $source] = $this->source($tree, $actor, $row['person_id'], true, true);
        if (
            (int) $member['revision'] !== (int) $row['member_revision'] ||
            (int) $source['revision'] !== (int) $row['source_revision']
        ) {
            throw new Problem(
                'conflict',
                'The research or your access changed. Review the event again.',
                412,
            );
        }
        $calendar = $this->calendar($actor, $row['collection_uri']);
        if (!$this->found($calendar, $row['id'])) {
            $backlink =
                $this->urls->linkToRouteAbsolute('stammbaum.page.index') .
                '#tree=' .
                rawurlencode($tree) .
                '&view=people&person=' .
                rawurlencode($row['person_id']);
            try {
                $calendar->createFromStringMinimal(
                    $row['object_name'],
                    self::ics($row['id'], $current, $backlink),
                );
            } catch (\Throwable $e) {
                if (!$this->found($calendar, $row['id'])) {
                    throw new Problem(
                        'outcome_unknown',
                        'Calendar creation could not be verified. Check Calendar before retrying.',
                        409,
                    );
                }
            }
        }
        try {
            [$member, $source] = $this->source($tree, $actor, $row['person_id'], true, true);
        } catch (Problem $e) {
            $this->store->execute(
                "UPDATE *PREFIX*stb_calendar_links SET status = 'authority-invalidated', updated_at = ? WHERE id = ?",
                [time(), $row['id']],
            );
            throw new Problem(
                'outcome_unknown',
                'The event may have been created, but your access or the research changed. Check Calendar.',
                409,
            );
        }
        if (
            (int) $member['revision'] !== (int) $row['member_revision'] ||
            (int) $source['revision'] !== (int) $row['source_revision']
        ) {
            $this->store->execute(
                "UPDATE *PREFIX*stb_calendar_links SET status = 'authority-invalidated', updated_at = ? WHERE id = ?",
                [time(), $row['id']],
            );
            throw new Problem(
                'outcome_unknown',
                'The event may have been created, but your access or the research changed. Check Calendar.',
                409,
            );
        }
        if (!$this->found($calendar, $row['id'])) {
            throw new Problem(
                'outcome_unknown',
                'Calendar creation could not be verified. Check Calendar before retrying.',
                409,
            );
        }
        $this->store->execute(
            "UPDATE *PREFIX*stb_calendar_links SET status = 'linked', updated_at = ? WHERE id = ? AND status = 'pending'",
            [time(), $row['id']],
        );
        return ['id' => $row['id'], 'status' => 'linked'];
    }

    /** Unlink is account-private and never deletes the independently owned VEVENT. */
    public function unlink(string $tree, string $actor, string $id): array
    {
        $this->trees->active($actor);
        return $this->store->write(function () use ($tree, $actor, $id) {
            $row = $this->store->one(
                'SELECT id FROM *PREFIX*stb_calendar_links WHERE tree_id = ? AND actor_id = ? AND id = ?',
                [$tree, $actor, $id],
            );
            if (!$row) {
                throw Problem::missing();
            }
            $this->store->execute(
                "UPDATE *PREFIX*stb_calendar_links SET status = 'unlinked', updated_at = ? WHERE id = ?",
                [time(), $id],
            );
            return ['id' => $id, 'status' => 'unlinked'];
        });
    }

    public function list(string $tree, string $actor, string $person): array
    {
        $m = $this->trees->access($tree, $actor);
        if (!Trees::management($m) && !(bool) $m['export_allowed']) {
            return ['items' => []];
        }
        $source = $this->people->row($tree, $person, $m);
        $this->store->execute(
            "UPDATE *PREFIX*stb_calendar_links SET status = 'outcome-unknown' WHERE actor_id = ? AND status = 'pending' AND created_at < ?",
            [$actor, time() - 300],
        );
        $rows = $this->store->rows(
            "SELECT id, collection_uri, status, source_revision, created_at FROM *PREFIX*stb_calendar_links WHERE tree_id = ? AND actor_id = ? AND person_id = ? AND member_revision = ? AND status IN ('pending','linked','outcome-unknown') ORDER BY created_at DESC, id DESC LIMIT 25",
            [$tree, $actor, $person, (int) $m['revision']],
        );
        $items = [];
        foreach ($rows as $r) {
            $status =
                (int) $r['source_revision'] !== (int) $source['revision'] ? 'source-changed' : $r['status'];
            if ($status === 'linked') {
                try {
                    $calendar = $this->calendar($actor, $r['collection_uri']);
                    if (!$this->found($calendar, $r['id'])) {
                        $status = 'destination-changed';
                    }
                } catch (Problem $e) {
                    if (!in_array($e->errorCode, ['not_found', 'unavailable'], true)) {
                        throw $e;
                    }
                    $status = 'unavailable';
                }
            }
            $items[] = [
                'id' => $r['id'],
                'collection' => $r['collection_uri'],
                'status' => $status,
                'createdAt' => (int) $r['created_at'],
            ];
        }
        return ['items' => $items];
    }
}
