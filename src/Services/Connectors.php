<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Services\Channels\EmailSender;
use App\Services\Channels\TelegramSender;

final class Connectors
{
    public const TYPES = [
        'smtp' => 'Email · SMTP',
        'm365' => 'Email · Microsoft 365',
        'telegram' => 'Telegram bot',
    ];

    /** Field definitions per type: key => [label, input type, required, secret, help] */
    public const FIELDS = [
        'smtp' => [
            'host' => ['SMTP host', 'text', true, false, 'π.χ. mail.example.gr'],
            'port' => ['Port', 'number', true, false, '587 για STARTTLS, 465 για SSL'],
            'encryption' => ['Κρυπτογράφηση', 'select:tls=STARTTLS,ssl=SSL/TLS,none=Καμία', true, false, ''],
            'username' => ['Username', 'text', false, false, ''],
            'password' => ['Password', 'password', false, true, ''],
            'from_email' => ['Αποστολέας (email)', 'email', true, false, ''],
            'from_name' => ['Όνομα αποστολέα', 'text', false, false, ''],
        ],
        'm365' => [
            'tenant_id' => ['Directory (tenant) ID', 'text', true, false, 'Από το Microsoft Entra ID → App registrations'],
            'client_id' => ['Application (client) ID', 'text', true, false, ''],
            'client_secret' => ['Client secret', 'password', true, true, 'Η εφαρμογή χρειάζεται το application permission Mail.Send'],
            'sender' => ['Mailbox αποστολέα', 'email', true, false, 'π.χ. alerts@example.gr'],
        ],
        'telegram' => [
            'bot_token' => ['Bot token', 'password', true, true, 'Από τον @BotFather'],
            'default_chat_id' => ['Προεπιλεγμένο chat ID', 'text', false, false, 'Χρήστης, group ή channel (π.χ. -1001234567890)'],
        ],
    ];

    public static function find(int $tenantId, ?int $id): ?array
    {
        if (!$id) {
            return null;
        }
        return App::db()->one('SELECT * FROM connectors WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    }

    /** Default connector for a channel ('email' or 'telegram'). */
    public static function default(int $tenantId, string $channel): ?array
    {
        $types = $channel === 'email' ? "'smtp','m365'" : "'telegram'";
        return App::db()->one(
            "SELECT * FROM connectors WHERE tenant_id = ? AND type IN ($types) ORDER BY is_default DESC, id ASC LIMIT 1",
            [$tenantId],
        );
    }

    public static function config(array $connector): array
    {
        return Crypto::decryptJson($connector['config_enc']);
    }

    public static function listFor(int $tenantId, string $channel): array
    {
        $types = $channel === 'email' ? "'smtp','m365'" : "'telegram'";
        return App::db()->all("SELECT id, name, type, is_default FROM connectors WHERE tenant_id = ? AND type IN ($types) ORDER BY name", [$tenantId]);
    }

    /** Sends a test message. @throws \RuntimeException */
    public static function test(array $connector, array $tenant, string $target): void
    {
        $cfg = self::config($connector);
        $title = 'Δοκιμαστικό μήνυμα από ' . App::name();
        $text = "Ο connector «{$connector['name']}» του {$tenant['name']} λειτουργεί σωστά.";
        if ($connector['type'] === 'telegram') {
            $chat = $target !== '' ? $target : (string) ($cfg['default_chat_id'] ?? '');
            TelegramSender::send($cfg, $chat, '✅ <b>' . e($title) . "</b>\n" . e($text));
            return;
        }
        $html = Notifier::emailHtml($tenant, $title, $text, null, null);
        EmailSender::send($connector['type'], $cfg, [$target], $title, $html, $text);
    }
}
