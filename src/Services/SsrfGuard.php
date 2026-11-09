<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

/**
 * Prevents tenants from reaching internal addresses (localhost, private ranges,
 * the server itself, cloud metadata) through job URLs or connector hosts.
 */
final class SsrfGuard
{
    /**
     * Validates a URL and resolves its host.
     * @return array{host:string, port:int, ip:string, scheme:string}
     * @throws \InvalidArgumentException
     */
    public static function check(string $url): array
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('Μη έγκυρο URL.');
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Επιτρέπονται μόνο http:// και https:// URLs.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Τα credentials μέσα στο URL δεν επιτρέπονται. Χρησιμοποίησε header Authorization.');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ip = self::checkHost($host);
        return ['host' => $host, 'port' => $port, 'ip' => $ip, 'scheme' => $scheme];
    }

    /** Resolves a host and returns a safe IP, or throws. */
    public static function checkHost(string $host): string
    {
        $host = strtolower(trim($host, '[] '));
        if ($host === '') {
            throw new \InvalidArgumentException('Κενό host.');
        }
        $blocked = array_filter(array_map('trim', explode(',', strtolower((string) Env::get('BLOCKED_HOSTS', '')))));
        if (in_array($host, $blocked, true)) {
            throw new \InvalidArgumentException('Ο προορισμός αυτός δεν επιτρέπεται.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = [];
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            foreach ($records as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                } elseif (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
            if (!$ips) {
                $v4 = gethostbynamel($host) ?: [];
                $ips = $v4;
            }
            if (!$ips) {
                throw new \InvalidArgumentException("Δεν βρέθηκε DNS εγγραφή για το $host.");
            }
        }
        foreach ($ips as $ip) {
            if (in_array($ip, $blocked, true) || !self::isPublic($ip)) {
                if (!Env::bool('ALLOW_PRIVATE_TARGETS')) {
                    throw new \InvalidArgumentException("Ο προορισμός $host ($ip) είναι εσωτερική διεύθυνση και δεν επιτρέπεται.");
                }
            }
        }
        // Prefer IPv4 for pinning.
        usort($ips, fn ($a, $b) => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));
        return $ips[0];
    }

    public static function isPublic(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // Extra ranges not covered by PHP flags.
        $deny = ['100.64.0.0/10', '169.254.0.0/16', '0.0.0.0/8', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4'];
        if (str_contains($ip, '.')) {
            $long = ip2long($ip);
            foreach ($deny as $cidr) {
                [$net, $bits] = explode('/', $cidr);
                $mask = -1 << (32 - (int) $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
        } else {
            $lower = strtolower($ip);
            if ($lower === '::1' || str_starts_with($lower, 'fe80') || str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd') || str_starts_with($lower, '::ffff:')) {
                return false;
            }
        }
        return true;
    }
}
