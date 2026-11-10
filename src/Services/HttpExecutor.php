<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Env;

/** Builds and executes the HTTP request of a job (single or many in parallel). */
final class HttpExecutor
{
    public const STORE_BYTES = 65536;      // response body kept in the log
    public const MAX_READ_BYTES = 2097152; // stop reading after 2 MB
    public const CONCURRENCY = 10;

    public const CONTENT_TYPES = [
        'json' => 'application/json',
        'form' => 'application/x-www-form-urlencoded',
        'text' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml',
    ];

    /** Decrypted header list [{name, value}] of a job row. */
    public static function headers(array $job): array
    {
        if (empty($job['headers_enc'])) {
            return [];
        }
        try {
            return Crypto::decryptJson($job['headers_enc']);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Turns a job row into a request spec, rendering {{ variables }}.
     * @return array{method:string,url:string,headers:array,body:?string,timeout:int}
     */
    public static function buildRequest(array $job, array $context): array
    {
        $render = fn (string $s) => TemplateRenderer::renderSafe($s, $context);
        $headers = [];
        $hasCt = false;
        foreach (self::headers($job) as $h) {
            $name = trim((string) ($h['name'] ?? ''));
            if ($name === '' || !preg_match('/^[A-Za-z0-9\-_]+$/', $name)) {
                continue;
            }
            $value = str_replace(["\r", "\n"], '', $render((string) ($h['value'] ?? '')));
            $headers[] = "$name: $value";
            if (strtolower($name) === 'content-type') {
                $hasCt = true;
            }
        }
        $hasAuth = (bool) array_filter($headers, fn ($h) => stripos($h, 'authorization:') === 0);
        if (!$hasAuth && ($auth = JobAuth::header($job, $context)) !== null) {
            $headers[] = $auth;
        }
        $body = null;
        $type = $job['body_type'] ?? 'none';
        $method = strtoupper((string) $job['method']);
        if ($type !== 'none' && $method !== 'HEAD') {
            $raw = (string) ($job['body'] ?? '');
            if ($type === 'form') {
                $pairs = [];
                foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                    if (trim($line) === '' || !str_contains($line, '=')) {
                        continue;
                    }
                    [$k, $v] = explode('=', $line, 2);
                    $pairs[trim($k)] = $render(trim($v));
                }
                $body = http_build_query($pairs);
            } else {
                $body = $render($raw);
            }
            if (!$hasCt && isset(self::CONTENT_TYPES[$type])) {
                $headers[] = 'Content-Type: ' . self::CONTENT_TYPES[$type];
            }
        }
        return [
            'method' => $method,
            'url' => $render((string) $job['url']),
            'headers' => $headers,
            'body' => $body,
            'timeout' => max(1, min(300, (int) ($job['timeout_sec'] ?? 30))),
        ];
    }

    public static function execute(array $request): array
    {
        return self::executeMany(['one' => $request])['one'];
    }

    /**
     * @param array<string|int, array> $requests
     * @return array<string|int, array{ok:bool,http_status:?int,duration_ms:int,headers:string,body:string,size:int,error:?string}>
     */
    public static function executeMany(array $requests): array
    {
        $results = [];
        $queue = $requests;
        $mh = curl_multi_init();
        $active = [];
        $state = [];

        $start = function ($key, array $req) use ($mh, &$active, &$state, &$results): void {
            try {
                $target = SsrfGuard::check($req['url']);
            } catch (\InvalidArgumentException $e) {
                $results[$key] = self::fail($e->getMessage());
                return;
            }
            $ch = curl_init();
            $st = (object) ['headers' => '', 'body' => '', 'size' => 0, 'aborted' => false, 't0' => microtime(true)];
            $pinIp = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
            $opts = [
                CURLOPT_URL => $req['url'],
                CURLOPT_CUSTOMREQUEST => $req['method'],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $pinIp],
                CURLOPT_CONNECTTIMEOUT => min(10, $req['timeout']),
                CURLOPT_TIMEOUT => $req['timeout'],
                CURLOPT_HTTPHEADER => array_merge(['Expect:'], $req['headers']),
                CURLOPT_USERAGENT => App::name() . '/1.0 (+' . Env::get('APP_URL', '') . ')',
                CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => function ($ch, string $line) use ($st): int {
                    if (strlen($st->headers) < 8192) {
                        $st->headers .= $line;
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($st): int {
                    $len = strlen($data);
                    $st->size += $len;
                    if (strlen($st->body) < self::STORE_BYTES) {
                        $st->body .= substr($data, 0, self::STORE_BYTES - strlen($st->body));
                    }
                    if ($st->size > self::MAX_READ_BYTES) {
                        $st->aborted = true;
                        return 0;
                    }
                    return $len;
                },
            ];
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $opts[CURLOPT_PROTOCOLS_STR] = 'http,https';
            }
            if ($req['method'] === 'HEAD') {
                $opts[CURLOPT_NOBODY] = true;
            } elseif ($req['body'] !== null) {
                $opts[CURLOPT_POSTFIELDS] = $req['body'];
            }
            $ca = Env::get('CURL_CA_BUNDLE');
            if ($ca) {
                $opts[CURLOPT_CAINFO] = $ca;
            }
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $id = spl_object_id($ch);
            $active[$id] = $ch;
            $state[$id] = [$key, $st];
        };

        $fill = function () use (&$queue, &$active, $start): void {
            while (count($active) < self::CONCURRENCY && $queue) {
                $key = array_key_first($queue);
                $req = $queue[$key];
                unset($queue[$key]);
                $start($key, $req);
            }
        };

        $fill();
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.5);
            }
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $id = spl_object_id($ch);
                [$key, $st] = $state[$id];
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $err = $info['result'] !== CURLE_OK && !$st->aborted ? self::curlError($info['result'], curl_error($ch)) : null;
                $results[$key] = [
                    'ok' => $err === null,
                    'http_status' => $code > 0 ? $code : null,
                    'duration_ms' => (int) round((microtime(true) - $st->t0) * 1000),
                    'headers' => trim($st->headers),
                    'body' => self::toUtf8($st->body),
                    'size' => $st->size,
                    'truncated' => $st->size > strlen($st->body),
                    'error' => $err,
                ];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($active[$id], $state[$id]);
                $fill();
                $running = $running || $active;
            }
        } while (($running || $active) && $status === CURLM_OK);
        curl_multi_close($mh);

        foreach ($requests as $key => $_) {
            $results[$key] ??= self::fail('Η εκτέλεση δεν ολοκληρώθηκε.');
        }
        return $results;
    }

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'http_status' => null, 'duration_ms' => 0, 'headers' => '', 'body' => '', 'size' => 0, 'truncated' => false, 'error' => $msg];
    }

    private static function curlError(int $code, string $message): string
    {
        return match ($code) {
            CURLE_OPERATION_TIMEDOUT => 'Λήξη χρόνου (timeout).',
            CURLE_COULDNT_RESOLVE_HOST => 'Δεν βρέθηκε ο server (DNS).',
            CURLE_COULDNT_CONNECT => 'Αποτυχία σύνδεσης με τον server.',
            CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION => 'Σφάλμα SSL/TLS: ' . $message,
            default => "Σφάλμα δικτύου ($code): $message",
        };
    }

    private static function toUtf8(string $s): string
    {
        if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        return mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    }

    /** Is the response a success according to the job rules? Returns [bool, reason]. */
    public static function evaluate(array $job, array $result): array
    {
        [$ok, $reason] = self::evaluateFull($job, $result);
        return [$ok, $reason];
    }

    /**
     * Like evaluate() plus the per-assertion results.
     * @return array{0: bool, 1: ?string, 2: array}
     */
    public static function evaluateFull(array $job, array $result): array
    {
        if (!$result['ok'] || $result['http_status'] === null) {
            return [false, $result['error'] ?? 'Καμία απάντηση.', []];
        }
        $rules = is_array($job['assertions'] ?? null) ? $job['assertions'] : Assertions::decode($job['assertions'] ?? null);
        [, , $checks] = Assertions::check($rules, $result);
        if (!self::statusMatches((int) $result['http_status'], (string) ($job['success_codes'] ?: '200-299'))) {
            return [false, 'Μη αναμενόμενο HTTP status ' . $result['http_status'] . '.', $checks];
        }
        $needle = (string) ($job['success_contains'] ?? '');
        if ($needle !== '' && !str_contains($result['body'], $needle)) {
            return [false, 'Το response δεν περιέχει το κείμενο «' . $needle . '».', $checks];
        }
        foreach ($checks as $c) {
            if (!$c['ok']) {
                return [false, 'Απέτυχε ο έλεγχος: ' . $c['label'] . ' (βρέθηκε: ' . $c['actual'] . ').', $checks];
            }
        }
        return [true, null, $checks];
    }

    public static function statusMatches(int $code, string $spec): bool
    {
        foreach (explode(',', $spec) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d{3})\s*-\s*(\d{3})$/', $part, $m)) {
                if ($code >= (int) $m[1] && $code <= (int) $m[2]) {
                    return true;
                }
            } elseif (preg_match('/^(\d)xx$/i', $part, $m)) {
                if (intdiv($code, 100) === (int) $m[1]) {
                    return true;
                }
            } elseif ((int) $part === $code) {
                return true;
            }
        }
        return false;
    }
}
