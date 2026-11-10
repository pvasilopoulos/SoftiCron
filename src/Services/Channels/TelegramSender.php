<?php

declare(strict_types=1);

namespace App\Services\Channels;

final class TelegramSender
{
    /** @throws \RuntimeException */
    /** @param array<int, array{0:string,1:string}> $buttons [text, url] link buttons under the message */
    public static function send(array $cfg, string $chatId, string $text, array $buttons = []): void
    {
        $token = trim((string) ($cfg['bot_token'] ?? ''));
        if (!preg_match('/^\d+:[A-Za-z0-9_\-]{20,}$/', $token)) {
            throw new \RuntimeException('Telegram: μη έγκυρο bot token.');
        }
        if (trim($chatId) === '') {
            throw new \RuntimeException('Telegram: δεν έχει οριστεί chat ID.');
        }
        $payload = [
            'chat_id' => trim($chatId),
            'text' => mb_substr($text, 0, 4000),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
        // Telegram only accepts public http(s) URLs on buttons.
        $buttons = array_values(array_filter($buttons, fn ($b) => preg_match('#^https?://#i', $b[1]) && !preg_match('#^https?://(localhost|127\.|10\.|192\.168\.|\[::1\])#i', $b[1])));
        if ($buttons) {
            $payload['reply_markup'] = ['inline_keyboard' => [array_map(fn ($b) => ['text' => $b[0], 'url' => $b[1]], $buttons)]];
        }
        [$code, $body] = Http::request(
            'POST',
            "https://api.telegram.org/bot$token/sendMessage",
            ['Content-Type: application/json'],
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
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
