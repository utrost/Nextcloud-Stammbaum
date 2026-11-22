<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;

final class ToolCredentials
{
    private const ROLES = ['tree.read' => 'viewer', 'tree.write' => 'editor'];
    private const ACTIVE_LIMIT = 20;
    private const ISSUANCE_LIMIT = 20;
    private const ISSUANCE_WINDOW = 900;

    public function __construct(private Store $store, private Trees $trees) {}

    public function list(string $actor): array
    {
        $this->trees->active($actor);
        return array_map(
            $this->metadata(...),
            $this->store->rows(
                'SELECT * FROM *PREFIX*stb_tool_credentials WHERE actor_id = ? AND revoked_at IS NULL AND expires_at > ? ORDER BY created_at DESC, id',
                [$actor, time()],
            ),
        );
    }

    public function issue(string $actor, array $body): array
    {
        $this->recordIssuanceAttempt($actor);
        $label = Values::text($body['label'] ?? '', 'label', 100, true);
        $scopes = $this->selection(
            $body['scopes'] ?? null,
            count(self::ROLES),
            static fn($value) => isset(self::ROLES[$value]),
        );
        $treeIds = $this->selection(
            $body['treeIds'] ?? null,
            100,
            static fn($id) => preg_match('/\A[0-9a-f-]{36}\z/', $id) === 1,
        );
        $days = $body['expiresInDays'] ?? null;
        $requestId = $body['requestId'] ?? null;
        if (!is_int($days) || $days < 1 || $days > 365) {
            throw new Problem('credential_expiry', 'Choose a credential expiry from 1 to 365 days.');
        }
        if (!is_string($requestId)) {
            throw new Problem('request_id_required', 'A valid request identifier is required.');
        }

        return $this->store->write(function () use ($actor, $label, $scopes, $treeIds, $days, $requestId) {
            $this->trees->active($actor);
            $secret = null;
            $id = $this->store->receipt(
                $actor,
                'tool-credential',
                $requestId,
                [$label, $scopes, $treeIds, $days],
                function () use ($actor, $label, $scopes, $treeIds, $days, &$secret) {
                    $now = time();
                    $active = $this->store->one(
                        'SELECT COUNT(*) AS total FROM *PREFIX*stb_tool_credentials WHERE actor_id = ? AND revoked_at IS NULL AND expires_at > ?',
                        [$actor, $now],
                    );
                    if ((int) $active['total'] >= self::ACTIVE_LIMIT) {
                        throw new Problem(
                            'credential_limit',
                            'Revoke an existing credential before creating another.',
                            409,
                        );
                    }
                    $grants = [];
                    foreach ($treeIds as $tree) {
                        $member = $this->trees->access($tree, $actor);
                        $grants[$tree] = ['id' => $member['id'], 'revision' => (int) $member['revision']];
                    }
                    $id = Store::id();
                    $secret = 'stb_' . bin2hex(random_bytes(32));
                    $this->store->insert('stb_tool_credentials', [
                        'id' => $id,
                        'actor_id' => $actor,
                        'label' => $label,
                        'secret_hash' => hash('sha256', $secret),
                        'scopes_json' => Store::json($scopes),
                        'grants_json' => Store::json($grants),
                        'created_at' => $now,
                        'expires_at' => $now + $days * 86400,
                    ]);
                    return $id;
                },
            );
            $row = $this->owned($actor, $id);
            return $this->metadata($row) + ($secret === null ? [] : ['secret' => $secret]);
        });
    }

    public function revoke(string $actor, string $id): void
    {
        $this->store->write(function () use ($actor, $id) {
            $this->trees->active($actor);
            $this->owned($actor, $id);
            $this->store->execute(
                'UPDATE *PREFIX*stb_tool_credentials SET revoked_at = ? WHERE actor_id = ? AND id = ? AND revoked_at IS NULL',
                [time(), $actor, $id],
            );
        });
    }

    public function revokeAll(string $actor): void
    {
        $this->store->write(function () use ($actor) {
            $this->trees->active($actor);
            $this->invalidate($actor);
        });
    }

    /** Called under the write guard by account lifecycle and recovery. */
    public function invalidate(?string $actor = null): void
    {
        $this->store->execute(
            'UPDATE *PREFIX*stb_tool_credentials SET revoked_at = ? WHERE revoked_at IS NULL' .
                ($actor === null ? '' : ' AND actor_id = ?'),
            $actor === null ? [time()] : [time(), $actor],
        );
    }

    /** Authorize one tool operation. Write callers must hold the same guard through the operation. */
    public function authorize(string $secret, string $tree, string $scope): array
    {
        $row =
            preg_match('/\Astb_[a-f0-9]{64}\z/', $secret) === 1
                ? $this->store->one(
                    'SELECT * FROM *PREFIX*stb_tool_credentials WHERE secret_hash = ? AND revoked_at IS NULL AND expires_at > ?',
                    [hash('sha256', $secret), time()],
                )
                : null;
        if (!$row) {
            throw new Problem('credential_invalid', 'This credential is invalid or expired.', 401);
        }
        $actor = $row['actor_id'];
        $this->trees->active($actor);
        $grant = json_decode($row['grants_json'], true, 32, JSON_THROW_ON_ERROR)[$tree] ?? null;
        if ($grant === null) {
            throw Problem::missing();
        }
        $member = $this->trees->access($tree, $actor);
        if ($member['id'] !== $grant['id'] || (int) $member['revision'] !== $grant['revision']) {
            throw Problem::missing();
        }
        if (
            !isset(self::ROLES[$scope]) ||
            !in_array($scope, json_decode($row['scopes_json'], true, 32, JSON_THROW_ON_ERROR), true)
        ) {
            throw new Problem('credential_scope', 'This credential does not allow this operation.', 403);
        }
        $this->trees->access($tree, $actor, self::ROLES[$scope]);
        return ['credentialId' => $row['id'], 'actorId' => $actor];
    }

    private function recordIssuanceAttempt(string $actor): void
    {
        $this->store->write(function () use ($actor) {
            $this->trees->active($actor);
            $now = time();
            $row = $this->store->one('SELECT * FROM *PREFIX*stb_tool_issue_limits WHERE actor_id = ?', [
                $actor,
            ]);
            if (!$row) {
                $this->store->insert('stb_tool_issue_limits', [
                    'actor_id' => $actor,
                    'window_start' => $now,
                    'attempts' => 1,
                ]);
                return;
            }
            $until = (int) $row['window_start'] + self::ISSUANCE_WINDOW;
            if ($now >= $until) {
                $this->store->execute(
                    'UPDATE *PREFIX*stb_tool_issue_limits SET window_start = ?, attempts = 1 WHERE actor_id = ?',
                    [$now, $actor],
                );
            } elseif ((int) $row['attempts'] >= self::ISSUANCE_LIMIT) {
                throw new Problem(
                    'credential_issuance_rate',
                    'Too many credential requests. Try again later.',
                    429,
                    ['retryAfter' => $until - $now],
                );
            } else {
                $this->store->execute(
                    'UPDATE *PREFIX*stb_tool_issue_limits SET attempts = attempts + 1 WHERE actor_id = ?',
                    [$actor],
                );
            }
        });
    }

    private function selection(mixed $value, int $limit, callable $valid): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > $limit) {
            throw new Problem(
                'credential_selection',
                'Choose supported scopes and existing trees for this credential.',
            );
        }
        foreach ($value as $item) {
            if (!is_string($item) || !$valid($item)) {
                throw new Problem(
                    'credential_selection',
                    'Choose supported scopes and existing trees for this credential.',
                );
            }
        }
        $value = array_values(array_unique($value));
        sort($value, SORT_STRING);
        return $value;
    }

    private function owned(string $actor, string $id): array
    {
        return $this->store->one('SELECT * FROM *PREFIX*stb_tool_credentials WHERE actor_id = ? AND id = ?', [
            $actor,
            $id,
        ]) ?? throw Problem::missing();
    }

    private function metadata(array $row): array
    {
        return [
            'id' => $row['id'],
            'label' => $row['label'],
            'scopes' => json_decode($row['scopes_json'], true, 32, JSON_THROW_ON_ERROR),
            'treeIds' => array_keys(json_decode($row['grants_json'], true, 32, JSON_THROW_ON_ERROR)),
            'createdAt' => (int) $row['created_at'],
            'expiresAt' => (int) $row['expires_at'],
            'revokedAt' => $row['revoked_at'] === null ? null : (int) $row['revoked_at'],
        ];
    }
}
