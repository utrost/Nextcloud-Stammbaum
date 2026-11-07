<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
use OCP\IUserManager;
final class Trees
{
    public const ROLES = ['viewer', 'editor', 'manager', 'owner'];
    public function __construct(private Store $store, private IUserManager $users) {}
    public function active(string $actor): void
    {
        $u = $this->users->get($actor);
        if (!$u || !$u->isEnabled()) {
            throw new Problem('unauthenticated', 'Sign in to continue.', 401);
        }
    }
    public function access(string $tree, string $actor, string $need = 'viewer'): array
    {
        $this->active($actor);
        $m = $this->store->one(
            'SELECT * FROM *PREFIX*stb_members WHERE tree_id = ? AND user_id = ? AND active = 1',
            [$tree, $actor],
        );
        if (!$m) {
            throw Problem::missing();
        }
        if (array_search($m['role'], self::ROLES, true) < array_search($need, self::ROLES, true)) {
            throw new Problem('forbidden', 'Your tree role does not allow this action.', 403);
        }
        return $m;
    }
    public static function management(array $m): bool
    {
        return in_array($m['role'], ['manager', 'owner'], true);
    }
    public function list(string $actor): array
    {
        $this->active($actor);
        $rows = $this->store->rows(
            'SELECT t.*, m.role, m.export_allowed FROM *PREFIX*stb_trees t INNER JOIN *PREFIX*stb_members m ON t.id = m.tree_id WHERE m.user_id = ? AND m.active = 1 ORDER BY t.name, t.id',
            [$actor],
        );
        // Resolve shared home through the same visibility check as a direct tree read.
        return array_map(fn($r) => $this->get($r['id'], $actor), $rows);
    }
    private function dto(array $r): array
    {
        return [
            'id' => $r['id'],
            'name' => $r['name'],
            'description' => $r['description'],
            'revision' => (int) $r['revision'],
            'role' => $r['role'],
            'canExport' => in_array($r['role'], ['manager', 'owner'], true) || (bool) $r['export_allowed'],
            'homeId' => $r['home_id'],
            'updatedAt' => (int) $r['updated_at'],
        ];
    }
    public function get(string $id, string $actor): array
    {
        $m = $this->access($id, $actor);
        $r = $this->store->one('SELECT * FROM *PREFIX*stb_trees WHERE id = ?', [$id]);
        if (!$r) {
            throw Problem::missing();
        }
        $r['role'] = $m['role'];
        $r['export_allowed'] = $m['export_allowed'];
        if ($r['home_id']) {
            $p = $this->store->one(
                'SELECT visibility FROM *PREFIX*stb_people WHERE id = ? AND tree_id = ? AND deleted = 0',
                [$r['home_id'], $id],
            );
            if (!$p || (!self::management($m) && $p['visibility'] !== 'members')) {
                $r['home_id'] = null;
            }
        }
        return $this->dto($r) + ['membershipRevision' => (int) $m['revision']];
    }
    public function create(string $actor, array $b): array
    {
        return $this->store->write(function () use ($actor, $b) {
            $this->active($actor);
            $name = Values::text($b['name'] ?? '', 'name', 200, true);
            $description = Values::text($b['description'] ?? '', 'description', 2000);
            $id = $this->store->receipt(
                $actor,
                'tree',
                (string) ($b['requestId'] ?? ''),
                [$name, $description],
                function () use ($actor, $name, $description) {
                    $id = Store::id();
                    $now = time();
                    $this->store->insert('stb_trees', [
                        'id' => $id,
                        'name' => $name,
                        'description' => $description,
                        'revision' => 1,
                        'home_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $this->store->insert('stb_members', [
                        'id' => Store::id(),
                        'tree_id' => $id,
                        'user_id' => $actor,
                        'role' => 'owner',
                        'export_allowed' => 0,
                        'active' => 1,
                        'revision' => 1,
                    ]);
                    $this->store->audit($id, $actor, 'tree_created', null, [], 'management');
                    return $id;
                },
            );
            return $this->get($id, $actor);
        });
    }
    public function update(string $id, string $actor, array $b): array
    {
        return $this->store->write(function () use ($id, $actor, $b) {
            $this->access($id, $actor, 'manager');
            $r = $this->store->one('SELECT * FROM *PREFIX*stb_trees WHERE id = ?', [$id]);
            $this->store->version($r, $b['revision'] ?? null);
            $name = Values::text($b['name'] ?? '', 'name', 200, true);
            $desc = Values::text($b['description'] ?? '', 'description', 2000);
            $home = $b['homeId'] ?? null;
            if (
                $home !== null &&
                (!is_string($home) ||
                    !$this->store->one(
                        'SELECT id FROM *PREFIX*stb_people WHERE id = ? AND tree_id = ? AND deleted = 0',
                        [$home, $id],
                    ))
            ) {
                throw Problem::missing();
            }
            $this->store->execute(
                'UPDATE *PREFIX*stb_trees SET name = ?, description = ?, home_id = ? WHERE id = ?',
                [$name, $desc, $home, $id],
            );
            $this->store->bump($id);
            $this->store->audit($id, $actor, 'tree_updated', null, [], 'management');
            return $this->get($id, $actor);
        });
    }
    public function members(string $id, string $actor): array
    {
        $this->access($id, $actor, 'manager');
        return array_map(function ($m) {
            $u = $this->users->get($m['user_id']);
            return [
                'userId' => $m['user_id'],
                'displayName' => $u?->getDisplayName() ?? $m['user_id'],
                'enabled' => $u?->isEnabled() ?? false,
                'active' => (bool) $m['active'],
                'role' => $m['role'],
                'exportAllowed' => (bool) $m['export_allowed'],
                'revision' => (int) $m['revision'],
            ];
        }, $this->store->rows('SELECT * FROM *PREFIX*stb_members WHERE tree_id = ? ORDER BY user_id', [$id]));
    }
    private function lastOwner(string $tree, string $target): void
    {
        foreach (
            $this->store->rows(
                'SELECT user_id FROM *PREFIX*stb_members WHERE tree_id = ? AND active = 1 AND role = ?',
                [$tree, 'owner'],
            )
            as $r
        ) {
            if ($r['user_id'] !== $target && $this->users->get($r['user_id'])?->isEnabled()) {
                return;
            }
        }
        throw new Problem(
            'last_owner',
            'Appoint another active owner before leaving or changing the last owner.',
            409,
        );
    }
    public function member(string $id, string $actor, array $b): array
    {
        return $this->store->write(function () use ($id, $actor, $b) {
            $target = Values::text($b['userId'] ?? '', 'userId', 64, true);
            $account = $this->users->get($target);
            if ($account) {
                $target = $account->getUID();
            }
            $self = $target === $actor;
            $remove = ($b['remove'] ?? false) === true;
            $caller = $this->access($id, $actor, $self && $remove ? 'viewer' : 'manager');
            $old = $this->store->one('SELECT * FROM *PREFIX*stb_members WHERE tree_id = ? AND user_id = ?', [
                $id,
                $target,
            ]);
            if ($old) {
                $this->store->version($old, $b['revision'] ?? null);
            } elseif (($b['revision'] ?? null) !== 0) {
                throw new Problem('conflict', 'Reload the membership list.', 412);
            }
            $role = Values::choice($b['role'] ?? 'viewer', self::ROLES, 'role');
            if (!$remove && !$this->users->get($target)?->isEnabled()) {
                throw new Problem('account_unavailable', 'Choose an existing active Nextcloud account.');
            }
            if (
                !($self && $remove) &&
                $caller['role'] !== 'owner' &&
                ($self ||
                    in_array($role, ['manager', 'owner'], true) ||
                    ($old && in_array($old['role'], ['manager', 'owner'], true)))
            ) {
                throw new Problem('forbidden', 'Only an owner can manage manager and owner grants.', 403);
            }
            if (
                $old &&
                (int) $old['active'] === 1 &&
                $old['role'] === 'owner' &&
                ($remove || $role !== 'owner')
            ) {
                $this->lastOwner($id, $target);
            }
            $export = ($b['exportAllowed'] ?? false) === true ? 1 : 0;
            if ($old) {
                $this->store->execute(
                    'UPDATE *PREFIX*stb_members SET role = ?, export_allowed = ?, active = ?, revision = revision + 1 WHERE id = ?',
                    [$remove ? $old['role'] : $role, $remove ? 0 : $export, $remove ? 0 : 1, $old['id']],
                );
            } elseif (!$remove) {
                $this->store->insert('stb_members', [
                    'id' => Store::id(),
                    'tree_id' => $id,
                    'user_id' => $target,
                    'role' => $role,
                    'export_allowed' => $export,
                    'active' => 1,
                    'revision' => 1,
                ]);
            } else {
                throw Problem::missing();
            }
            $this->store->execute(
                'UPDATE *PREFIX*stb_invites SET used = 1 WHERE tree_id = ? AND issuer_id = ?',
                [$id, $target],
            );
            $this->store->bump($id);
            $this->store->audit(
                $id,
                $actor,
                $remove ? 'member_removed' : 'member_updated',
                null,
                ['userId' => $target, 'role' => $role, 'exportAllowed' => (bool) $export],
                'management',
            );
            return ['ok' => true];
        });
    }
    public function invite(string $id, string $actor, array $b): array
    {
        return $this->store->write(function () use ($id, $actor, $b) {
            $m = $this->access($id, $actor, 'manager');
            $role = Values::choice($b['role'] ?? 'viewer', self::ROLES, 'role');
            if ($m['role'] !== 'owner' && in_array($role, ['manager', 'owner'], true)) {
                throw new Problem('forbidden', 'Only owners can invite managers and owners.', 403);
            }
            $token = bin2hex(random_bytes(32));
            $expiry = time() + 172800;
            $this->store->insert('stb_invites', [
                'id' => Store::id(),
                'tree_id' => $id,
                'token_hash' => hash('sha256', $token),
                'issuer_id' => $actor,
                'issuer_revision' => (int) $m['revision'],
                'role' => $role,
                'export_allowed' => ($b['exportAllowed'] ?? false) === true ? 1 : 0,
                'expires_at' => $expiry,
                'used' => 0,
            ]);
            $this->store->audit($id, $actor, 'invitation_created', null, ['role' => $role], 'management');
            return ['token' => $token, 'expiresAt' => $expiry];
        });
    }
    public function invitations(string $tree, string $actor): array
    {
        $m = $this->access($tree, $actor, 'manager');
        $rows = $this->store->rows(
            'SELECT id, issuer_id, role, export_allowed, expires_at FROM *PREFIX*stb_invites WHERE tree_id = ? AND used = 0 AND expires_at > ? ORDER BY expires_at DESC',
            [$tree, time()],
        );
        return array_map(
            fn($r) => [
                'id' => $r['id'],
                'issuerId' => $r['issuer_id'],
                'role' => $r['role'],
                'exportAllowed' => (bool) $r['export_allowed'],
                'expiresAt' => (int) $r['expires_at'],
                'canRevoke' => $m['role'] === 'owner' || $r['issuer_id'] === $actor,
            ],
            $rows,
        );
    }
    public function revokeInvitation(string $tree, string $id, string $actor): array
    {
        return $this->store->write(function () use ($tree, $id, $actor) {
            $m = $this->access($tree, $actor, 'manager');
            $r = $this->store->one('SELECT * FROM *PREFIX*stb_invites WHERE tree_id = ? AND id = ?', [
                $tree,
                $id,
            ]);
            if (!$r) {
                throw Problem::missing();
            }
            if ($m['role'] !== 'owner' && $r['issuer_id'] !== $actor) {
                throw new Problem(
                    'forbidden',
                    'Only the issuer or an owner can revoke this invitation.',
                    403,
                );
            }
            $this->store->execute('UPDATE *PREFIX*stb_invites SET used = 1 WHERE id = ?', [$id]);
            $this->store->audit(
                $tree,
                $actor,
                'invitation_revoked',
                null,
                ['invitationId' => $id],
                'management',
            );
            return ['ok' => true];
        });
    }
    public function accept(string $actor, array $b, bool $accept): array
    {
        return $this->store->write(function () use ($actor, $b, $accept) {
            $this->active($actor);
            $token = Values::text($b['token'] ?? '', 'token', 64, true);
            $r = $this->store->one('SELECT * FROM *PREFIX*stb_invites WHERE token_hash = ?', [
                hash('sha256', $token),
            ]);
            if (!$r || (int) $r['used'] !== 0 || (int) $r['expires_at'] <= time()) {
                throw new Problem(
                    'invitation_unavailable',
                    'This invitation is expired, revoked or already used.',
                    404,
                );
            }
            $m = $this->store->one(
                'SELECT * FROM *PREFIX*stb_members WHERE tree_id = ? AND user_id = ? AND active = 1',
                [$r['tree_id'], $r['issuer_id']],
            );
            if (
                !$m ||
                (int) $m['revision'] !== (int) $r['issuer_revision'] ||
                !self::management($m) ||
                !$this->users->get($r['issuer_id'])?->isEnabled()
            ) {
                throw new Problem(
                    'invitation_unavailable',
                    'This invitation is expired, revoked or already used.',
                    404,
                );
            }
            $tree = $this->store->one('SELECT name FROM *PREFIX*stb_trees WHERE id = ?', [$r['tree_id']]);
            if (!$accept) {
                return [
                    'treeName' => $tree['name'],
                    'role' => $r['role'],
                    'exportAllowed' => (bool) $r['export_allowed'],
                    'expiresAt' => (int) $r['expires_at'],
                ];
            }
            $existing = $this->store->one(
                'SELECT * FROM *PREFIX*stb_members WHERE tree_id = ? AND user_id = ?',
                [$r['tree_id'], $actor],
            );
            if ($existing && (int) $existing['active'] === 1) {
                throw new Problem('already_member', 'You already belong to this tree.', 409);
            }
            if ($existing) {
                $this->store->execute(
                    'UPDATE *PREFIX*stb_members SET role = ?, export_allowed = ?, active = 1, revision = revision + 1 WHERE id = ?',
                    [$r['role'], $r['export_allowed'], $existing['id']],
                );
            } else {
                $this->store->insert('stb_members', [
                    'id' => Store::id(),
                    'tree_id' => $r['tree_id'],
                    'user_id' => $actor,
                    'role' => $r['role'],
                    'export_allowed' => $r['export_allowed'],
                    'active' => 1,
                    'revision' => 1,
                ]);
            }
            $this->store->execute('UPDATE *PREFIX*stb_invites SET used = 1 WHERE id = ?', [$r['id']]);
            $this->store->bump($r['tree_id']);
            $this->store->audit($r['tree_id'], $actor, 'invitation_accepted', null, [], 'management');
            return $this->get($r['tree_id'], $actor);
        });
    }
}
