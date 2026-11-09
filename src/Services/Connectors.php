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
        'slack' => 'Slack',
        'teams' => 'Microsoft Teams',
        'discord' => 'Discord',
        'webhook' => 'Webhook (JSON)',
    ];

    /** Catalog info for the "add connector" gallery: type => [channel, short description, brand colour, icon text] */
    public const CATALOG = [
        'smtp' => ['email', 'Οποιοσδήποτε mail server (Plesk, Gmail, Outlook) μέσω SMTP.', '#0EA5E9', '@'],
        'm365' => ['email', 'Αποστολή από mailbox Microsoft 365 μέσω Graph API, χωρίς SMTP.', '#2563EB', 'M'],
        'telegram' => ['telegram', 'Μηνύματα σε χρήστη, group ή channel μέσω Telegram bot.', '#229ED9', 'T'],
        'slack' => ['slack', 'Μηνύματα σε κανάλι Slack μέσω Incoming Webhook.', '#4A154B', 'S'],
        'teams' => ['teams', 'Κάρτες σε κανάλι Microsoft Teams μέσω Workflows webhook.', '#5B5FC7', 'T'],
        'discord' => ['discord', 'Μηνύματα σε κανάλι Discord μέσω webhook.', '#5865F2', 'D'],
        'webhook' => ['webhook', 'JSON POST σε δικό σου URL (n8n, Zapier, Make, δικό σου API), με υπογραφή HMAC.', '#0F172A', '{}'],
    ];

    /** Connector types that serve a notification channel. */
    public static function typesFor(string $channel): array
    {
        return match ($channel) {
            'email' => ['smtp', 'm365'],
            default => [$channel],
        };
    }

    public static function channelOf(string $type): string
    {
        return self::CATALOG[$type][0] ?? $type;
    }

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
        'slack' => [
            'webhook_url' => ['Incoming Webhook URL', 'password', true, true, 'https://hooks.slack.com/services/…'],
        ],
        'teams' => [
            'webhook_url' => ['Workflows webhook URL', 'password', true, true, 'Teams → κανάλι → ⋯ → Workflows → «Post to a channel when a webhook request is received»'],
        ],
        'discord' => [
            'webhook_url' => ['Webhook URL', 'password', true, true, 'Server settings → Integrations → Webhooks → New Webhook → Copy URL'],
        ],
        'webhook' => [
            'url' => ['URL', 'url', true, false, 'Δέχεται POST με JSON (event, title, message, job, run, tenant)'],
            'secret' => ['Secret για υπογραφή (προαιρετικό)', 'password', false, true, 'Αν οριστεί, στέλνεται header X-CronApp-Signature: sha256=HMAC(body)'],
        ],
    ];

    public static function find(int $tenantId, ?int $id): ?array
    {
        if (!$id) {
            return null;
        }
        return App::db()->one('SELECT * FROM connectors WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    }

    /** Default connector for a channel (email, telegram, slack, teams, discord, webhook). */
    public static function default(int $tenantId, string $channel): ?array
    {
        $types = self::typesFor($channel);
        $in = implode(',', array_fill(0, count($types), '?'));
        return App::db()->one(
            "SELECT * FROM connectors WHERE tenant_id = ? AND type IN ($in) ORDER BY is_default DESC, id ASC LIMIT 1",
            array_merge([$tenantId], $types),
        );
    }

    public static function config(array $connector): array
    {
        return Crypto::decryptJson($connector['config_enc']);
    }

    public static function listFor(int $tenantId, string $channel): array
    {
        $types = self::typesFor($channel);
        $in = implode(',', array_fill(0, count($types), '?'));
        return App::db()->all("SELECT id, name, type, is_default FROM connectors WHERE tenant_id = ? AND type IN ($in) ORDER BY name", array_merge([$tenantId], $types));
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
        if (in_array($connector['type'], ['slack', 'teams', 'discord', 'webhook'], true)) {
            $ctx = ['tenant' => ['id' => (int) $tenant['id'], 'name' => $tenant['name'], 'slug' => $tenant['slug']], 'app' => ['url' => abs_url('')], 'run' => ['link' => abs_url('connectors')]];
            \App\Services\Channels\ChatSender::send($connector['type'], $cfg, '✅ ' . $title, $text, $ctx, 'test');
            return;
        }
        $html = Notifier::emailHtml($tenant, $title, $text, null, null);
        EmailSender::send($connector['type'], $cfg, [$target], $title, $html, $text);
    }
}
