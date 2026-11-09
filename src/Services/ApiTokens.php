<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Permissions;
use App\Core\App;

/** Personal API tokens (Bearer). Only a SHA-256 hash is stored; the token is shown once. */
final class ApiTokens
{
    public const PREFIX = 'sct_';

    /** scope => [label, description, permissions granted] */
    public const SCOPES = [
        'read' => ['Ανάγνωση', 'Λίστα/προβολή jobs, εκτελέσεων και αναφορών', ['jobs.view', 'runs.view', 'reports.view']],
        'write' => ['Εγγραφή', 'Δημιουργία, αλλαγή, παύση και διαγραφή jobs', ['jobs.view', 'jobs.create', 'jobs.edit', 'jobs.delete', 'jobs.toggle']],
        'run' => ['Εκτέλεση', 'Χειροκίνητη εκτέλεση jobs', ['jobs.view', 'jobs.run', 'runs.view']],
    ];

    /** @return array{0: string, 1: int} [plain token, id] */
    public static function create(int $tenantId, int $userId, string $name, array $scopes, ?string $expiresAt): array
    {
        $plain = self::PREFIX . bin2hex(random_bytes(20));
        $id = App::db()->insert('api_tokens', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 100),
            'token_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 12),
            'scopes' => implode(',', self::cleanScopes($scopes)),
            'expires_at' => $expiresAt,
        ]);
        return [$plain, $id];
    }

    public static function cleanScopes(array $scopes): array
    {
        return array_values(array_intersect(array_keys(self::SCOPES), $scopes));
    }

    /**
     * Resolves a bearer token to [token, user, tenant, permissions] or null.
     * Permissions are the intersection of the token scopes and the user's current role
     * in the tenant, so removing a member or downgrading a role limits their tokens too.
     */
    public static function resolve(string $plain): ?array
    {
        if (!str_starts_with($plain, self::PREFIX) || strlen($plain) > 100) {
            return null;
        }
        $db = App::db();
        $tok = $db->one(
            'SELECT * FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())',
            [hash('sha256', $plain)],
        );
        if (!$tok || !$tok['user_id']) {
            return null;
        }
        $user = $db->one("SELECT * FROM users WHERE id = ? AND status = 'active'", [$tok['user_id']]);
        $tenant = $db->one("SELECT * FROM tenants WHERE id = ? AND status = 'active'", [$tok['tenant_id']]);
        if (!$user || !$tenant) {
            return null;
        }
        if ($user['is_super_admin']) {
            $rolePerms = array_keys(Permissions::ALL);
        } else {
            $roleId = $db->value('SELECT role_id FROM memberships WHERE tenant_id = ? AND user_id = ?', [$tenant['id'], $user['id']]);
            if ($roleId === null) {
                return null;
            }
            $rolePerms = $db->column('SELECT permission FROM role_permissions WHERE role_id = ?', [$roleId]);
        }
        $granted = [];
        foreach (explode(',', (string) $tok['scopes']) as $s) {
            $granted = array_merge($granted, self::SCOPES[$s][2] ?? []);
        }
        $perms = array_values(array_intersect(array_unique($granted), $rolePerms));
        return [$tok, $user, $tenant, $perms];
    }

    public static function touch(int $id, string $ip): void
    {
        // At most one write per minute per token.
        App::db()->exec(
            'UPDATE api_tokens SET last_used_at = UTC_TIMESTAMP(), last_used_ip = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < UTC_TIMESTAMP() - INTERVAL 1 MINUTE)',
            [mb_substr($ip, 0, 45), $id],
        );
    }

    /** Simple fixed-window rate limit per token (requests per minute). */
    public static function rateLimited(int $id, int $perMinute = 120): bool
    {
        $dir = App::storage('cache/ratelimit');
        @mkdir($dir, 0775, true);
        $file = $dir . '/api-' . $id . '-' . gmdate('YmdHi');
        $n = (int) @file_get_contents($file) + 1;
        @file_put_contents($file, (string) $n, LOCK_EX);
        if (random_int(1, 50) === 1) {
            foreach (glob($dir . '/api-*') ?: [] as $f) {
                if (filemtime($f) < time() - 180) {
                    @unlink($f);
                }
            }
        }
        return $n > $perMinute;
    }
}
