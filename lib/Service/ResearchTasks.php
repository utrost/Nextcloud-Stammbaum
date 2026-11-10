<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
use OCP\App\IAppManager;
use OCP\Calendar\IManager;
use OCP\Constants;
use OCP\IURLGenerator;
use OCP\IUserManager;

/** One reviewed, account-owned CalDAV VTODO. Never writes into another app's tables. */
final class ResearchTasks
{
    public function __construct(
        private Store $store,
        private Trees $trees,
        private People $people,
        private IManager $calendars,
        private IAppManager $apps,
        private IUserManager $users,
        private IURLGenerator $urls,
    ) {}

    private function authority(string $tree, string $actor, string $person, bool $includeLiving): array
    {
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
        return [$m, $row, $p];
    }

    private function calendar(string $actor, string $uri): \OCP\Calendar\ICalendar
    {
        if (!preg_match('/\A[a-zA-Z0-9_.-]{1,160}\z/D', $uri)) {
            throw Problem::missing();
        }
        $user = $this->users->get($actor);
        if (!$user || !$this->apps->isEnabledForUser('tasks', $user)) {
            throw new Problem('unavailable', 'Nextcloud Tasks is not available for this account.', 409);
        }
        foreach ($this->calendars->getCalendarsForPrincipal('principals/users/' . $actor, [$uri]) as $c) {
            if (
                $c->getUri() === $uri &&
                !$c->isDeleted() &&
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
        if (!$user || !$this->apps->isEnabledForUser('tasks', $user)) {
            return ['available' => false, 'items' => []];
        }
        $items = [];
        foreach ($this->calendars->getCalendarsForPrincipal('principals/users/' . $actor) as $c) {
            if (
                !$c->isDeleted() &&
                $c->getPermissions() & Constants::PERMISSION_CREATE &&
                preg_match('/\A[a-zA-Z0-9_.-]{1,160}\z/D', $c->getUri())
            ) {
                $items[] = ['uri' => $c->getUri(), 'name' => $c->getDisplayName() ?: $c->getUri()];
            }
            if (count($items) >= 50) {
                break;
            }
        }
        return ['available' => true, 'items' => $items];
    }

    private static function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\r", "\n", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value,
        );
    }

    private static function ics(string $id, string $title, string $description, ?string $due): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Stammbaum//Research Tasks 1//EN',
            'BEGIN:VTODO',
            'UID:stb-' . $id . '@stammbaum.nextcloud',
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'STATUS:NEEDS-ACTION',
            'SUMMARY:' . self::escape($title),
            'DESCRIPTION:' . self::escape($description),
        ];
        if ($due !== null) {
            $lines[] = 'DUE;VALUE=DATE:' . str_replace('-', '', $due);
        }
        $lines[] = 'END:VTODO';
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

    public function review(string $tree, string $actor, array $b): array
    {
        $person = $b['personId'] ?? null;
        $uri = $b['collection'] ?? null;
        if (!is_string($person) || !is_string($uri)) {
            throw Problem::missing();
        }
        $this->calendar($actor, $uri);
        [$m, $row, $p] = $this->authority($tree, $actor, $person, ($b['includeLiving'] ?? false) === true);
        $name = trim($p['givenName'] . ' ' . $p['familyName']) ?: 'Unnamed person';
        $title = Values::text($b['title'] ?? 'Research ' . $name, 'title', 200, true);
        $note = Values::text($b['note'] ?? '', 'note', 1000);
        $due = $b['due'] ?? null;
        if ($due === '') {
            $due = null;
        }
        if (
            $due !== null &&
            (!is_string($due) ||
                !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $due) ||
                !checkdate((int) substr($due, 5, 2), (int) substr($due, 8, 2), (int) substr($due, 0, 4)))
        ) {
            throw new Problem('invalid_date', 'Enter a valid due date.');
        }
        return [
            'personId' => $person,
            'personName' => $name,
            'title' => $title,
            'note' => $note,
            'due' => $due,
            'collection' => $uri,
            'includeLiving' => ($b['includeLiving'] ?? false) === true,
            'sourceRevision' => (int) $row['revision'],
            'memberRevision' => (int) $m['revision'],
            'treeRevision' => $this->trees->get($tree, $actor)['revision'],
            'expiresAt' => time() + 300,
            'audienceWarning' =>
                'The selected task list has separate sharing permissions. Its readers and synchronized devices can retain this copy.',
        ];
    }

    public function reserve(string $tree, string $actor, array $b): array
    {
        return $this->store->write(function () use ($tree, $actor, $b) {
            $review = $b['review'] ?? null;
            $requestId = $b['requestId'] ?? null;
            if (
                !is_array($review) ||
                !is_string($requestId) ||
                !preg_match('/\A[a-zA-Z0-9-]{8,80}\z/D', $requestId)
            ) {
                throw new Problem('review_required', 'Review this task before creating it.');
            }
            $current = $this->review($tree, $actor, $review);
            foreach (
                [
                    'personId',
                    'title',
                    'note',
                    'due',
                    'collection',
                    'includeLiving',
                    'sourceRevision',
                    'memberRevision',
                    'treeRevision',
                ]
                as $key
            ) {
                if (($review[$key] ?? null) !== $current[$key]) {
                    throw new Problem(
                        'conflict',
                        'The research or your access changed. Review the task again.',
                        412,
                    );
                }
            }
            if (
                !is_int($review['expiresAt'] ?? null) ||
                $review['expiresAt'] < time() ||
                $review['expiresAt'] > time() + 300
            ) {
                throw new Problem('review_expired', 'Review this task again.', 412);
            }
            if (($b['acknowledged'] ?? false) !== true) {
                throw new Problem(
                    'acknowledgement',
                    'Acknowledge the task list audience before creating it.',
                );
            }
            $hash = hash(
                'sha256',
                Store::json([
                    $review['personId'],
                    $review['collection'],
                    $review['title'],
                    $review['note'],
                    $review['due'],
                    $review['includeLiving'],
                    $review['sourceRevision'],
                    $review['memberRevision'],
                ]),
            );
            $old = $this->store->one(
                'SELECT * FROM *PREFIX*stb_task_links WHERE actor_id = ? AND tree_id = ? AND request_id = ?',
                [$actor, $tree, $requestId],
            );
            if ($old) {
                if ($old['payload_hash'] !== $hash) {
                    throw new Problem(
                        'conflict',
                        'This task request was already used for different details.',
                        409,
                    );
                }
                return $this->reserved($old, $actor);
            }
            $recent = $this->store->one(
                'SELECT COUNT(*) AS n FROM *PREFIX*stb_task_links WHERE actor_id = ? AND created_at >= ?',
                [$actor, time() - 3600],
            );
            if ((int) $recent['n'] >= 20) {
                throw new Problem('rate_limit', 'Too many new task requests. Try again later.', 429);
            }
            $id = Store::id();
            $now = time();
            $this->store->insert('stb_task_links', [
                'id' => $id,
                'tree_id' => $tree,
                'actor_id' => $actor,
                'person_id' => $review['personId'],
                'collection_uri' => $review['collection'],
                'object_name' => $id . '.ics',
                'request_id' => $requestId,
                'payload_hash' => $hash,
                'payload_json' => Store::json([
                    'title' => $review['title'],
                    'note' => $review['note'],
                    'due' => $review['due'],
                ]),
                'source_revision' => $review['sourceRevision'],
                'member_revision' => $review['memberRevision'],
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $row = $this->store->one('SELECT * FROM *PREFIX*stb_task_links WHERE id = ?', [$id]);
            return $this->reserved($row, $actor);
        });
    }

    private function reserved(array $row, string $actor): array
    {
        $this->calendar($actor, $row['collection_uri']);
        if ($row['status'] === 'linked') {
            return ['id' => $row['id'], 'status' => 'linked'];
        }
        if ($row['status'] !== 'pending') {
            throw new Problem('unavailable', 'This task request is no longer available.', 409);
        }
        if ((int) $row['created_at'] < time() - 300) {
            $this->store->execute(
                "UPDATE *PREFIX*stb_task_links SET status = 'expired', payload_json = '{}' WHERE id = ?",
                [$row['id']],
            );
            throw new Problem('review_expired', 'Review this task again.', 412);
        }
        [$m, $source] = $this->authority($row['tree_id'], $actor, $row['person_id'], true);
        if (
            (int) $source['revision'] !== (int) $row['source_revision'] ||
            (int) $m['revision'] !== (int) $row['member_revision']
        ) {
            throw new Problem('conflict', 'The research or your access changed. Review the task again.', 412);
        }
        $data = json_decode($row['payload_json'], true, 16, JSON_THROW_ON_ERROR);
        $link =
            $this->urls->linkToRouteAbsolute('stammbaum.page.index') .
            '#tree=' .
            rawurlencode($row['tree_id']) .
            '&view=people&person=' .
            rawurlencode($row['person_id']);
        $description = ($data['note'] === '' ? '' : $data['note'] . "\n\n") . $link;
        return [
            'id' => $row['id'],
            'status' => 'pending',
            'collection' => $row['collection_uri'],
            'objectName' => $row['object_name'],
            'ics' => self::ics($row['id'], $data['title'], $description, $data['due']),
        ];
    }

    public function complete(string $tree, string $actor, string $id): array
    {
        return $this->store->write(function () use ($tree, $actor, $id) {
            $row = $this->store->one(
                'SELECT * FROM *PREFIX*stb_task_links WHERE id = ? AND tree_id = ? AND actor_id = ?',
                [$id, $tree, $actor],
            );
            if (!$row) {
                throw Problem::missing();
            }
            $m = $this->trees->access($tree, $actor);
            if (!Trees::management($m) && !(bool) $m['export_allowed']) {
                throw Problem::missing();
            }
            $source = $this->people->row($tree, $row['person_id'], $m);
            if (
                (int) $m['revision'] !== (int) $row['member_revision'] ||
                (int) $source['revision'] !== (int) $row['source_revision']
            ) {
                throw new Problem(
                    'conflict',
                    'The research or your access changed. Review the task again.',
                    412,
                );
            }
            if ($row['status'] === 'linked') {
                return ['id' => $id, 'status' => 'linked'];
            }
            $c = $this->calendar($actor, $row['collection_uri']);
            $found = $c->search(
                '',
                [],
                ['uid' => 'stb-' . $id . '@stammbaum.nextcloud', 'types' => ['VTODO']],
                1,
            );
            if (!$found) {
                throw new Problem(
                    'outcome_unknown',
                    'Task creation could not be verified yet. Refresh before trying again.',
                    409,
                );
            }
            $this->store->execute(
                "UPDATE *PREFIX*stb_task_links SET status = 'linked', payload_json = '{}', updated_at = ? WHERE id = ?",
                [time(), $id],
            );
            return ['id' => $id, 'status' => 'linked'];
        });
    }

    public function list(string $tree, string $actor, string $person): array
    {
        $m = $this->trees->access($tree, $actor);
        if (!Trees::management($m) && !(bool) $m['export_allowed']) {
            return ['items' => []];
        }
        $source = $this->people->row($tree, $person, $m);
        // A timed-out DAV request may have succeeded. Keep its opaque identity for
        // reconciliation, but discard the short-lived copied task text.
        $this->store->execute(
            "UPDATE *PREFIX*stb_task_links SET status = 'outcome-unknown', payload_json = '{}' WHERE actor_id = ? AND status = 'pending' AND created_at < ?",
            [$actor, time() - 300],
        );
        $rows = $this->store->rows(
            "SELECT id, collection_uri, status, source_revision, created_at FROM *PREFIX*stb_task_links WHERE tree_id = ? AND actor_id = ? AND person_id = ? AND member_revision = ? AND status IN ('pending','linked','outcome-unknown') ORDER BY created_at DESC, id DESC LIMIT 25",
            [$tree, $actor, $person, (int) $m['revision']],
        );
        return [
            'items' => array_map(
                fn($r) => [
                    'id' => $r['id'],
                    'collection' => $r['collection_uri'],
                    'status' =>
                        (int) $r['source_revision'] !== (int) $source['revision']
                            ? 'source-changed'
                            : $r['status'],
                    'createdAt' => (int) $r['created_at'],
                ],
                $rows,
            ),
        ];
    }
}
