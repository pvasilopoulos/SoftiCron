<?php

declare(strict_types=1);

namespace App\Core;

final class App
{
    public static string $root = '';
    private static ?Db $db = null;

    public static function boot(string $root): void
    {
        self::$root = rtrim($root, '/\\');
        Env::load(self::$root . '/.env');
        date_default_timezone_set('UTC');
        error_reporting(E_ALL);
        ini_set('display_errors', Env::bool('APP_DEBUG') ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', self::$root . '/storage/logs/php-error.log');
        mb_internal_encoding('UTF-8');
        // Windows PHP builds need an explicit openssl.cnf for EC keys (Web Push).
        if (($conf = Env::get('OPENSSL_CONF')) && !getenv('OPENSSL_CONF')) {
            putenv('OPENSSL_CONF=' . $conf);
        }
    }

    public static function db(): Db
    {
        if (self::$db === null) {
            self::$db = new Db(
                (string) Env::get('DB_HOST', '127.0.0.1'),
                (int) Env::get('DB_PORT', '3306'),
                (string) Env::get('DB_NAME', 'cronapp'),
                (string) Env::get('DB_USER', 'root'),
                (string) Env::get('DB_PASS', ''),
            );
        }
        return self::$db;
    }

    public static function name(): string
    {
        return (string) Env::get('APP_NAME', 'CronApp');
    }

    public static function storage(string $path = ''): string
    {
        return self::$root . '/storage' . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    public static function log(string $message, array $context = []): void
    {
        $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . $message;
        if ($context) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        @file_put_contents(self::storage('logs/app.log'), $line . PHP_EOL, FILE_APPEND);
    }
}
