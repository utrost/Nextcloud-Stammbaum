<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;

use OCP\App\IAppManager;
use OCP\Constants;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAddressBook;
use OCP\IUserManager;

/** Account-private links to current CardDAV cards. No card text is stored here. */
final class ContactLinks
{
    public function __construct(
        private Store $store,
        private Trees $trees,
        private People $people,
        private Records $records,
        private IContactsManager $contacts,
        private IAppManager $apps,
        private IUserManager $users,
    ) {}

    private function available(string $actor): bool
    {
        $this->trees->active($actor);
        $user = $this->users->get($actor);
        return $user && $this->apps->isEnabledForUser('contacts', $user) && $this->contacts->isEnabled();
    }

    private function book(string $actor, string $key): IAddressBook
    {
        if (!$this->available($actor)) {
            throw new Problem('unavailable', 'Nextcloud Contacts is not available for this account.', 409);
        }
        foreach (array_slice($this->contacts->getUserAddressBooks(), 0, 50) as $book) {
            if ((string) $book->getKey() === $key && $book->getPermissions() & Constants::PERMISSION_READ) {
                return $book;
            }
        }
        throw Problem::missing();
    }

    public function books(string $tree, string $actor, string $person): array
    {
        $m = $this->trees->access($tree, $actor);
        $this->people->row($tree, $person, $m);
        if (!$this->available($actor)) {
            return ['available' => false, 'items' => []];
        }
        $items = [];
        foreach (array_slice($this->contacts->getUserAddressBooks(), 0, 50) as $book) {
            if (!($book->getPermissions() & Constants::PERMISSION_READ)) {
                continue;
            }
            $items[] = [
                'key' => (string) $book->getKey(),
                'name' => (string) $book->getDisplayName(),
                'shared' => $book->isShared(),
                'system' => $book->isSystemAddressBook(),
                'readOnly' => !($book->getPermissions() & Constants::PERMISSION_UPDATE),
            ];
        }
        return ['available' => true, 'items' => $items];
    }

    private static function fieldEntries(mixed $raw, string $kind): array
    {
        if (!is_array($raw)) {
            $raw = $raw === null ? [] : [$raw];
        } elseif (!array_is_list($raw)) {
            $raw = [$raw];
        }
        $items = [];
        foreach (array_slice($raw, 0, 20) as $entry) {
            $type = is_array($entry) ? $entry['type'] ?? '' : '';
            $value = is_array($entry) && array_key_exists('value', $entry) ? $entry['value'] : $entry;
            $parts = [];
            if ($kind === 'address' && is_string($value) && str_contains($value, ';')) {
                $value = explode(';', $value);
            }
            if ($kind === 'address' && is_array($value)) {
                $parts = array_map(
                    static fn($part): string => is_scalar($part) ? trim((string) $part) : '',
                    array_slice($value, 0, 7),
                );
                $value = implode(';', $parts);
            }
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 2000) {
                continue;
            }
            $value = trim($value);
            $items[] = [
                'value' => $value,
                'display' => $parts
                    ? implode(', ', array_values(array_filter($parts, static fn($v) => $v !== '')))
                    : $value,
                'label' => is_string($type) ? mb_substr(trim($type), 0, 60) : '',
                'components' => $parts,
            ];
        }
        return $items;
    }

    private static function cardView(array $card, IAddressBook $book): ?array
    {
        $uid = $card['UID'] ?? null;
        $uri = $card['URI'] ?? null;
        if (
            !is_string($uid) ||
            $uid === '' ||
            mb_strlen($uid) > 255 ||
            !is_string($uri) ||
            $uri === '' ||
            mb_strlen($uri) > 255
        ) {
            return null;
        }
        $name = $card['FN'] ?? '';
        $name = is_string($name) ? mb_substr(trim($name), 0, 250) : '';
        $birthday = $card['BDAY'] ?? null;
        $birthday = is_string($birthday) ? trim($birthday) : '';
        $fields = [
            'email' => self::fieldEntries($card['EMAIL'] ?? null, 'email'),
            'phone' => self::fieldEntries($card['TEL'] ?? null, 'phone'),
            'address' => self::fieldEntries($card['ADR'] ?? null, 'address'),
        ];
        return [
            'bookKey' => (string) $book->getKey(),
            'bookName' => (string) $book->getDisplayName(),
            'cardUid' => $uid,
            'cardUri' => $uri,
            'name' => $name ?: 'Unnamed contact',
            'fields' => $fields,
            'birthdayPresent' => (bool) preg_match(
                '/\A(?:\d{4}-?\d{2}-?\d{2}|--\d{2}-?\d{2})\z/D',
                $birthday,
            ),
            'hash' => hash('sha256', Store::json([$uid, $uri, $name, $fields, $birthday])),
        ];
    }

    private function card(string $actor, string $bookKey, string $uid, string $uri): array
    {
        $book = $this->book($actor, $bookKey);
        if ($uid === '' || mb_strlen($uid) > 255 || $uri === '' || mb_strlen($uri) > 255) {
            throw Problem::missing();
        }
        foreach (
            $book->search($uid, ['UID'], ['types' => true, 'wildcard' => false, 'limit' => 10, 'offset' => 0])
            as $raw
        ) {
            if (($raw['UID'] ?? null) === $uid && ($raw['URI'] ?? null) === $uri) {
                return self::cardView($raw, $book) ?? throw Problem::missing();
            }
        }
        throw Problem::missing();
    }

    public function search(string $tree, string $actor, array $b): array
    {
        $person = Values::text($b['personId'] ?? null, 'personId', 36, true);
        $m = $this->trees->access($tree, $actor);
        $this->people->row($tree, $person, $m);
        $query = Values::text($b['query'] ?? null, 'query', 100, true);
        if (mb_strlen($query) < 3) {
            throw new Problem('validation', 'Enter at least three characters to search contacts.');
        }
        $page = $b['page'] ?? 0;
        if (!is_int($page) || $page < 0 || $page > 40) {
            throw new Problem('validation', 'Choose a valid search page.');
        }
        $book = $this->book($actor, Values::text($b['bookKey'] ?? null, 'bookKey', 128, true));
        $raw = $book->search(
            $query,
            ['FN', 'EMAIL', 'TEL', 'ORG'],
            ['types' => true, 'limit' => 26, 'offset' => $page * 25],
        );
        $items = array_values(
            array_filter(array_map(fn($c) => self::cardView($c, $book), array_slice($raw, 0, 25))),
        );
        return ['items' => $items, 'nextPage' => count($raw) > 25 ? $page + 1 : null];
    }

    public function reviewLink(string $tree, string $actor, array $b): array
    {
        $person = Values::text($b['personId'] ?? null, 'personId', 36, true);
        $m = $this->trees->access($tree, $actor);
        $p = $this->people->row($tree, $person, $m);
        $card = $this->card(
            $actor,
            Values::text($b['bookKey'] ?? null, 'bookKey', 128, true),
            Values::text($b['cardUid'] ?? null, 'cardUid', 255, true),
            Values::text($b['cardUri'] ?? null, 'cardUri', 255, true),
        );
        return [
            'personId' => $person,
            'personRevision' => (int) $p['revision'],
            'memberRevision' => (int) $m['revision'],
            'card' => $card,
            'expiresAt' => time() + 300,
        ];
    }

    private static function confirmedReview(array $review, array $current, array $keys): void
    {
        foreach ($keys as $key) {
            if (($review[$key] ?? null) !== $current[$key]) {
                throw new Problem('conflict', 'The person, card or your access changed. Review again.', 412);
            }
        }
        if (
            !is_int($review['expiresAt'] ?? null) ||
            $review['expiresAt'] < time() ||
            $review['expiresAt'] > time() + 300
        ) {
            throw new Problem('review_expired', 'Review this contact again.', 412);
        }
    }

    public function link(string $tree, string $actor, array $b): array
    {
        $review = $b['review'] ?? null;
        $requestId = $b['requestId'] ?? null;
        if (
            !is_array($review) ||
            !is_string($requestId) ||
            !preg_match('/\A[a-zA-Z0-9-]{8,80}\z/D', $requestId)
        ) {
            throw new Problem('review_required', 'Review this contact before linking it.');
        }
        $card = $review['card'] ?? null;
        if (!is_array($card)) {
            throw Problem::missing();
        }
        $current = $this->reviewLink($tree, $actor, [
            'personId' => $review['personId'] ?? null,
            'bookKey' => $card['bookKey'] ?? null,
            'cardUid' => $card['cardUid'] ?? null,
            'cardUri' => $card['cardUri'] ?? null,
        ]);
        self::confirmedReview($review, $current, ['personId', 'personRevision', 'memberRevision', 'card']);
        return $this->store->write(function () use ($tree, $actor, $review, $requestId, $current) {
            $m = $this->trees->access($tree, $actor);
            $person = $this->people->row($tree, $review['personId'], $m);
            if ((int) $m['revision'] !== $current['memberRevision']) {
                throw new Problem('conflict', 'The person, card or your access changed. Review again.', 412);
            }
            if ((int) $person['revision'] !== $current['personRevision']) {
                throw new Problem('conflict', 'The person changed. Review again.', 412);
            }
            $card = $current['card'];
            $prior = $this->store->one(
                'SELECT * FROM *PREFIX*stb_contact_links WHERE actor_id = ? AND tree_id = ? AND request_id = ?',
                [$actor, $tree, $requestId],
            );
            if ($prior) {
                if (
                    $prior['person_id'] !== $review['personId'] ||
                    $prior['book_key'] !== $card['bookKey'] ||
                    $prior['card_uid'] !== $card['cardUid'] ||
                    $prior['card_uri'] !== $card['cardUri']
                ) {
                    throw new Problem(
                        'retry_conflict',
                        'This link request was already used for another card.',
                        409,
                    );
                }
                return $this->linkDto($actor, $prior);
            }
            $prior = $this->store->one(
                'SELECT * FROM *PREFIX*stb_contact_links WHERE actor_id = ? AND tree_id = ? AND person_id = ? AND book_key = ? AND card_uid = ? AND member_revision = ?',
                [$actor, $tree, $review['personId'], $card['bookKey'], $card['cardUid'], $m['revision']],
            );
            if ($prior) {
                return $this->linkDto($actor, $prior);
            }
            $id = Store::id();
            $this->store->insert('stb_contact_links', [
                'id' => $id,
                'tree_id' => $tree,
                'person_id' => $review['personId'],
                'actor_id' => $actor,
                'book_key' => $card['bookKey'],
                'card_uri' => $card['cardUri'],
                'card_uid' => $card['cardUid'],
                'card_hash' => $card['hash'],
                'request_id' => $requestId,
                'member_revision' => (int) $m['revision'],
                'created_at' => time(),
            ]);
            return $this->linkDto($actor, $this->linkRow($tree, $actor, $id));
        });
    }

    private function linkRow(string $tree, string $actor, string $id): array
    {
        return $this->store->one(
            'SELECT * FROM *PREFIX*stb_contact_links WHERE tree_id = ? AND actor_id = ? AND id = ?',
            [$tree, $actor, $id],
        ) ?? throw Problem::missing();
    }

    private function linkDto(string $actor, array $link): array
    {
        try {
            $card = $this->card($actor, $link['book_key'], $link['card_uid'], $link['card_uri']);
            return [
                'id' => $link['id'],
                'status' => $card['hash'] === $link['card_hash'] ? 'current' : 'changed',
                'card' => $card,
                'createdAt' => (int) $link['created_at'],
            ];
        } catch (Problem $e) {
            if (!in_array($e->errorCode, ['not_found', 'unavailable'], true)) {
                throw $e;
            }
            return [
                'id' => $link['id'],
                'status' => 'unavailable',
                'card' => null,
                'createdAt' => (int) $link['created_at'],
            ];
        }
    }

    public function links(string $tree, string $actor, string $person, int $page = 0): array
    {
        $m = $this->trees->access($tree, $actor);
        $this->people->row($tree, $person, $m);
        if ($page < 0 || $page > 400) {
            throw new Problem('validation', 'Choose a valid link page.');
        }
        $rows = $this->store->rows(
            'SELECT * FROM *PREFIX*stb_contact_links WHERE tree_id = ? AND person_id = ? AND actor_id = ? AND member_revision = ? ORDER BY created_at DESC, id DESC LIMIT 26 OFFSET ' .
                $page * 25,
            [$tree, $person, $actor, $m['revision']],
        );
        return [
            'items' => array_map(fn($r) => $this->linkDto($actor, $r), array_slice($rows, 0, 25)),
            'nextPage' => count($rows) > 25 ? $page + 1 : null,
        ];
    }

    private function activeLink(string $tree, string $actor, string $id, string $need = 'viewer'): array
    {
        $link = $this->linkRow($tree, $actor, $id);
        $m = $this->trees->access($tree, $actor, $need);
        $this->people->row($tree, $link['person_id'], $m);
        if ((int) $link['member_revision'] !== (int) $m['revision']) {
            throw Problem::missing();
        }
        return [$link, $m];
    }

    public function reviewCopy(string $tree, string $actor, string $id, array $b): array
    {
        // #24 requires management for all genealogy contact records. Editors may link, but cannot copy.
        [$link, $m] = $this->activeLink($tree, $actor, $id, 'manager');
        $card = $this->card($actor, $link['book_key'], $link['card_uid'], $link['card_uri']);
        $kind = Values::choice($b['kind'] ?? null, ['email', 'phone', 'address'], 'kind');
        $index = $b['index'] ?? null;
        if (!is_int($index) || $index < 0 || $index >= count($card['fields'][$kind])) {
            throw Problem::missing();
        }
        $mode = Values::choice($b['mode'] ?? null, ['append', 'replace'], 'mode');
        $rows = $this->store->rows(
            "SELECT id, record_type, revision, data_json FROM *PREFIX*stb_records WHERE tree_id = ? AND origin_id = ? AND category = 'contact' ORDER BY created_at DESC, id DESC LIMIT 101",
            [$tree, $link['person_id']],
        );
        if (count($rows) > 100) {
            throw new Problem('capacity', 'Too many contact records to compare safely.', 409);
        }
        $current = [];
        foreach ($rows as $r) {
            $d = json_decode($r['data_json'], true);
            $current[] = [
                'id' => $r['id'],
                'kind' => $r['record_type'],
                'title' => $d['title'],
                'value' => $d['text'],
                'revision' => (int) $r['revision'],
            ];
        }
        $replaceId =
            $mode === 'replace' ? Values::text($b['replaceId'] ?? null, 'replaceId', 36, true) : null;
        $target = null;
        if ($replaceId !== null) {
            foreach ($current as $r) {
                if ($r['id'] === $replaceId && $r['kind'] === $kind) {
                    $target = $r;
                    break;
                }
            }
            if (!$target) {
                throw Problem::missing();
            }
        }
        $person = $this->people->row($tree, $link['person_id'], $m);
        return [
            'linkId' => $id,
            'kind' => $kind,
            'index' => $index,
            'mode' => $mode,
            'replaceId' => $replaceId,
            'replaceRevision' => $target['revision'] ?? null,
            'current' => $current,
            'proposed' => $card['fields'][$kind][$index],
            'cardHash' => $card['hash'],
            'personRevision' => (int) $person['revision'],
            'memberRevision' => (int) $m['revision'],
            'treeRevision' => $this->trees->get($tree, $actor)['revision'],
            'expiresAt' => time() + 300,
            'retentionWarning' =>
                'This management-only genealogy copy remains after the card changes, becomes unavailable or is unlinked.',
        ];
    }

    public function copy(string $tree, string $actor, string $id, array $b): array
    {
        $review = $b['review'] ?? null;
        $requestId = $b['requestId'] ?? null;
        if (
            !is_array($review) ||
            !is_string($requestId) ||
            !preg_match('/\A[a-zA-Z0-9-]{8,80}\z/D', $requestId)
        ) {
            throw new Problem('review_required', 'Review this contact field before copying it.');
        }
        if (($b['acknowledged'] ?? false) !== true) {
            throw new Problem(
                'acknowledgement',
                'Acknowledge that the genealogy copy is retained independently.',
            );
        }
        $current = $this->reviewCopy($tree, $actor, $id, [
            'kind' => $review['kind'] ?? null,
            'index' => $review['index'] ?? null,
            'mode' => $review['mode'] ?? null,
            'replaceId' => $review['replaceId'] ?? null,
        ]);
        self::confirmedReview($review, $current, [
            'linkId',
            'kind',
            'index',
            'mode',
            'replaceId',
            'replaceRevision',
            'proposed',
            'cardHash',
            'personRevision',
            'memberRevision',
            'treeRevision',
        ]);
        [$link] = $this->activeLink($tree, $actor, $id, 'manager');
        $kind = $current['kind'];
        $entry = $current['proposed'];
        $fields = [['label' => 'Source', 'value' => 'Nextcloud Contacts', 'unit' => '']];
        if ($entry['label'] !== '') {
            $fields[] = ['label' => 'Card label', 'value' => $entry['label'], 'unit' => ''];
        }
        if ($kind === 'address') {
            foreach (
                ['PO box', 'Extended', 'Street', 'City', 'Region', 'Postal code', 'Country']
                as $i => $label
            ) {
                if (($entry['components'][$i] ?? '') !== '') {
                    $fields[] = ['label' => $label, 'value' => $entry['components'][$i], 'unit' => ''];
                }
            }
        }
        return $this->records->save($tree, $current['replaceId'], $actor, [
            'originId' => $link['person_id'],
            'category' => 'contact',
            'type' => $kind,
            'title' => ucfirst($kind) . ' copied from Nextcloud Contacts',
            'text' => $entry['display'],
            'fields' => $fields,
            'visibility' => 'management',
            'revision' => $current['replaceRevision'],
            'requestId' => $requestId,
        ]);
    }

    public function birthdaySignal(string $tree, string $actor, string $person): array
    {
        $m = $this->trees->access($tree, $actor);
        $this->people->row($tree, $person, $m);
        $rows = $this->store->rows(
            'SELECT * FROM *PREFIX*stb_contact_links WHERE tree_id = ? AND person_id = ? AND actor_id = ? AND member_revision = ? ORDER BY created_at DESC LIMIT 25',
            [$tree, $person, $actor, $m['revision']],
        );
        foreach ($rows as $r) {
            try {
                if ($this->card($actor, $r['book_key'], $r['card_uid'], $r['card_uri'])['birthdayPresent']) {
                    return ['linkedContactBirthday' => true];
                }
            } catch (Problem $e) {
                if (!in_array($e->errorCode, ['not_found', 'unavailable'], true)) {
                    throw $e;
                }
            }
        }
        return ['linkedContactBirthday' => false];
    }

    public function unlink(string $tree, string $actor, string $id): array
    {
        // Allow private cleanup even after the actor loses this tree; return no source metadata.
        $this->trees->active($actor);
        return $this->store->write(function () use ($tree, $actor, $id) {
            $link = $this->linkRow($tree, $actor, $id);
            $this->store->execute('DELETE FROM *PREFIX*stb_contact_links WHERE id = ? AND actor_id = ?', [
                $link['id'],
                $actor,
            ]);
            return ['removed' => true];
        });
    }
}
