<?php

declare(strict_types=1);

namespace App\Core;

/** Authenticated encryption of secrets at rest (libsodium secretbox). */
final class Crypto
{
    private static function key(): string
    {
        $raw = base64_decode((string) Env::get('APP_KEY', ''), true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('APP_KEY is missing or invalid. Run: php bin/keygen.php');
        }
        return $raw;
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $cipher): string
    {
        if ($cipher === null || $cipher === '') {
            return '';
        }
        if (!str_starts_with($cipher, 'v1:')) {
            throw new \RuntimeException('Unknown cipher format');
        }
        $bin = base64_decode(substr($cipher, 3), true);
        if ($bin === false || strlen($bin) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Corrupt cipher text');
        }
        $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed (wrong APP_KEY?)');
        }
        return $plain;
    }

    public static function encryptJson(array $data): string
    {
        return self::encrypt((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function decryptJson(?string $cipher): array
    {
        $plain = self::decrypt($cipher);
        $data = $plain === '' ? [] : json_decode($plain, true);
        return is_array($data) ? $data : [];
    }
}
