<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Services\Channels\Http;

/** Authentication of a job's HTTP request: Basic, Bearer or OAuth2 client credentials. */
final class JobAuth
{
    public const TYPES = ['none' => 'Καμία', 'basic' => 'Basic auth', 'bearer' => 'Bearer token', 'oauth2' => 'OAuth2 (client credentials)'];

    /** Field definitions: key => [label, input, secret, help] */
    public const FIELDS = [
        'basic' => [
            'username' => ['Username', 'text', false, ''],
            'password' => ['Password', 'password', true, ''],
        ],
        'bearer' => [
            'token' => ['Token', 'password', true, 'Μπορείς να γράψεις και {{ secrets.ΟΝΟΜΑ }}'],
        ],
        'oauth2' => [
            'token_url' => ['Token URL', 'url', false, 'π.χ. https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token'],
            'client_id' => ['Client ID', 'text', false, ''],
            'client_secret' => ['Client secret', 'password', true, 'Μπορείς να γράψεις και {{ secrets.ΟΝΟΜΑ }}'],
            'scope' => ['Scope', 'text', false, 'Προαιρετικό, π.χ. https://graph.microsoft.com/.default'],
            'audience' => ['Audience', 'text', false, 'Προαιρετικό (Auth0 κ.ά.)'],
            'client_auth' => ['Αποστολή credentials', 'select:body=Στο body (client_id/client_secret),basic=Basic header', false, ''],
        ],
    ];

    public static function config(array $job): array
    {
        if (empty($job['auth_enc'])) {
            return [];
        }
        try {
            return Crypto::decryptJson($job['auth_enc']);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The Authorization header line for a job (or null).
     * @throws \RuntimeException when an OAuth2 token cannot be obtained
     */
    public static function header(array $job, array $ctx): ?string
    {
        $type = $job['auth_type'] ?? 'none';
        if ($type === 'none' || $type === '') {
            return null;
        }
        $cfg = array_map(fn ($v) => is_string($v) ? TemplateRenderer::renderSafe($v, $ctx) : $v, self::config($job));
        return match ($type) {
            'basic' => 'Authorization: Basic ' . base64_encode(($cfg['username'] ?? '') . ':' . ($cfg['password'] ?? '')),
            'bearer' => 'Authorization: Bearer ' . trim((string) ($cfg['token'] ?? '')),
            'oauth2' => 'Authorization: Bearer ' . self::oauthToken((int) ($job['id'] ?? 0), $cfg),
            default => null,
        };
    }

    /** Client-credentials token, cached per job until shortly before it expires. */
    private static function oauthToken(int $jobId, array $cfg, bool $force = false): string
    {
        $db = App::db();
        if ($jobId && !$force) {
            $row = $db->one('SELECT token_enc, expires_at FROM oauth_tokens WHERE job_id = ?', [$jobId]);
            if ($row && strtotime($row['expires_at'] . ' UTC') > time() + 60) {
                try {
                    return Crypto::decrypt($row['token_enc']);
                } catch (\Throwable) {
                    // fall through and fetch a new one
                }
            }
        }
        $url = trim((string) ($cfg['token_url'] ?? ''));
        if (!preg_match('#^https://#i', $url)) {
            throw new \RuntimeException('OAuth2: το Token URL πρέπει να ξεκινά με https://');
        }
        try {
            SsrfGuard::check($url);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('OAuth2: ' . $e->getMessage());
        }
        $form = ['grant_type' => 'client_credentials'];
        $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
        if (($cfg['client_auth'] ?? 'body') === 'basic') {
            $headers[] = 'Authorization: Basic ' . base64_encode(rawurlencode((string) ($cfg['client_id'] ?? '')) . ':' . rawurlencode((string) ($cfg['client_secret'] ?? '')));
        } else {
            $form['client_id'] = (string) ($cfg['client_id'] ?? '');
            $form['client_secret'] = (string) ($cfg['client_secret'] ?? '');
        }
        foreach (['scope', 'audience'] as $k) {
            if (!empty($cfg[$k])) {
                $form[$k] = (string) $cfg[$k];
            }
        }
        [$code, $body] = Http::request('POST', $url, $headers, http_build_query($form), 20);
        $data = json_decode($body, true);
        if ($code !== 200 || empty($data['access_token'])) {
            $err = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? '') : '';
            throw new \RuntimeException('OAuth2: αποτυχία λήψης token (' . $code . ') ' . mb_substr((string) ($err ?: $body), 0, 200));
        }
        $token = (string) $data['access_token'];
        if ($jobId) {
            $exp = gmdate('Y-m-d H:i:s', time() + max(60, (int) ($data['expires_in'] ?? 3600)));
            $db->exec(
                'INSERT INTO oauth_tokens (job_id, token_enc, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE token_enc = VALUES(token_enc), expires_at = VALUES(expires_at)',
                [$jobId, Crypto::encrypt($token), $exp],
            );
        }
        return $token;
    }

    /** Called when the API answered 401: the next attempt fetches a fresh token. */
    public static function invalidate(int $jobId): void
    {
        App::db()->exec('DELETE FROM oauth_tokens WHERE job_id = ?', [$jobId]);
    }
}
