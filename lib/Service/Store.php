<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
use OCP\IDBConnection;
final class Store
{
    public function __construct(public IDBConnection $db) {}
    public function rows(string $sql, array $params = []): array
    {
        return $this->db->executeQuery($sql, $params)->fetchAllAssociative();
    }
    public function one(string $sql, array $params = []): ?array
    {
        return $this->rows($sql, $params)[0] ?? null;
    }
    public function execute(string $sql, array $params = []): int
    {
        return $this->db->executeStatement($sql, $params);
    }
    public function insert(string $table, array $data): void
    {
        $columns = array_keys($data);
        $this->execute(
            'INSERT INTO *PREFIX*' .
                $table .
                ' (' .
                implode(',', $columns) .
                ') VALUES (' .
                implode(',', array_fill(0, count($columns), '?')) .
                ')',
            array_values($data),
        );
    }
    /** Serialize app mutations, including graph cycle tests and membership generations. */
    public function write(callable $fn): mixed
    {
        // Nextcloud applies schema-only migrations on a fresh app installation.
        // Seed the singleton before the transaction; tolerate only a concurrent insert.
        if (!$this->one('SELECT id FROM *PREFIX*stb_state WHERE id = 1')) {
            try {
                $this->execute('INSERT INTO *PREFIX*stb_state (id, guard_value) VALUES (1, 0)');
            } catch (\OCP\DB\Exception $e) {
                if (
                    !in_array(
                        $e->getReason(),
                        [
                            \OCP\DB\Exception::REASON_CONSTRAINT_VIOLATION,
                            \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION,
                        ],
                        true,
                    )
                ) {
                    throw $e;
                }
            }
        }
        $this->db->beginTransaction();
        try {
            if (
                $this->execute('UPDATE *PREFIX*stb_state SET guard_value = guard_value + 1 WHERE id = 1') !==
                1
            ) {
                throw new \RuntimeException('Missing Stammbaum write guard');
            }
            $result = $fn();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    public static function id(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 15) | 64);
        $b[8] = chr((ord($b[8]) & 63) | 128);
        $h = bin2hex($b);
        return substr($h, 0, 8) .
            '-' .
            substr($h, 8, 4) .
            '-' .
            substr($h, 12, 4) .
            '-' .
            substr($h, 16, 4) .
            '-' .
            substr($h, 20);
    }
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    public function version(array $row, mixed $value): void
    {
        if (!is_int($value) || $value < 1) {
            throw new Problem('version_required', 'Reload the current information before saving.', 428);
        }
        if ((int) $row['revision'] !== $value) {
            throw new Problem(
                'conflict',
                'Someone changed this information. Your draft has not been saved. Load and compare the current version.',
                412,
            );
        }
    }
    public function bump(string $tree): void
    {
        $this->execute('UPDATE *PREFIX*stb_trees SET revision = revision + 1, updated_at = ? WHERE id = ?', [
            time(),
            $tree,
        ]);
    }
    public function audit(
        string $tree,
        string $actor,
        string $action,
        ?string $subject,
        array $data,
        string $visibility = 'members',
    ): void {
        $this->insert('stb_history', [
            'id' => self::id(),
            'tree_id' => $tree,
            'actor_id' => $actor,
            'action' => $action,
            'subject_id' => $subject,
            'data_json' => self::json($data),
            'visibility' => $visibility,
            'created_at' => time(),
        ]);
    }
    /** Receipts store identities, never replay private response bodies. Caller reauthorizes results. */
    public function receipt(
        string $actor,
        string $scope,
        string $key,
        array $payload,
        callable $create,
    ): string {
        if (!preg_match('/^[a-zA-Z0-9_-]{16,128}$/D', $key)) {
            throw new Problem('request_id_required', 'A valid request identifier is required.');
        }
        $id = hash('sha256', $actor . '|' . $scope . '|' . $key);
        $hash = hash('sha256', self::json($payload));
        $row = $this->one('SELECT * FROM *PREFIX*stb_receipts WHERE id = ?', [$id]);
        if ($row) {
            if (!hash_equals($row['payload_hash'], $hash)) {
                throw new Problem(
                    'retry_conflict',
                    'This request identifier was used with different information.',
                    409,
                );
            }
            return $row['result_id'];
        }
        $result = $create();
        $this->insert('stb_receipts', [
            'id' => $id,
            'payload_hash' => $hash,
            'result_id' => $result,
            'created_at' => time(),
        ]);
        return $result;
    }
}
