<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public string $method;
    public string $path;
    private ?array $json = null;

    public function __construct()
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }
        $this->method = $method;
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = rtrim((string) Env::get('APP_BASE_PATH', ''), '/');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $this->path = '/' . trim(rawurldecode($uri), '/');
    }

    public function query(string $key, mixed $default = null): mixed
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_POST)) {
            $v = $_POST[$key];
            return is_string($v) ? trim($v) : $v;
        }
        $json = $this->json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }
        return $default;
    }

    /** Raw (untrimmed) input — for bodies/templates where whitespace matters. */
    public function raw(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? ($this->json()[$key] ?? $default);
        return is_string($v) ? str_replace("\r\n", "\n", $v) : $default;
    }

    public function all(): array
    {
        return $_POST + $this->json();
    }

    public function json(): array
    {
        if ($this->json === null) {
            $this->json = [];
            $ct = $_SERVER['CONTENT_TYPE'] ?? '';
            if (str_contains($ct, 'application/json')) {
                $data = json_decode((string) file_get_contents('php://input'), true);
                $this->json = is_array($data) ? $data : [];
            }
        }
        return $this->json;
    }

    public function file(string $key): ?array
    {
        $f = $_FILES[$key] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    }

    public function isSecure(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
