<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Core\App;
use App\Services\SsrfGuard;

/**
 * Chat / webhook add-ons: Slack, Microsoft Teams, Discord and a generic JSON webhook.
 * All of them are "incoming webhook" URLs configured on the connector.
 * Messages are rich cards: colour per event, short facts, and "Το είδα" / "Άνοιγμα" buttons.
 */
final class ChatSender
{
    private const COLORS = [
        'failed' => '#DC2626', 'escalated' => '#B91C1C', 'recovered' => '#16A34A', 'succeeded' => '#16A34A',
        'slow' => '#D97706', 'missed' => '#64748B', 'acked' => '#4F46E5',
    ];

    private const LABELS = [
        'failed' => 'Αποτυχία', 'escalated' => 'Κλιμάκωση', 'recovered' => 'Ανάκαμψη', 'succeeded' => 'Επιτυχία',
        'slow' => 'Αργή απόκριση', 'missed' => 'Χαμένη εκτέλεση', 'acked' => 'Ανάληψη',
    ];

    /** @throws \RuntimeException */
    public static function send(string $type, array $cfg, string $title, string $text, array $ctx, ?string $event): void
    {
        $url = trim((string) ($cfg['webhook_url'] ?? $cfg['url'] ?? ''));
        self::checkUrl($type, $url);
        $payload = self::payload($type, $cfg, $title, $text, $ctx, $event);
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type: application/json'];
        if ($type === 'webhook') {
            $headers[] = 'User-Agent: ' . App::name() . '-Webhook/1.0';
            $headers[] = 'X-CronApp-Event: ' . ($event ?? 'test');
            if (!empty($cfg['secret'])) {
                $headers[] = 'X-CronApp-Signature: sha256=' . hash_hmac('sha256', $json, (string) $cfg['secret']);
            }
        }
        [$code, $resp] = Http::request('POST', $url, $headers, $json, 15);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException(($type === 'webhook' ? 'Webhook' : ucfirst($type)) . " ($code): " . mb_substr(trim($resp), 0, 200));
        }
    }

    /** The JSON body for each service (public for tests / previews). */
    public static function payload(string $type, array $cfg, string $title, string $text, array $ctx, ?string $event): array
    {
        $link = $ctx['run']['link'] ?? ($ctx['job']['link'] ?? ($ctx['app']['url'] ?? null));
        $ack = ($ctx['ack']['link'] ?? '') ?: null;
        $color = self::COLORS[$event ?? ''] ?? '#4F46E5';
        $alarming = in_array($event, ['failed', 'escalated'], true);
        $mention = $alarming ? trim((string) ($cfg['mention'] ?? '')) : '';
        if ($type === 'slack') {
            // Slack only pings with its own syntax.
            $mention = (string) preg_replace(['/(?<![<!])@(here|channel|everyone)\b/'], ['<!$1>'], $mention);
        }
        // Short facts as fields; the template text already carries the details.
        $facts = array_filter([
            'Job' => $ctx['job']['name'] ?? null,
            'Γεγονός' => self::LABELS[$event ?? ''] ?? null,
            'Εκτέλεση' => !empty($ctx['run']['id']) ? '#' . $ctx['run']['id'] . (!empty($ctx['run']['status_label']) ? ' · ' . $ctx['run']['status_label'] : '') : null,
            'Οργανισμός' => $ctx['tenant']['name'] ?? null,
        ]);
        $openLabel = 'Άνοιγμα στο ' . App::name();

        switch ($type) {
            case 'slack':
                $blocks = [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*' . self::slackEscape($title) . '*' . ($text !== '' ? "\n" . self::slackEscape($text) : '')]],
                ];
                if ($facts) {
                    $blocks[] = ['type' => 'section', 'fields' => array_values(array_map(
                        fn ($k, $v) => ['type' => 'mrkdwn', 'text' => '*' . $k . "*\n" . self::slackEscape((string) $v)],
                        array_keys($facts),
                        $facts,
                    ))];
                }
                $buttons = [];
                if ($ack) {
                    $buttons[] = ['type' => 'button', 'style' => 'danger', 'text' => ['type' => 'plain_text', 'text' => '✋ Το είδα'], 'url' => $ack];
                }
                if ($link) {
                    $buttons[] = ['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => $openLabel], 'url' => $link];
                }
                if ($buttons) {
                    $blocks[] = ['type' => 'actions', 'elements' => $buttons];
                }
                // Top-level text = notification preview (+ mention); the coloured attachment holds the card.
                return ['text' => trim($mention . ' ' . $title), 'attachments' => [['color' => $color, 'blocks' => $blocks]]];

            case 'discord':
                // Webhook messages cannot carry link buttons: the links go in the description.
                $links = array_filter([$ack ? '[✋ Το είδα](' . $ack . ')' : null, $link ? '[' . $openLabel . '](' . $link . ')' : null]);
                $out = [
                    'username' => App::name(),
                    'embeds' => [array_filter([
                        'title' => mb_substr($title, 0, 256),
                        'description' => mb_substr($text . ($links ? "\n\n" . implode(' · ', $links) : ''), 0, 4000),
                        'url' => $link,
                        'color' => hexdec(ltrim($color, '#')),
                        'fields' => array_values(array_map(fn ($k, $v) => ['name' => $k, 'value' => mb_substr((string) $v, 0, 1000), 'inline' => true], array_keys($facts), $facts)),
                        'footer' => ['text' => App::name()],
                        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                    ], fn ($v) => $v !== null && $v !== [])],
                ];
                if ($mention !== '') {
                    $out['content'] = $mention;
                    $out['allowed_mentions'] = ['parse' => ['everyone', 'roles', 'users']];
                }
                return $out;

            case 'teams':
                // Works with Teams "Workflows" webhooks and classic incoming webhooks (Adaptive Card).
                $style = match ($event) {
                    'failed', 'escalated' => 'attention',
                    'recovered', 'succeeded' => 'good',
                    'slow', 'missed' => 'warning',
                    default => 'accent',
                };
                $body = [
                    ['type' => 'Container', 'style' => $style, 'bleed' => true, 'items' => [
                        ['type' => 'TextBlock', 'text' => $title, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
                    ]],
                ];
                if ($text !== '') {
                    $body[] = ['type' => 'TextBlock', 'text' => str_replace("\n", "\n\n", $text), 'wrap' => true, 'spacing' => 'Medium'];
                }
                if ($facts) {
                    $body[] = ['type' => 'FactSet', 'facts' => array_values(array_map(fn ($k, $v) => ['title' => $k, 'value' => (string) $v], array_keys($facts), $facts))];
                }
                $card = ['$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4', 'body' => $body, 'msteams' => ['width' => 'Full']];
                $actions = [];
                if ($ack) {
                    $actions[] = ['type' => 'Action.OpenUrl', 'title' => '✋ Το είδα', 'url' => $ack, 'style' => 'destructive'];
                }
                if ($link) {
                    $actions[] = ['type' => 'Action.OpenUrl', 'title' => $openLabel, 'url' => $link];
                }
                if ($actions) {
                    $card['actions'] = $actions;
                }
                return ['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => $card]]];

            case 'webhook':
                return [
                    'event' => $event,
                    'title' => $title,
                    'message' => $text,
                    'job' => $ctx['job'] ?? null,
                    'run' => $ctx['run'] ?? null,
                    'tenant' => $ctx['tenant'] ?? null,
                    'ack_url' => $ack,
                    'sent_at' => gmdate(DATE_ATOM),
                ];
        }
        throw new \RuntimeException("Άγνωστος τύπος connector: $type");
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
