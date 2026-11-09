<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;

/**
 * Tenant variables: {{ vars.NAME }} (plain) and {{ secrets.NAME }} (encrypted, never shown again).
 * Both are stored encrypted; secrets are additionally masked in logs.
 */
final class Variables
{
    private static array $cache = [];

    public static function all(int $tenantId): array
    {
        if (!isset(self::$cache[$tenantId])) {
            $rows = App::db()->all('SELECT * FROM tenant_variables WHERE tenant_id = ? ORDER BY name', [$tenantId]);
            foreach ($rows as &$r) {
                try {
                    $r['value'] = Crypto::decrypt($r['value_enc']);
                } catch (\Throwable) {
                    $r['value'] = '';
                }
            }
            self::$cache[$tenantId] = $rows;
        }
        return self::$cache[$tenantId];
    }

    public static function forget(int $tenantId): void
    {
        unset(self::$cache[$tenantId]);
    }

    /** Template context: ['vars' => [...], 'secrets' => [...]] */
    public static function context(int $tenantId): array
    {
        $vars = [];
        $secrets = [];
        foreach (self::all($tenantId) as $r) {
            if ($r['is_secret']) {
                $secrets[$r['name']] = $r['value'];
            } else {
                $vars[$r['name']] = $r['value'];
            }
        }
        return ['vars' => $vars, 'secrets' => $secrets];
    }

    /** Replaces secret values inside a string (e.g. a URL that is stored in the run log). */
    public static function mask(int $tenantId, ?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        foreach (self::all($tenantId) as $r) {
            if ($r['is_secret'] && mb_strlen($r['value']) >= 4) {
                $text = str_replace([$r['value'], rawurlencode($r['value'])], '••••', $text);
            }
        }
        return $text;
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $name);
    }
}
