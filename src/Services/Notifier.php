<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Services\Channels\EmailSender;
use App\Services\Channels\PushSender;
use App\Services\Channels\TelegramSender;

/** Sends job event notifications through push, email and Telegram. */
final class Notifier
{
    public const EVENTS = [
        'failed' => 'Αποτυχία',
        'recovered' => 'Ανάκαμψη (ξανά επιτυχία)',
        'missed' => 'Χαμένη εκτέλεση',
        'slow' => 'Αργή απόκριση',
        'succeeded' => 'Επιτυχία (κάθε φορά)',
    ];

    public const CHANNELS = ['push' => 'Push', 'email' => 'Email', 'telegram' => 'Telegram'];

    public const DEFAULT_USER_EVENTS = ['failed', 'recovered', 'missed'];

    /** Built-in templates: event => channel => [subject, body] */
    public const DEFAULTS = [
        'failed' => [
            'email' => ['❌ Αποτυχία: {{ job.name }}', "Το job «{{ job.name }}» απέτυχε.\n\nΣφάλμα: {{ run.error }}\nHTTP status: {{ run.http_status|default('—') }}\nΔιάρκεια: {{ run.duration }}\nΠροσπάθειες: {{ run.attempts }}\nΏρα: {{ run.started_at }}\n\nResponse:\n{{ run.response_excerpt }}"],
            'telegram' => ['', "❌ **Αποτυχία: {{ job.name }}**\n{{ run.error }}\nHTTP {{ run.http_status|default('—') }} · {{ run.duration }} · {{ run.attempts }} προσπάθειες\n{{ run.link }}"],
            'push' => ['Αποτυχία: {{ job.name }}', "{{ run.error }} · {{ run.attempts }} προσπάθειες"],
        ],
        'recovered' => [
            'email' => ['✅ Ανάκαμψη: {{ job.name }}', "Το job «{{ job.name }}» εκτελείται ξανά κανονικά.\n\nHTTP status: {{ run.http_status }}\nΔιάρκεια: {{ run.duration }}\nΏρα: {{ run.started_at }}"],
            'telegram' => ['', "✅ **Ανάκαμψη: {{ job.name }}**\nHTTP {{ run.http_status }} · {{ run.duration }}\n{{ run.link }}"],
            'push' => ['Ανάκαμψη: {{ job.name }}', 'Εκτελείται ξανά κανονικά · HTTP {{ run.http_status }}'],
        ],
        'missed' => [
            'email' => ['⏰ Χαμένη εκτέλεση: {{ job.name }}', "Η εκτέλεση του «{{ job.name }}» για τις {{ run.scheduled_at }} δεν έγινε εγκαίρως, γιατί ο scheduler δεν έτρεχε.\nΈγινε εκτέλεση αναπλήρωσης."],
            'telegram' => ['', "⏰ **Χαμένη εκτέλεση: {{ job.name }}**\nΠρογραμματισμένη για {{ run.scheduled_at }}\n{{ run.link }}"],
            'push' => ['Χαμένη εκτέλεση: {{ job.name }}', 'Προγραμματισμένη για {{ run.scheduled_at }}'],
        ],
        'slow' => [
            'email' => ['🐢 Αργή απόκριση: {{ job.name }}', "Το job «{{ job.name }}» ολοκληρώθηκε σε {{ run.duration }}, πάνω από το όριο που έχει οριστεί."],
            'telegram' => ['', "🐢 **Αργή απόκριση: {{ job.name }}**\n{{ run.duration }}\n{{ run.link }}"],
            'push' => ['Αργή απόκριση: {{ job.name }}', 'Ολοκληρώθηκε σε {{ run.duration }}'],
        ],
        'succeeded' => [
            'email' => ['Επιτυχία: {{ job.name }}', "Το job «{{ job.name }}» εκτελέστηκε επιτυχώς.\nHTTP {{ run.http_status }} · {{ run.duration }} · {{ run.started_at }}"],
            'telegram' => ['', "✔️ **{{ job.name }}**\nHTTP {{ run.http_status }} · {{ run.duration }}"],
            'push' => ['Επιτυχία: {{ job.name }}', 'HTTP {{ run.http_status }} · {{ run.duration }}'],
        ],
    ];

    /** Seeds editable default templates for a new tenant. */
    public static function seedTemplates(int $tenantId): void
    {
        $db = App::db();
        foreach (self::DEFAULTS as $event => $channels) {
            foreach ($channels as $channel => [$subject, $body]) {
                $db->insert('message_templates', [
                    'tenant_id' => $tenantId,
                    'name' => self::EVENTS[$event] . ' · ' . self::CHANNELS[$channel],
                    'event' => $event,
                    'channel' => $channel,
                    'subject' => $subject !== '' ? $subject : null,
                    'body' => $body,
                    'is_default' => 1,
                ]);
            }
        }
    }

    public static function context(string $event, array $job, ?array $run, array $tenant): array
    {
        $tz = $job['timezone'] ?: $tenant['timezone'];
        $statusLabel = isset($run['status']) ? (RUN_STATUS[$run['status']][0] ?? $run['status']) : '';
        return [
            'event' => $event,
            'event_label' => self::EVENTS[$event] ?? $event,
            'job' => [
                'id' => (int) $job['id'], 'name' => $job['name'], 'url' => $job['url'], 'method' => $job['method'],
                'link' => abs_url('jobs/' . $job['id'] . '/edit'),
            ],
            'run' => $run ? [
                'id' => (int) $run['id'],
                'status' => $run['status'],
                'status_label' => $statusLabel,
                'http_status' => $run['http_status'] !== null ? (int) $run['http_status'] : null,
                'duration_ms' => $run['duration_ms'] !== null ? (int) $run['duration_ms'] : null,
                'duration' => fmt_ms($run['duration_ms'] !== null ? (int) $run['duration_ms'] : null),
                'attempts' => (int) $run['attempts'],
                'error' => (string) ($run['error'] ?? ''),
                'started_at' => fmt_dt($run['started_at'] ?? $run['scheduled_at'], $tz, 'd/m/Y H:i:s'),
                'scheduled_at' => fmt_dt($run['scheduled_at'], $tz, 'd/m/Y H:i'),
                'link' => abs_url('runs/' . $run['id']),
                'response_excerpt' => mb_substr((string) ($run['response_body'] ?? ''), 0, 500),
            ] : [],
            'tenant' => ['id' => (int) $tenant['id'], 'name' => $tenant['name'], 'slug' => $tenant['slug']],
            'app' => ['name' => App::name(), 'url' => abs_url('')],
        ];
    }

    /** Fires one event for a job run according to the job's notification rules. */
    public static function dispatch(string $event, array $job, ?array $run, array $tenant): void
    {
        $db = App::db();
        $rules = $db->all('SELECT * FROM job_notifications WHERE job_id = ? AND event = ?', [$job['id'], $event]);
        if (!$rules) {
            return;
        }
        // Throttle repeated failure alerts.
        if ($event === 'failed') {
            $minutes = (int) Settings::get((int) $tenant['id'], 'notify.failure_repeat_minutes', '60');
            if ($minutes > 0) {
                $recent = $db->value(
                    "SELECT 1 FROM notification_log WHERE job_id = ? AND event = 'failed' AND status = 'sent' AND created_at > ? LIMIT 1",
                    [$job['id'], gmdate('Y-m-d H:i:s', time() - $minutes * 60)],
                );
                $prevFailed = ($job['last_status'] ?? null) === 'failed';
                if ($recent && $prevFailed) {
                    return;
                }
            }
        }
        $ctx = self::context($event, $job, $run, $tenant);
        foreach ($rules as $rule) {
            $channel = $rule['channel'];
            try {
                [$subject, $body] = self::resolveTemplate((int) $tenant['id'], $event, $channel, $rule['template_id'] ? (int) $rule['template_id'] : null);
                $subjectR = trim(TemplateRenderer::render($subject, $ctx));
                $bodyR = trim(TemplateRenderer::render($body, $ctx));
                $targets = self::send($channel, $job, $tenant, $event, $subjectR, $bodyR, $ctx);
                foreach ($targets as $t) {
                    self::log($tenant, $job, $run, $event, $channel, $t, 'sent', null);
                }
            } catch (\Throwable $e) {
                self::log($tenant, $job, $run, $event, $channel, '—', 'failed', $e->getMessage());
            }
        }
    }

    /** @return array{0:string,1:string} */
    public static function resolveTemplate(int $tenantId, string $event, string $channel, ?int $templateId): array
    {
        $db = App::db();
        $tpl = null;
        if ($templateId) {
            $tpl = $db->one('SELECT * FROM message_templates WHERE id = ? AND tenant_id = ?', [$templateId, $tenantId]);
        }
        $tpl ??= $db->one(
            'SELECT * FROM message_templates WHERE tenant_id = ? AND event = ? AND channel = ? ORDER BY is_default DESC, id ASC LIMIT 1',
            [$tenantId, $event, $channel],
        );
        if ($tpl) {
            return [(string) ($tpl['subject'] ?? ''), (string) $tpl['body']];
        }
        return self::DEFAULTS[$event][$channel] ?? [$event, $event];
    }

    /** @return string[] targets that were sent to */
    private static function send(string $channel, array $job, array $tenant, string $event, string $subject, string $body, array $ctx): array
    {
        $tid = (int) $tenant['id'];
        if ($channel === 'email') {
            $conn = Connectors::find($tid, $job['email_connector_id'] ? (int) $job['email_connector_id'] : null) ?? Connectors::default($tid, 'email');
            if (!$conn) {
                throw new \RuntimeException('Δεν υπάρχει email connector.');
            }
            $list = trim((string) ($job['notify_emails'] ?: Settings::get($tid, 'notify.emails', '')));
            $to = array_filter(array_map('trim', preg_split('/[,;\s]+/', $list) ?: []));
            if (!$to) {
                throw new \RuntimeException('Δεν έχουν οριστεί παραλήπτες email.');
            }
            $html = self::emailHtml($tenant, $subject, $body, $ctx['run']['link'] ?? null, $event);
            EmailSender::send($conn['type'], Connectors::config($conn), $to, $subject, $html, $body);
            return [implode(', ', $to)];
        }
        if ($channel === 'telegram') {
            $conn = Connectors::find($tid, $job['telegram_connector_id'] ? (int) $job['telegram_connector_id'] : null) ?? Connectors::default($tid, 'telegram');
            if (!$conn) {
                throw new \RuntimeException('Δεν υπάρχει Telegram connector.');
            }
            $cfg = Connectors::config($conn);
            $chat = (string) ($job['notify_telegram_chat'] ?: ($cfg['default_chat_id'] ?? '') ?: Settings::get($tid, 'notify.telegram_chat', ''));
            TelegramSender::send($cfg, $chat, TelegramSender::format($body));
            return ['chat ' . $chat];
        }
        if ($channel === 'push') {
            $users = self::pushRecipients($tid, $event);
            if (!$users) {
                return [];
            }
            $res = PushSender::sendToUsers($users, [
                'title' => $subject,
                'body' => $body,
                'url' => $ctx['run']['link'] ?? abs_url(''),
                'tag' => 'job-' . $job['id'] . '-' . $event,
                'icon' => abs_url('assets/icons/icon-192.png'),
                'badge' => abs_url('assets/icons/badge-72.png'),
            ]);
            if ($res['sent'] === 0 && $res['failed'] > 0) {
                throw new \RuntimeException('Push: ' . implode('; ', array_slice($res['errors'], 0, 3)));
            }
            return $res['sent'] > 0 ? [$res['sent'] . ' συσκευές'] : [];
        }
        throw new \RuntimeException("Άγνωστο κανάλι $channel");
    }

    /** Users of the tenant who want push for this event. */
    public static function pushRecipients(int $tenantId, string $event): array
    {
        $rows = App::db()->all(
            'SELECT m.user_id, p.events FROM memberships m
             JOIN users u ON u.id = m.user_id AND u.status = \'active\'
             LEFT JOIN user_notification_prefs p ON p.user_id = m.user_id AND p.tenant_id = m.tenant_id
             WHERE m.tenant_id = ?',
            [$tenantId],
        );
        $ids = [];
        foreach ($rows as $r) {
            $events = $r['events'] !== null ? array_filter(explode(',', $r['events'])) : self::DEFAULT_USER_EVENTS;
            if (in_array($event, $events, true)) {
                $ids[] = (int) $r['user_id'];
            }
        }
        return $ids;
    }

    private static function log(array $tenant, array $job, ?array $run, string $event, string $channel, string $target, string $status, ?string $error): void
    {
        App::db()->insert('notification_log', [
            'tenant_id' => $tenant['id'],
            'job_id' => $job['id'],
            'run_id' => $run['id'] ?? null,
            'event' => $event,
            'channel' => $channel,
            'target' => mb_substr($target, 0, 255),
            'status' => $status,
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
        ]);
    }

    /** Branded HTML email wrapper around plain-text template output. */
    public static function emailHtml(array $tenant, string $title, string $text, ?string $link, ?string $event): string
    {
        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $tenant['brand_color']) ? $tenant['brand_color'] : '#4F46E5';
        $logo = $tenant['logo_path'] ? '<img src="' . e(abs_url('branding/' . $tenant['id'] . '/logo')) . '" alt="" height="36" style="display:block;max-height:36px">' : '<div style="width:36px;height:36px;border-radius:9px;background:' . $color . ';color:#fff;font:700 14px/36px Arial,sans-serif;text-align:center">' . e(initials($tenant['name'])) . '</div>';
        $accent = match ($event) {
            'failed' => '#DC2626',
            'recovered', 'succeeded' => '#16A34A',
            'slow', 'missed' => '#D97706',
            default => $color,
        };
        $button = $link ? '<p style="margin:24px 0 0"><a href="' . e($link) . '" style="display:inline-block;padding:12px 20px;border-radius:10px;background:' . $color . ';color:#fff;text-decoration:none;font-weight:600">Προβολή στο ' . e(App::name()) . '</a></p>' : '';
        return '<!doctype html><html lang="el"><body style="margin:0;background:#F6F7F9;font-family:Segoe UI,Arial,sans-serif;color:#0F172A">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border:1px solid #E5E7EB;border-radius:14px">'
            . '<tr><td style="padding:20px 24px;border-bottom:1px solid #EEF1F5"><table role="presentation"><tr><td>' . $logo . '</td><td style="padding-left:12px;font-weight:600">' . e($tenant['name']) . '</td></tr></table></td></tr>'
            . '<tr><td style="padding:24px;border-top:4px solid ' . $accent . '"><h1 style="margin:0 0 16px;font-size:18px">' . e($title) . '</h1>'
            . '<div style="font-size:14px;line-height:1.6;white-space:pre-wrap">' . nl2br(e($text)) . '</div>' . $button . '</td></tr>'
            . '<tr><td style="padding:14px 24px;font-size:12px;color:#64748B;border-top:1px solid #EEF1F5">Στάλθηκε από ' . e(App::name()) . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
