<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;

/** Stable only for one Nextcloud account incarnation; never derived from a reusable UID. */
final class ConnectionIdentity
{
    public function __construct(private Store $store) {}

    public function current(string $actor): string
    {
        $row = $this->store->one('SELECT incarnation FROM *PREFIX*stb_connection_actors WHERE actor_id = ?', [
            $actor,
        ]);
        if ($row) {
            return $row['incarnation'];
        }
        $id = Store::id();
        try {
            $this->store->insert('stb_connection_actors', [
                'actor_id' => $actor,
                'incarnation' => $id,
                'created_at' => time(),
            ]);
            return $id;
        } catch (\OCP\DB\Exception $e) {
            $row = $this->store->one(
                'SELECT incarnation FROM *PREFIX*stb_connection_actors WHERE actor_id = ?',
                [$actor],
            );
            if ($row) {
                return $row['incarnation'];
            }
            throw $e;
        }
    }
}
