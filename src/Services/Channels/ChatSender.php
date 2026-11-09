<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Core\App;
use App\Services\SsrfGuard;

/**
 * Chat / webhook add-ons: Slack, Microsoft Teams, Discord and a generic JSON webhook.
 * All of them are "incoming webhook" URLs configured on the connector.
 */
final class ChatSender
{
    private const COLORS = ['failed' => '#DC2626', 'recovered' => '#16A34A', 'succeeded' => '#16A34A', 'slow' => '#D97706', 'missed' => '#64748B'];

    /** @throws \RuntimeException */
    public static function send(string $type, array $cfg, string $title, string $text, array $ctx, ?string $event): void
    {
        $url = trim((string) ($cfg['webhook_url'] ?? $cfg['url'] ?? ''));
        self::checkUrl($type, $url);
        $link = $ctx['run']['link'] ?? ($ctx['app']['url'] ?? null);
        $color = self::COLORS[$event ?? ''] ?? '#4F46E5';
        $headers = ['Content-Type: application/json'];

        switch ($type) {
            case 'slack':
                $payload = [
                    'text' => $title,
                    'blocks' => array_values(array_filter([
                        ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*' . self::slackEscape($title) . "*\n" . self::slackEscape($text)]],
                        $link ? ['type' => 'actions', 'elements' => [['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => 'Άνοιγμα στο ' . App::name()], 'url' => $link]]] : null,
                    ])),
                ];
                break;
            case 'discord':
                $payload = [
                    'username' => App::name(),
                    'embeds' => [[
                        'title' => mb_substr($title, 0, 256),
                        'description' => mb_substr($text, 0, 4000),
                        'url' => $link,
                        'color' => hexdec(ltrim($color, '#')),
                    ]],
                ];
                break;
            case 'teams':
                // Works with Teams "Workflows" webhooks and classic incoming webhooks (Adaptive Card).
                $body = [
                    ['type' => 'TextBlock', 'text' => $title, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
                    ['type' => 'TextBlock', 'text' => str_replace("\n", "\n\n", $text), 'wrap' => true],
                ];
                $card = ['$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4', 'body' => $body];
                if ($link) {
                    $card['actions'] = [['type' => 'Action.OpenUrl', 'title' => 'Άνοιγμα στο ' . App::name(), 'url' => $link]];
                }
                $payload = ['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => $card]]];
                break;
            case 'webhook':
                $payload = [
                    'event' => $event,
                    'title' => $title,
                    'message' => $text,
                    'job' => $ctx['job'] ?? null,
                    'run' => $ctx['run'] ?? null,
                    'tenant' => $ctx['tenant'] ?? null,
                    'sent_at' => gmdate(DATE_ATOM),
                ];
                $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headers[] = 'User-Agent: ' . App::name() . '-Webhook/1.0';
                $headers[] = 'X-CronApp-Event: ' . ($event ?? 'test');
                if (!empty($cfg['secret'])) {
                    $headers[] = 'X-CronApp-Signature: sha256=' . hash_hmac('sha256', $json, (string) $cfg['secret']);
                }
                [$code, $resp] = Http::request('POST', $url, $headers, $json, 15);
                if ($code < 200 || $code >= 300) {
                    throw new \RuntimeException("Webhook ($code): " . mb_substr(trim($resp), 0, 200));
                }
                return;
            default:
                throw new \RuntimeException("Άγνωστος τύπος connector: $type");
        }

        [$code, $resp] = Http::request('POST', $url, $headers, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 15);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException(ucfirst($type) . " ($code): " . mb_substr(trim($resp), 0, 200));
        }
    }

    private static function checkUrl(string $type, string $url): void
    {
        if (!preg_match('#^https://#i', $url)) {
            throw new \RuntimeException('Το webhook URL πρέπει να ξεκινά με https://');
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $expected = [
            'slack' => ['hooks.slack.com'],
            'discord' => ['discord.com', 'discordapp.com'],
        ][$type] ?? null;
        if ($expected !== null && !in_array($host, $expected, true)) {
            throw new \RuntimeException('Το URL δεν είναι ' . ucfirst($type) . ' webhook (' . implode(' / ', $expected) . ').');
        }
        try {
            SsrfGuard::check($url);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage());
        }
    }

    private static function slackEscape(string $s): string
    {
        $s = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
        return (string) preg_replace('/\*\*(.+?)\*\*/u', '*$1*', $s);
    }
}
