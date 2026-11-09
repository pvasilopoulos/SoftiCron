<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/** Key/value settings. tenant_id 0 = platform-wide. */
final class Settings
{
    private static array $cache = [];

    public static function get(int $tenantId, string $key, ?string $default = null): ?string
    {
        $all = self::all($tenantId);
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function all(int $tenantId): array
    {
        if (!isset(self::$cache[$tenantId])) {
            $rows = App::db()->all('SELECT k, v FROM settings WHERE tenant_id = ?', [$tenantId]);
            self::$cache[$tenantId] = array_column($rows, 'v', 'k');
        }
        return self::$cache[$tenantId];
    }

    public static function set(int $tenantId, string $key, ?string $value): void
    {
        App::db()->exec(
            'INSERT INTO settings (tenant_id, k, v) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
            [$tenantId, $key, $value],
        );
        unset(self::$cache[$tenantId]);
    }
}
