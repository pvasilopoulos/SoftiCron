<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/** Push device identification (provider, platform, browser) and registration. */
final class Devices
{
    public const PROVIDERS = [
        'fcm' => 'Google FCM', 'apple' => 'Apple Push', 'mozilla' => 'Mozilla Push', 'wns' => 'Microsoft WNS', 'other' => 'Άλλος',
    ];

    public static function provider(string $endpoint): string
    {
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        return match (true) {
            $host === 'fcm.googleapis.com' => 'fcm',
            str_ends_with($host, 'push.apple.com') => 'apple',
            str_ends_with($host, 'mozilla.com') => 'mozilla',
            str_ends_with($host, 'notify.windows.com') => 'wns',
            default => 'other',
        };
    }

    /** @return array{0: string, 1: string} [platform, browser] */
    public static function parseAgent(string $ua): array
    {
        $platform = match (true) {
            (bool) preg_match('/iPhone OS (\d+)[_.](\d+)/', $ua, $m) => 'iPhone · iOS ' . $m[1] . '.' . $m[2],
            (bool) preg_match('/iPad.*OS (\d+)[_.](\d+)/', $ua, $m) => 'iPad · iPadOS ' . $m[1] . '.' . $m[2],
            (bool) preg_match('/Android (\d+(?:\.\d+)?)/', $ua, $m) => 'Android ' . $m[1],
            str_contains($ua, 'Windows NT 10.0') => 'Windows 10/11',
            str_contains($ua, 'Windows') => 'Windows',
            (bool) preg_match('/Mac OS X (\d+)[_.](\d+)/', $ua, $m) => 'macOS ' . $m[1] . '.' . $m[2],
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Άγνωστη',
        };
        $browser = match (true) {
            (bool) preg_match('/Edg[A-Z]*\/(\d+)/', $ua, $m) => 'Edge ' . $m[1],
            (bool) preg_match('/OPR\/(\d+)/', $ua, $m) => 'Opera ' . $m[1],
            (bool) preg_match('/SamsungBrowser\/(\d+)/', $ua, $m) => 'Samsung Internet ' . $m[1],
            (bool) preg_match('/(?:Firefox|FxiOS)\/(\d+)/', $ua, $m) => 'Firefox ' . $m[1],
            (bool) preg_match('/(?:Chrome|CriOS)\/(\d+)/', $ua, $m) => 'Chrome ' . $m[1],
            (bool) preg_match('/Version\/(\d+(?:\.\d+)?).*Safari/', $ua, $m) => 'Safari ' . $m[1],
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Browser',
        };
        return [$platform, $browser];
    }

    /** Short default name, e.g. "Android · Chrome". */
    public static function shortName(string $ua): string
    {
        [$p, $b] = self::parseAgent($ua);
        $os = match (true) {
            str_starts_with($p, 'iPhone') => 'iPhone',
            str_starts_with($p, 'iPad') => 'iPad',
            str_starts_with($p, 'Android') => 'Android',
            str_starts_with($p, 'Windows') => 'Windows',
            str_starts_with($p, 'macOS') => 'Mac',
            $p === 'Άγνωστη' => 'Συσκευή',
            default => $p,
        };
        return $os . ' · ' . (preg_replace('/\s[\d.]+$/', '', $b) ?? $b);
    }

    /**
     * Registers or refreshes a subscription with the metadata the browser sent.
     * Called on enabling push and periodically from any open page (last seen / IP).
     */
    public static function register(int $userId, string $endpoint, array $keys, array $meta, string $ua, string $ip): void
    {
        [$platform, $browser] = self::parseAgent($ua);
        $tz = (string) ($meta['tz'] ?? '');
        $data = [
            'user_id' => $userId,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'p256dh' => mb_substr((string) $keys['p256dh'], 0, 255),
            'auth' => mb_substr((string) $keys['auth'], 0, 255),
            'user_agent' => self::shortName($ua),
            'provider' => self::provider($endpoint),
            'platform' => mb_substr($platform, 0, 60),
            'browser' => mb_substr($browser, 0, 60),
            'full_user_agent' => mb_substr($ua, 0, 500),
            'is_pwa' => !empty($meta['pwa']) ? 1 : 0,
            'language' => preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', (string) ($meta['lang'] ?? '')) ? (string) $meta['lang'] : null,
            'timezone' => in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : null,
            'screen' => preg_match('/^\d{2,5}x\d{2,5}$/', (string) ($meta['screen'] ?? '')) ? (string) $meta['screen'] : null,
            'last_ip' => mb_substr($ip, 0, 45),
        ];
        $db = App::db();
        $db->exec(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent, provider, platform, browser, full_user_agent, is_pwa, language, timezone, screen, created_ip, last_ip, last_seen_at)
             VALUES (:user_id, :endpoint, :endpoint_hash, :p256dh, :auth, :user_agent, :provider, :platform, :browser, :full_user_agent, :is_pwa, :language, :timezone, :screen, :last_ip, :last_ip2, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent),
               provider = VALUES(provider), platform = VALUES(platform), browser = VALUES(browser), full_user_agent = VALUES(full_user_agent),
               is_pwa = VALUES(is_pwa), language = VALUES(language), timezone = VALUES(timezone), screen = VALUES(screen),
               last_ip = VALUES(last_ip), last_seen_at = UTC_TIMESTAMP()',
            $data + ['last_ip2' => $data['last_ip']],
        );
    }
}
