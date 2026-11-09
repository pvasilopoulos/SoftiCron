<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\App;
use App\Core\Session;

final class Auth
{
    private static ?array $user = null;
    private static ?array $tenant = null;
    private static ?array $perms = null;
    private static bool $loaded = false;

    public const MAX_FAILURES = 5;
    public const LOCK_MINUTES = 15;

    /** @return string|null error message, null on success */
    public static function attempt(string $email, string $password, string $ip): ?string
    {
        $db = App::db();
        $email = strtolower(trim($email));
        $since = gmdate('Y-m-d H:i:s', time() - self::LOCK_MINUTES * 60);
        $fails = (int) $db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > ? AND (email = ? OR ip = ?)',
            [$since, $email, $ip],
        );
        if ($fails >= self::MAX_FAILURES * 2) {
            return 'Πολλές αποτυχημένες προσπάθειες. Δοκίμασε ξανά σε ' . self::LOCK_MINUTES . ' λεπτά.';
        }
        $user = $db->one('SELECT * FROM users WHERE email = ?', [$email]);
        $ok = $user && $user['status'] === 'active' && password_verify($password, $user['password_hash']);
        $db->insert('login_attempts', ['email' => $email, 'ip' => $ip, 'success' => $ok ? 1 : 0]);
        if (!$ok) {
            // Constant-ish time when the user does not exist.
            if (!$user) {
                password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            }
            return 'Λάθος email ή κωδικός.';
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $db->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $user['id']]);
        }
        Session::regenerate();
        Session::set('uid', (int) $user['id']);
        $db->update('users', ['last_login_at' => $db->now()], 'id = :id', ['id' => $user['id']]);
        self::$loaded = false;
        self::chooseTenant((int) $user['id'], $user['last_tenant_id'] !== null ? (int) $user['last_tenant_id'] : null, (bool) $user['is_super_admin']);
        return null;
    }

    public static function loginAs(int $userId): void
    {
        Session::regenerate();
        Session::set('uid', $userId);
        self::$loaded = false;
        $u = App::db()->one('SELECT * FROM users WHERE id = ?', [$userId]);
        self::chooseTenant($userId, $u && $u['last_tenant_id'] !== null ? (int) $u['last_tenant_id'] : null, (bool) ($u['is_super_admin'] ?? false));
    }

    private static function chooseTenant(int $userId, ?int $preferred, bool $isSuper): void
    {
        $db = App::db();
        $ids = array_map('intval', $db->column(
            "SELECT m.tenant_id FROM memberships m JOIN tenants t ON t.id = m.tenant_id WHERE m.user_id = ? AND t.status = 'active' ORDER BY t.name",
            [$userId],
        ));
        $tid = null;
        if ($preferred && (in_array($preferred, $ids, true) || $isSuper)) {
            $tid = $preferred;
        } elseif ($ids) {
            $tid = $ids[0];
        }
        Session::set('tid', $tid);
    }

    /** Stateless authentication for the REST API: act as the token's user, limited to its scopes. */
    public static function actAs(array $user, array $tenant, array $permissions): void
    {
        self::$user = $user;
        self::$tenant = $tenant;
        self::$perms = $permissions;
        self::$loaded = true;
        self::$apiMode = true;
    }

    private static bool $apiMode = false;

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
        self::$tenant = null;
        self::$perms = null;
        self::$loaded = true;
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $uid = (int) Session::get('uid', 0);
        if (!$uid) {
            return;
        }
        $db = App::db();
        $user = $db->one("SELECT * FROM users WHERE id = ? AND status = 'active'", [$uid]);
        if (!$user) {
            Session::destroy();
            return;
        }
        self::$user = $user;
        $tid = (int) Session::get('tid', 0);
        if ($tid) {
            $tenant = $db->one('SELECT * FROM tenants WHERE id = ?', [$tid]);
            $member = $db->one('SELECT role_id FROM memberships WHERE tenant_id = ? AND user_id = ?', [$tid, $uid]);
            $allowed = $tenant && ($user['is_super_admin'] || ($member && $tenant['status'] === 'active'));
            if ($allowed) {
                self::$tenant = $tenant;
                if ($user['is_super_admin']) {
                    self::$perms = array_keys(Permissions::ALL);
                } else {
                    self::$perms = $db->column('SELECT permission FROM role_permissions WHERE role_id = ?', [$member['role_id']]);
                }
            } else {
                Session::set('tid', null);
            }
        }
    }

    public static function user(): ?array
    {
        self::load();
        return self::$user;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isSuperAdmin(): bool
    {
        $u = self::user();
        return $u !== null && (bool) $u['is_super_admin'];
    }

    public static function tenant(): ?array
    {
        self::load();
        return self::$tenant;
    }

    public static function tenantId(): ?int
    {
        $t = self::tenant();
        return $t ? (int) $t['id'] : null;
    }

    public static function can(string $permission): bool
    {
        self::load();
        if (self::$user === null) {
            return false;
        }
        if (self::$user['is_super_admin'] && !self::$apiMode) {
            return true; // API tokens are always limited to their scopes
        }
        return in_array($permission, self::$perms ?? [], true);
    }

    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $p) {
            if (self::can($p)) {
                return true;
            }
        }
        return false;
    }

    public static function switchTenant(int $tenantId): bool
    {
        $uid = self::id();
        if (!$uid) {
            return false;
        }
        $db = App::db();
        $ok = self::isSuperAdmin()
            ? (bool) $db->value('SELECT 1 FROM tenants WHERE id = ?', [$tenantId])
            : (bool) $db->value(
                "SELECT 1 FROM memberships m JOIN tenants t ON t.id = m.tenant_id WHERE m.user_id = ? AND m.tenant_id = ? AND t.status = 'active'",
                [$uid, $tenantId],
            );
        if (!$ok) {
            return false;
        }
        Session::set('tid', $tenantId);
        $db->update('users', ['last_tenant_id' => $tenantId], 'id = :id', ['id' => $uid]);
        self::$loaded = false;
        self::$tenant = null;
        self::$perms = null;
        return true;
    }

    /** Tenants the current user can switch between. */
    public static function tenants(): array
    {
        $uid = self::id();
        if (!$uid) {
            return [];
        }
        return App::db()->all(
            "SELECT t.id, t.name, t.brand_color, t.logo_path, r.name AS role
             FROM memberships m JOIN tenants t ON t.id = m.tenant_id JOIN roles r ON r.id = m.role_id
             WHERE m.user_id = ? AND t.status = 'active' ORDER BY t.name",
            [$uid],
        );
    }

    public static function roleName(): ?string
    {
        $uid = self::id();
        $tid = self::tenantId();
        if (!$uid || !$tid) {
            return null;
        }
        $name = App::db()->value(
            'SELECT r.name FROM memberships m JOIN roles r ON r.id = m.role_id WHERE m.user_id = ? AND m.tenant_id = ?',
            [$uid, $tid],
        );
        if ($name === null && self::isSuperAdmin()) {
            return 'Super admin';
        }
        return $name;
    }
}
