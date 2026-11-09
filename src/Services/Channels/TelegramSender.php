<?php

declare(strict_types=1);

namespace App\Services\Channels;

final class TelegramSender
{
    /** @throws \RuntimeException */
    public static function send(array $cfg, string $chatId, string $text): void
    {
        $token = trim((string) ($cfg['bot_token'] ?? ''));
        if (!preg_match('/^\d+:[A-Za-z0-9_\-]{20,}$/', $token)) {
            throw new \RuntimeException('Telegram: μη έγκυρο bot token.');
        }
        if (trim($chatId) === '') {
            throw new \RuntimeException('Telegram: δεν έχει οριστεί chat ID.');
        }
        [$code, $body] = Http::request(
            'POST',
            "https://api.telegram.org/bot$token/sendMessage",
            ['Content-Type: application/json'],
            (string) json_encode([
                'chat_id' => trim($chatId),
                'text' => mb_substr($text, 0, 4000),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ], JSON_UNESCAPED_UNICODE),
        );
        $data = json_decode($body, true);
        if ($code !== 200 || empty($data['ok'])) {
            throw new \RuntimeException('Telegram (' . $code . '): ' . ($data['description'] ?? 'άγνωστο σφάλμα'));
        }
    }

    /** Escapes text for Telegram HTML parse mode, then turns **bold** into <b>. */
    public static function format(string $text): string
    {
        $safe = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
        return (string) preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $safe);
    }
}
