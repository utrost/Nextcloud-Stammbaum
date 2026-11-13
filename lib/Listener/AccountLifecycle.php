<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Listener;
use OCA\Stammbaum\Service\Store;
use OCP\EventDispatcher\{Event, IEventListener};
use OCP\User\Events\{UserDeletedEvent, UserCreatedEvent, UserChangedEvent, PasswordUpdatedEvent};
/** @implements IEventListener<Event> */
final class AccountLifecycle implements IEventListener
{
    public function __construct(private Store $store) {}
    public function handle(Event $event): void
    {
        $remove = $event instanceof UserDeletedEvent || $event instanceof UserCreatedEvent;
        $invalidate =
            $remove ||
            $event instanceof PasswordUpdatedEvent ||
            ($event instanceof UserChangedEvent &&
                $event->getFeature() === 'enabled' &&
                $event->getValue() === false);
        if (!$invalidate) {
            return;
        }
        $uid = $event->getUser()->getUID();
        $this->store->write(function () use ($uid, $remove) {
            if ($remove) {
                $this->store->execute('DELETE FROM *PREFIX*stb_preferences WHERE user_id = ?', [$uid]);
                $this->store->execute('DELETE FROM *PREFIX*stb_task_links WHERE actor_id = ?', [$uid]);
                $this->store->execute('DELETE FROM *PREFIX*stb_calendar_links WHERE actor_id = ?', [$uid]);
                $this->store->execute('DELETE FROM *PREFIX*stb_contact_links WHERE actor_id = ?', [$uid]);
                $this->store->execute('DELETE FROM *PREFIX*stb_connection_actors WHERE actor_id = ?', [$uid]);
            } else {
                // A pending DAV write cannot survive account or credential invalidation.
                $this->store->execute(
                    "UPDATE *PREFIX*stb_task_links SET status = 'authority-invalidated' WHERE actor_id = ? AND status = 'pending'",
                    [$uid],
                );
                $this->store->execute(
                    "UPDATE *PREFIX*stb_calendar_links SET status = 'authority-invalidated' WHERE actor_id = ? AND status = 'pending'",
                    [$uid],
                );
                $this->store->execute(
                    "UPDATE *PREFIX*stb_contact_links SET status = 'authority-invalidated', updated_at = ? WHERE actor_id = ? AND status = 'linked'",
                    [time(), $uid],
                );
            }
            $rows = $this->store->rows('SELECT tree_id FROM *PREFIX*stb_members WHERE user_id = ?', [$uid]);
            $this->store->execute(
                'UPDATE *PREFIX*stb_members SET revision = revision + 1' .
                    ($remove ? ', active = 0, export_allowed = 0' : '') .
                    ' WHERE user_id = ?',
                [$uid],
            );
            $this->store->execute('UPDATE *PREFIX*stb_invites SET used = 1 WHERE issuer_id = ?', [$uid]);
            foreach ($rows as $r) {
                $this->store->bump($r['tree_id']);
                $this->store->audit(
                    $r['tree_id'],
                    $uid,
                    $remove ? 'account_memberships_revoked' : 'account_authority_invalidated',
                    null,
                    [],
                    'management',
                );
            }
        });
    }
}
