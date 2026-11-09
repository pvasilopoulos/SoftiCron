<?php

declare(strict_types=1);

namespace App\Services\Channels;

/**
 * Routee (routee.net) SMS and Viber.
 * Docs: auth   POST https://auth.routee.net/oauth/token  (Basic appId:secret, grant_type=client_credentials)
 *       sms    POST https://connect.routee.net/sms       {body, to, from, transcode}
 *       viber  POST https://connect.routee.net/viber     {senderInfoTrackingId, to, body:{text}}
 */
final class RouteeSender
{
    /** @var array<string, array{token:string, exp:int}> per app id */
    private static array $tokens = [];

    /** Normalises Greek/international numbers to E.164 (+30…). Returns null if invalid. */
    public static function normalizePhone(string $raw): ?string
    {
        $n = preg_replace('/[\s\-().]/', '', trim($raw)) ?? '';
        if (str_starts_with($n, '00')) {
            $n = '+' . substr($n, 2);
        } elseif (preg_match('/^(69|2)\d{8,9}$/', $n)) {
            $n = '+30' . $n; // Greek mobile / landline without country code
        }
        return preg_match('/^\+[1-9]\d{7,14}$/', $n) ? $n : null;
    }

    /** @return string[] valid numbers from a comma/space separated list */
    public static function parsePhones(string $list): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/', $list) ?: [] as $p) {
            if (trim($p) === '') {
                continue;
            }
            $n = self::normalizePhone($p);
            if ($n !== null && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        return $out;
    }

    private static function token(array $cfg, bool $refresh = false): string
    {
        $id = trim((string) ($cfg['app_id'] ?? ''));
        $secret = (string) ($cfg['app_secret'] ?? '');
        if ($id === '' || $secret === '') {
            throw new \RuntimeException('Routee: λείπουν το Application ID ή το Secret.');
        }
        $cached = self::$tokens[$id] ?? null;
        if (!$refresh && $cached && $cached['exp'] > time() + 60) {
            return $cached['token'];
        }
        [$code, $body] = Http::request(
            'POST',
            'https://auth.routee.net/oauth/token',
            ['Authorization: Basic ' . base64_encode($id . ':' . $secret), 'Content-Type: application/x-www-form-urlencoded'],
            'grant_type=client_credentials',
            15,
        );
        $data = json_decode($body, true);
        if ($code !== 200 || empty($data['access_token'])) {
            throw new \RuntimeException('Routee: αποτυχία σύνδεσης (' . $code . ') — ' . self::error($data, $body));
        }
        self::$tokens[$id] = ['token' => (string) $data['access_token'], 'exp' => time() + (int) ($data['expires_in'] ?? 3600)];
        return self::$tokens[$id]['token'];
    }

    /** POST with bearer token; retries once with a fresh token on 401/403. */
    private static function post(array $cfg, string $url, array $payload): array
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ([false, true] as $refresh) {
            $token = self::token($cfg, $refresh);
            [$code, $body] = Http::request('POST', $url, ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], $json, 20);
            if (($code === 401 || $code === 403) && !$refresh) {
                continue;
            }
            $data = json_decode($body, true);
            if ($code < 200 || $code >= 300) {
                throw new \RuntimeException('Routee (' . $code . '): ' . self::error($data, $body));
            }
            return is_array($data) ? $data : [];
        }
        throw new \RuntimeException('Routee: μη εξουσιοδοτημένο αίτημα.');
    }

    private static function error(mixed $data, string $raw): string
    {
        if (is_array($data)) {
            foreach (['developerMessage', 'message', 'error_description', 'error'] as $k) {
                if (!empty($data[$k]) && is_string($data[$k])) {
                    return $data[$k];
                }
            }
        }
        return mb_substr(trim($raw), 0, 200) ?: 'άγνωστο σφάλμα';
    }

    /** @return string tracking id */
    public static function sms(array $cfg, string $to, string $text): string
    {
        $from = trim((string) ($cfg['sms_from'] ?? ''));
        if ($from === '') {
            throw new \RuntimeException('Routee: δεν έχει οριστεί αποστολέας SMS.');
        }
        $payload = ['body' => mb_substr($text, 0, 700), 'to' => $to, 'from' => mb_substr($from, 0, 16)];
        if (($cfg['transcode'] ?? '1') === '1') {
            $payload['transcode'] = true;
        }
        $res = self::post($cfg, 'https://connect.routee.net/sms', $payload);
        return (string) ($res['trackingId'] ?? '');
    }

    /**
     * Sends a Viber text; if that call fails and fallback is enabled, sends SMS instead.
     * @return string "viber" or "sms" (what was actually used)
     */
    public static function viber(array $cfg, string $to, string $text): string
    {
        $sender = trim((string) ($cfg['viber_sender_id'] ?? ''));
        try {
            if ($sender === '') {
                throw new \RuntimeException('Routee: δεν έχει οριστεί Viber sender (senderInfoTrackingId).');
            }
            self::post($cfg, 'https://connect.routee.net/viber', [
                'senderInfoTrackingId' => $sender,
                'to' => $to,
                'body' => ['text' => mb_substr($text, 0, 1000)],
                'ttl' => 3600,
            ]);
            return 'viber';
        } catch (\RuntimeException $e) {
            if (($cfg['viber_fallback_sms'] ?? '1') === '1' && trim((string) ($cfg['sms_from'] ?? '')) !== '') {
                self::sms($cfg, $to, $text);
                return 'sms';
            }
            throw $e;
        }
    }
}
