<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/**
 * Roles visible to a tenant: its own roles plus the built-in ones it has not customised.
 * Customising a built-in role creates a tenant copy and moves the tenant's members to it.
 */
final class Roles
{
    public const OWNER = 1;

    public static function visible(int $tenantId): array
    {
        return App::db()->all(
            'SELECT r.* FROM roles r
             WHERE r.tenant_id = ?
                OR (r.tenant_id IS NULL AND r.id NOT IN (SELECT based_on FROM roles WHERE tenant_id = ? AND based_on IS NOT NULL))
             ORDER BY COALESCE(r.based_on, r.id) < 100 DESC, COALESCE(r.based_on, r.id), r.id',
            [$tenantId, $tenantId],
        );
    }

    public static function isVisible(int $tenantId, int $roleId): bool
    {
        foreach (self::visible($tenantId) as $r) {
            if ((int) $r['id'] === $roleId) {
                return true;
            }
        }
        return false;
    }

    /** Returns an editable tenant role id for $roleId, cloning a built-in role if needed. */
    public static function editable(int $tenantId, int $roleId): ?int
    {
        $db = App::db();
        if ($roleId === self::OWNER) {
            return null;
        }
        $role = $db->one('SELECT * FROM roles WHERE id = ?', [$roleId]);
        if (!$role) {
            return null;
        }
        if ((int) $role['tenant_id'] === $tenantId) {
            return $roleId;
        }
        if ($role['tenant_id'] !== null) {
            return null;
        }
        $existing = $db->value('SELECT id FROM roles WHERE tenant_id = ? AND based_on = ?', [$tenantId, $roleId]);
        if ($existing) {
            return (int) $existing;
        }
        return $db->transaction(function () use ($db, $role, $tenantId, $roleId): int {
            $id = $db->insert('roles', [
                'tenant_id' => $tenantId, 'name' => $role['name'], 'description' => $role['description'], 'based_on' => $roleId,
            ]);
            $db->exec('INSERT INTO role_permissions (role_id, permission) SELECT ?, permission FROM role_permissions WHERE role_id = ?', [$id, $roleId]);
            $db->exec('UPDATE memberships SET role_id = ? WHERE tenant_id = ? AND role_id = ?', [$id, $tenantId, $roleId]);
            $db->exec('UPDATE invitations SET role_id = ? WHERE tenant_id = ? AND role_id = ?', [$id, $tenantId, $roleId]);
            return $id;
        });
    }
}
