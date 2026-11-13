<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;

/** Owner-only projection over provider receipts. All queries bind actor before paging. */
final class IntegrationConnections
{
    private const TABLES = [
        'task' => 'stb_task_links',
        'calendar' => 'stb_calendar_links',
        'contact' => 'stb_contact_links',
    ];

    public function __construct(
        private Store $store,
        private Trees $trees,
        private People $people,
        private ConnectionIdentity $identity,
        private ResearchTasks $tasks,
        private CalendarAppointments $calendar,
        private ContactLinks $contacts,
    ) {}

    private function table(string $kind): string
    {
        return self::TABLES[$kind] ?? throw Problem::missing();
    }

    private function prune(string $actor): void
    {
        foreach (self::TABLES as $table) {
            $this->store->execute(
                "UPDATE *PREFIX*$table SET status = 'outcome-unknown', updated_at = ? WHERE actor_id = ? AND status = 'pending' AND created_at < ?",
                [time(), $actor, time() - 300],
            );
            $this->store->execute(
                "DELETE FROM *PREFIX*$table WHERE actor_id = ? AND ((status = 'unlinked' AND updated_at < ?) OR (status = 'outcome-unknown' AND updated_at < ?))",
                [$actor, time() - 30 * 86400, time() - 90 * 86400],
            );
        }
    }

    /** Daily local receipt retention; deliberately performs no DAV access. */
    public function cleanup(): void
    {
        $this->store->write(function () {
            foreach (self::TABLES as $table) {
                $this->store->execute(
                    "UPDATE *PREFIX*$table SET status = 'outcome-unknown', updated_at = ? WHERE status = 'pending' AND created_at < ?",
                    [time(), time() - 300],
                );
                $this->store->execute(
                    "DELETE FROM *PREFIX*$table WHERE (status = 'unlinked' AND updated_at < ?) OR (status = 'outcome-unknown' AND updated_at < ?)",
                    [time() - 30 * 86400, time() - 90 * 86400],
                );
            }
        });
    }

    private function row(string $tree, string $actor, string $kind, string $id): array
    {
        if (!preg_match('/\A[0-9a-f-]{36}\z/D', $id)) {
            throw Problem::missing();
        }
        return $this->store->one(
            'SELECT * FROM *PREFIX*' . $this->table($kind) . ' WHERE actor_id = ? AND tree_id = ? AND id = ?',
            [$actor, $tree, $id],
        ) ?? throw Problem::missing();
    }

    private function project(
        string $tree,
        string $actor,
        string $kind,
        array $row,
        ?array $member,
        string $incarnation,
    ): array {
        $status = $row['status'];
        $item = [
            'id' => $row['id'],
            'kind' => $kind,
            'status' => $status,
            'createdAt' => (int) $row['created_at'],
            'createdByApp' => $kind !== 'contact',
        ];
        if ($status === 'unlinked') {
            return $item;
        }
        if ($status === 'unavailable' || $status === 'authority-invalidated') {
            return $item;
        }
        if (
            !$member ||
            ((string) $row['account_incarnation'] !== '' && $row['account_incarnation'] !== $incarnation) ||
            (int) $row['member_revision'] !== (int) $member['revision'] ||
            ($kind !== 'contact' && !Trees::management($member) && !(bool) $member['export_allowed']) ||
            $status === 'authority-invalidated'
        ) {
            $item['status'] = 'authority-invalidated';
            return $item;
        }
        try {
            $source = $this->people->row($tree, $row['person_id'], $member);
        } catch (Problem $e) {
            if ($e->errorCode === 'not_found') {
                $this->store->execute(
                    'UPDATE *PREFIX*' .
                        $this->table($kind) .
                        " SET status = 'unavailable', updated_at = ? WHERE id = ? AND actor_id = ? AND status NOT IN ('unlinked', 'authority-invalidated')",
                    [time(), $row['id'], $actor],
                );
                $item['status'] = 'unavailable';
                return $item;
            }
            throw $e;
        }
        // Existing links from before the account-incarnation migration are
        // adopted only while their original membership and source remain valid.
        if (
            $row['account_incarnation'] === null ||
            $row['account_incarnation'] === '' ||
            ($kind === 'contact' && (int) $row['source_revision'] === 0)
        ) {
            $baseline = (int) $row['source_revision'] ?: (int) $source['revision'];
            $this->store->execute(
                'UPDATE *PREFIX*' .
                    $this->table($kind) .
                    ' SET account_incarnation = ?, source_revision = ?, dependency_digest = ? WHERE id = ? AND actor_id = ? AND (account_incarnation IS NULL OR account_incarnation = ?)',
                [
                    $incarnation,
                    $baseline,
                    hash('sha256', $row['person_id'] . ':' . $baseline),
                    $row['id'],
                    $actor,
                    '',
                ],
            );
            $row['source_revision'] = $baseline;
        }
        $person = $this->people->dto($source);
        $item['source'] = [
            'type' => 'person',
            'id' => $row['person_id'],
            'label' => trim($person['givenName'] . ' ' . $person['familyName']) ?: 'Unnamed person',
        ];
        $item['collection'] = $kind === 'contact' ? $row['book_key'] : $row['collection_uri'];
        if (
            (int) $row['source_revision'] > 0 &&
            (int) $row['source_revision'] !== (int) $source['revision']
        ) {
            $item['status'] = 'source-changed';
            return $item;
        }
        if ($status === 'pending' || $status === 'outcome-unknown') {
            return $item;
        }
        if ($status === 'destination-changed') {
            return $item;
        }
        if ($kind === 'task') {
            $item['status'] = $this->tasks->destinationStatus($actor, $row['collection_uri'], $row['id']);
        } elseif ($kind === 'calendar') {
            $item['status'] = $this->calendar->destinationStatus($actor, $row['collection_uri'], $row['id']);
        } else {
            $item['status'] = $this->contacts->destinationStatus(
                $actor,
                $row['book_key'],
                $row['card_uid'],
                $row['card_uri'],
                $row['card_hash'],
            );
        }
        if ($kind !== 'contact' && $item['status'] === 'destination-changed') {
            $this->store->execute(
                'UPDATE *PREFIX*' .
                    $this->table($kind) .
                    " SET status = 'destination-changed', updated_at = ? WHERE id = ? AND actor_id = ? AND status = 'linked'",
                [time(), $row['id'], $actor],
            );
        }
        $item['canRemoveExternal'] =
            $kind !== 'contact' &&
            $item['status'] === 'linked' &&
            is_string($row['destination_etag']) &&
            $row['destination_etag'] !== '';
        return $item;
    }

    public function list(string $tree, string $actor, int $page = 0): array
    {
        $this->trees->active($actor);
        if ($page < 0 || $page > 400) {
            throw new Problem('validation', 'Choose a valid connection page.');
        }
        $this->prune($actor);
        try {
            $member = $this->trees->access($tree, $actor);
        } catch (Problem $e) {
            if ($e->errorCode !== 'not_found') {
                throw $e;
            }
            $member = null;
        }
        $incarnation = $this->identity->current($actor);
        $sql =
            'SELECT kind, id, created_at FROM (' .
            "SELECT 'task' AS kind, id, created_at FROM *PREFIX*stb_task_links WHERE actor_id = ? AND tree_id = ? " .
            "UNION ALL SELECT 'calendar' AS kind, id, created_at FROM *PREFIX*stb_calendar_links WHERE actor_id = ? AND tree_id = ? " .
            "UNION ALL SELECT 'contact' AS kind, id, created_at FROM *PREFIX*stb_contact_links WHERE actor_id = ? AND tree_id = ?" .
            ') connections ORDER BY created_at DESC, id DESC LIMIT 26 OFFSET ' .
            $page * 25;
        $refs = $this->store->rows($sql, [$actor, $tree, $actor, $tree, $actor, $tree]);
        $items = [];
        foreach (array_slice($refs, 0, 25) as $ref) {
            $items[] = $this->project(
                $tree,
                $actor,
                $ref['kind'],
                $this->row($tree, $actor, $ref['kind'], $ref['id']),
                $member,
                $incarnation,
            );
        }
        return ['items' => $items, 'nextPage' => count($refs) > 25 ? $page + 1 : null];
    }

    public function refresh(string $tree, string $actor, string $kind, string $id): array
    {
        $this->trees->active($actor);
        $this->prune($actor);
        $row = $this->row($tree, $actor, $kind, $id);
        try {
            $member = $this->trees->access($tree, $actor);
        } catch (Problem $e) {
            if ($e->errorCode !== 'not_found') {
                throw $e;
            }
            $member = null;
        }
        return $this->project($tree, $actor, $kind, $row, $member, $this->identity->current($actor));
    }

    /** Opaque local cleanup; external objects and tree revisions are untouched. */
    public function unlink(string $tree, string $actor, string $kind, string $id): array
    {
        $this->trees->active($actor);
        return $this->store->write(function () use ($tree, $actor, $kind, $id) {
            $this->row($tree, $actor, $kind, $id);
            $this->store->execute(
                'UPDATE *PREFIX*' .
                    $this->table($kind) .
                    " SET status = 'unlinked', updated_at = ? WHERE id = ? AND actor_id = ?",
                [time(), $id, $actor],
            );
            return ['id' => $id, 'kind' => $kind, 'status' => 'unlinked'];
        });
    }

    /** Capture the first observed DAV ETag once; older links remain non-removable. */
    public function acknowledgeEtag(string $tree, string $actor, string $kind, string $id, array $body): array
    {
        $this->trees->active($actor);
        if ($kind === 'contact') {
            throw Problem::missing();
        }
        $etag = $body['etag'] ?? null;
        if (
            !is_string($etag) ||
            strlen($etag) > 128 ||
            !preg_match('/\A(?:W\/)?"[^"\r\n]{1,120}"\z/D', $etag)
        ) {
            throw new Problem('validation', 'The external item has no usable ETag.');
        }
        return $this->store->write(function () use ($tree, $actor, $kind, $id, $etag) {
            $row = $this->row($tree, $actor, $kind, $id);
            $member = $this->trees->access($tree, $actor);
            if (
                $this->project($tree, $actor, $kind, $row, $member, $this->identity->current($actor))[
                    'status'
                ] !== 'linked'
            ) {
                throw new Problem('conflict', 'Review this connection again.', 412);
            }
            if (
                $row['destination_etag'] !== null &&
                $row['destination_etag'] !== '' &&
                $row['destination_etag'] !== $etag
            ) {
                throw new Problem('conflict', 'The external item changed. Remove it in its own app.', 412);
            }
            if ($row['destination_etag'] === null || $row['destination_etag'] === '') {
                if ((int) $row['created_at'] < time() - 60) {
                    throw new Problem(
                        'conflict',
                        'The initial ETag capture window expired. Remove the item in its own app.',
                        412,
                    );
                }
                $this->store->execute(
                    'UPDATE *PREFIX*' .
                        $this->table($kind) .
                        ' SET destination_etag = ?, updated_at = ? WHERE id = ? AND actor_id = ?',
                    [$etag, time(), $id, $actor],
                );
            }
            return ['id' => $id, 'kind' => $kind, 'captured' => true];
        });
    }

    /** The browser supplies only its own same-origin DAV ETag observation. */
    public function observeEtag(string $tree, string $actor, string $kind, string $id, array $body): array
    {
        $this->trees->active($actor);
        if ($kind === 'contact') {
            throw Problem::missing();
        }
        $etag = $body['etag'] ?? null;
        if (
            !is_string($etag) ||
            strlen($etag) > 128 ||
            !preg_match('/\A(?:W\/)?"[^"\r\n]{1,120}"\z/D', $etag)
        ) {
            throw new Problem('validation', 'The external item has no usable ETag.');
        }
        $row = $this->row($tree, $actor, $kind, $id);
        $member = $this->trees->access($tree, $actor);
        $current = $this->project($tree, $actor, $kind, $row, $member, $this->identity->current($actor));
        if ($current['status'] !== 'linked' || !$row['destination_etag']) {
            return $current;
        }
        if ($etag !== $row['destination_etag']) {
            $this->store->execute(
                'UPDATE *PREFIX*' .
                    $this->table($kind) .
                    " SET status = 'destination-changed', updated_at = ? WHERE id = ? AND actor_id = ? AND status = 'linked'",
                [time(), $id, $actor],
            );
            $current['status'] = 'destination-changed';
            $current['canRemoveExternal'] = false;
        }
        return $current;
    }

    public function reviewRemoval(string $tree, string $actor, string $kind, string $id): array
    {
        $this->trees->active($actor);
        if ($kind === 'contact') {
            throw Problem::missing();
        }
        $row = $this->row($tree, $actor, $kind, $id);
        $member = $this->trees->access($tree, $actor);
        $current = $this->project($tree, $actor, $kind, $row, $member, $this->identity->current($actor));
        if (!($current['canRemoveExternal'] ?? false)) {
            throw new Problem(
                'conflict',
                'This item cannot be removed safely here. Check the external app.',
                412,
            );
        }
        return [
            'id' => $id,
            'kind' => $kind,
            'collection' => $row['collection_uri'],
            'objectName' => $row['object_name'],
            'etag' => $row['destination_etag'],
            'source' => $current['source'],
            'sourceRevision' => (int) $row['source_revision'],
            'memberRevision' => (int) $row['member_revision'],
            'expiresAt' => time() + 300,
        ];
    }

    /** Confirm only after conditional DAV deletion; a lost response is reconciled by UID. */
    public function completeRemoval(string $tree, string $actor, string $kind, string $id, array $body): array
    {
        $review = $body['review'] ?? null;
        if (!is_array($review) || ($body['acknowledged'] ?? false) !== true) {
            throw new Problem('review_required', 'Review external removal before confirming it.');
        }
        $row = $this->row($tree, $actor, $kind, $id);
        if (
            $kind === 'contact' ||
            $row['status'] !== 'linked' ||
            ($review['id'] ?? null) !== $id ||
            ($review['kind'] ?? null) !== $kind ||
            ($review['etag'] ?? null) !== $row['destination_etag'] ||
            ($review['sourceRevision'] ?? null) !== (int) $row['source_revision'] ||
            ($review['memberRevision'] ?? null) !== (int) $row['member_revision'] ||
            !is_int($review['expiresAt'] ?? null) ||
            $review['expiresAt'] < time() ||
            $review['expiresAt'] > time() + 300
        ) {
            throw new Problem('conflict', 'Review external removal again.', 412);
        }
        // Recheck source and authority after the external operation. A revoked
        // account retains only opaque local cleanup, never an active link.
        if ($this->refresh($tree, $actor, $kind, $id)['status'] !== 'destination-changed') {
            throw new Problem(
                'outcome_unknown',
                'External removal could not be verified. Refresh before retrying.',
                409,
            );
        }
        return $this->unlink($tree, $actor, $kind, $id);
    }
}
