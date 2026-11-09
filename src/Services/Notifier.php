<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Services\Channels\ChatSender;
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

    /** Delivery channels (columns of the job notification matrix). */
    public const CHANNELS = [
        'push' => 'Push', 'email' => 'Email', 'telegram' => 'Telegram',
        'slack' => 'Slack', 'teams' => 'Teams', 'discord' => 'Discord', 'webhook' => 'Webhook',
    ];

    /** Channels that need a connector of the same type before they can be used. */
    public const CONNECTOR_CHANNELS = ['slack', 'teams', 'discord', 'webhook'];

    /** Template "channels": Slack/Teams/Discord/Webhook share the "chat" template. */
    public const TEMPLATE_CHANNELS = ['email' => 'Email', 'telegram' => 'Telegram', 'push' => 'Push', 'chat' => 'Chat (Slack · Teams · Discord · Webhook)'];

    public static function templateChannel(string $channel): string
    {
        return in_array($channel, self::CONNECTOR_CHANNELS, true) ? 'chat' : $channel;
    }

    /** Channels available for jobs of this tenant (connector-based ones only if configured). */
    public static function availableChannels(int $tenantId): array
    {
        $types = App::db()->column('SELECT DISTINCT type FROM connectors WHERE tenant_id = ?', [$tenantId]);
        $out = [];
        foreach (self::CHANNELS as $k => $l) {
            if (!in_array($k, self::CONNECTOR_CHANNELS, true) || in_array($k, $types, true)) {
                $out[$k] = $l;
            }
        }
        return $out;
    }

    public const DEFAULT_USER_EVENTS = ['failed', 'recovered', 'missed'];

    /** Built-in templates: event => channel => [subject, body] */
    public const DEFAULTS = [
        'failed' => [
            'email' => ['❌ Αποτυχία: {{ job.name }}', "Το job «{{ job.name }}» απέτυχε.\n\nΣφάλμα: {{ run.error }}\nHTTP status: {{ run.http_status|default('—') }}\nΔιάρκεια: {{ run.duration }}\nΠροσπάθειες: {{ run.attempts }}\nΏρα: {{ run.started_at }}\n\nResponse:\n{{ run.response_excerpt }}"],
            'telegram' => ['', "❌ **Αποτυχία: {{ job.name }}**\n{{ run.error }}\nHTTP {{ run.http_status|default('—') }} · {{ run.duration }} · {{ run.attempts }} προσπάθειες\n{{ run.link }}"],
            'push' => ['Αποτυχία: {{ job.name }}', "{{ run.error }} · {{ run.attempts }} προσπάθειες"],
            'chat' => ['❌ Αποτυχία: {{ job.name }}', "{{ run.error }}\nHTTP {{ run.http_status|default('—') }} · {{ run.duration }} · {{ run.attempts }} προσπάθειες · {{ run.started_at }}"],
        ],
        'recovered' => [
            'email' => ['✅ Ανάκαμψη: {{ job.name }}', "Το job «{{ job.name }}» εκτελείται ξανά κανονικά.\n\nHTTP status: {{ run.http_status }}\nΔιάρκεια: {{ run.duration }}\nΏρα: {{ run.started_at }}"],
            'telegram' => ['', "✅ **Ανάκαμψη: {{ job.name }}**\nHTTP {{ run.http_status }} · {{ run.duration }}\n{{ run.link }}"],
            'push' => ['Ανάκαμψη: {{ job.name }}', 'Εκτελείται ξανά κανονικά · HTTP {{ run.http_status }}'],
            'chat' => ['✅ Ανάκαμψη: {{ job.name }}', 'Εκτελείται ξανά κανονικά · HTTP {{ run.http_status }} · {{ run.duration }}'],
        ],
        'missed' => [
            'email' => ['⏰ Χαμένη εκτέλεση: {{ job.name }}', "Η εκτέλεση του «{{ job.name }}» για τις {{ run.scheduled_at }} δεν έγινε εγκαίρως, γιατί ο scheduler δεν έτρεχε.\nΈγινε εκτέλεση αναπλήρωσης."],
            'telegram' => ['', "⏰ **Χαμένη εκτέλεση: {{ job.name }}**\nΠρογραμματισμένη για {{ run.scheduled_at }}\n{{ run.link }}"],
            'push' => ['Χαμένη εκτέλεση: {{ job.name }}', 'Προγραμματισμένη για {{ run.scheduled_at }}'],
            'chat' => ['⏰ Χαμένη εκτέλεση: {{ job.name }}', 'Προγραμματισμένη για {{ run.scheduled_at }}. Ο scheduler δεν έτρεχε· έγινε εκτέλεση αναπλήρωσης.'],
        ],
        'slow' => [
            'email' => ['🐢 Αργή απόκριση: {{ job.name }}', "Το job «{{ job.name }}» ολοκληρώθηκε σε {{ run.duration }}, πάνω από το όριο που έχει οριστεί."],
            'telegram' => ['', "🐢 **Αργή απόκριση: {{ job.name }}**\n{{ run.duration }}\n{{ run.link }}"],
            'push' => ['Αργή απόκριση: {{ job.name }}', 'Ολοκληρώθηκε σε {{ run.duration }}'],
            'chat' => ['🐢 Αργή απόκριση: {{ job.name }}', 'Ολοκληρώθηκε σε {{ run.duration }}, πάνω από το όριο.'],
        ],
        'succeeded' => [
            'email' => ['Επιτυχία: {{ job.name }}', "Το job «{{ job.name }}» εκτελέστηκε επιτυχώς.\nHTTP {{ run.http_status }} · {{ run.duration }} · {{ run.started_at }}"],
            'telegram' => ['', "✔️ **{{ job.name }}**\nHTTP {{ run.http_status }} · {{ run.duration }}"],
            'push' => ['Επιτυχία: {{ job.name }}', 'HTTP {{ run.http_status }} · {{ run.duration }}'],
            'chat' => ['✔️ Επιτυχία: {{ job.name }}', 'HTTP {{ run.http_status }} · {{ run.duration }} · {{ run.started_at }}'],
        ],
    ];

    /** Seeds editable default templates for a new tenant (and adds any missing ones later). */
    public static function seedTemplates(int $tenantId): void
    {
        $db = App::db();
        $have = [];
        foreach ($db->all('SELECT DISTINCT event, channel FROM message_templates WHERE tenant_id = ?', [$tenantId]) as $r) {
            $have[$r['event'] . '|' . $r['channel']] = true;
        }
        foreach (self::DEFAULTS as $event => $channels) {
            foreach ($channels as $channel => [$subject, $body]) {
                if (isset($have["$event|$channel"])) {
                    continue;
                }
                $db->insert('message_templates', [
                    'tenant_id' => $tenantId,
                    'name' => self::EVENTS[$event] . ' · ' . (self::TEMPLATE_CHANNELS[$channel] === self::TEMPLATE_CHANNELS['chat'] ? 'Chat' : self::TEMPLATE_CHANNELS[$channel]),
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
            $subjectR = $bodyR = null;
            try {
                [$subject, $body] = self::resolveTemplate((int) $tenant['id'], $event, self::templateChannel($channel), $rule['template_id'] ? (int) $rule['template_id'] : null);
                $subjectR = trim(TemplateRenderer::render($subject, $ctx));
                $bodyR = trim(TemplateRenderer::render($body, $ctx));
                $targets = self::send($channel, $job, $tenant, $event, $subjectR, $bodyR, $ctx);
                if (!$targets) {
                    self::log($tenant, $job, $run, $event, $channel, '—', 'skipped', 'Δεν υπάρχει συσκευή για αποστολή (κανείς δεν έχει ενεργό push για αυτό το γεγονός).', $subjectR, $bodyR);
                }
                foreach ($targets as $t) {
                    self::log($tenant, $job, $run, $event, $channel, $t, 'sent', null, $subjectR, $bodyR);
                }
            } catch (\Throwable $e) {
                self::log($tenant, $job, $run, $event, $channel, self::$lastTarget ?? '—', 'failed', $e->getMessage(), $subjectR, $bodyR);
            }
            self::$lastTarget = null;
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
            self::$lastTarget = implode(', ', $to);
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
            self::$lastTarget = 'chat ' . $chat . ' · ' . $conn['name'];
            TelegramSender::send($cfg, $chat, TelegramSender::format($body));
            return [self::$lastTarget];
        }
        if ($channel === 'push') {
            $subs = self::pushSubscriptions($job, $tid, $event);
            if (!$subs) {
                return [];
            }
            $res = PushSender::sendToSubscriptions($subs, [
                'title' => $subject,
                'body' => $body,
                'url' => $ctx['run']['link'] ?? abs_url(''),
                'tag' => 'job-' . $job['id'] . '-' . $event,
                'icon' => abs_url('assets/icons/icon-192.png'),
                'badge' => abs_url('assets/icons/badge-72.png'),
            ]);
            self::$lastTarget = self::deviceNames($subs);
            if ($res['sent'] === 0 && $res['failed'] > 0) {
                throw new \RuntimeException('Push: ' . implode('; ', array_slice($res['errors'], 0, 3)));
            }
            if ($res['failed'] > 0) {
                // Partial success: log the failed devices separately.
                $failedIds = array_diff($subs, $res['sent_ids']);
                self::log($tenant, $job, null, $event, 'push', self::deviceNames($failedIds), 'failed', implode('; ', array_slice($res['errors'], 0, 3)), $subject, $body, $ctx['run']['id'] ?? null);
            }
            return $res['sent_ids'] ? [self::deviceNames($res['sent_ids'])] : [];
        }
        if (in_array($channel, self::CONNECTOR_CHANNELS, true)) {
            $conn = Connectors::default($tid, $channel);
            if (!$conn) {
                throw new \RuntimeException('Δεν υπάρχει ' . self::CHANNELS[$channel] . ' connector.');
            }
            self::$lastTarget = $conn['name'];
            ChatSender::send($conn['type'], Connectors::config($conn), $subject, $body, $ctx, $event);
            return [$conn['name']];
        }
        throw new \RuntimeException("Άγνωστο κανάλι $channel");
    }

    /**
     * Sends a template rendered with sample data to the current user (templates page "Δοκιμή").
     * @return string where it was sent
     */
    public static function sendTest(array $tpl, array $tenant, array $user): string
    {
        $ctx = TemplateRenderer::sampleContext();
        $ctx['tenant']['name'] = $tenant['name'];
        $ctx['event'] = $tpl['event'];
        $ctx['event_label'] = self::EVENTS[$tpl['event']] ?? $tpl['event'];
        $subject = '[ΔΟΚΙΜΗ] ' . trim(TemplateRenderer::render((string) ($tpl['subject'] ?? ''), $ctx));
        $body = trim(TemplateRenderer::render((string) $tpl['body'], $ctx));
        $tid = (int) $tenant['id'];
        switch ($tpl['channel']) {
            case 'email':
                $conn = Connectors::default($tid, 'email') ?? throw new \RuntimeException('Δεν υπάρχει email connector.');
                EmailSender::send($conn['type'], Connectors::config($conn), [$user['email']], $subject, self::emailHtml($tenant, $subject, $body, $ctx['run']['link'], $tpl['event']), $body);
                return $user['email'];
            case 'telegram':
                $conn = Connectors::default($tid, 'telegram') ?? throw new \RuntimeException('Δεν υπάρχει Telegram connector.');
                $cfg = Connectors::config($conn);
                $chat = (string) (($cfg['default_chat_id'] ?? '') ?: Settings::get($tid, 'notify.telegram_chat', ''));
                TelegramSender::send($cfg, $chat, TelegramSender::format("[ΔΟΚΙΜΗ]\n" . $body));
                return 'Telegram chat ' . $chat;
            case 'push':
                $res = PushSender::sendToUsers([(int) $user['id']], ['title' => $subject, 'body' => $body, 'url' => abs_url('templates'), 'tag' => 'tpl-test']);
                if ($res['sent'] === 0) {
                    throw new \RuntimeException($res['errors'] ? implode('; ', $res['errors']) : 'Δεν έχεις ενεργή συσκευή για push.');
                }
                return $res['sent'] . ' συσκευές σου';
            case 'chat':
                $sent = [];
                foreach (App::db()->all("SELECT * FROM connectors WHERE tenant_id = ? AND type IN ('slack','teams','discord','webhook') AND is_default = 1", [$tid]) as $conn) {
                    ChatSender::send($conn['type'], Connectors::config($conn), $subject, $body, $ctx, $tpl['event']);
                    $sent[] = $conn['name'];
                }
                if (!$sent) {
                    throw new \RuntimeException('Δεν υπάρχει προεπιλεγμένος Slack, Teams, Discord ή Webhook connector.');
                }
                return implode(', ', $sent);
        }
        throw new \RuntimeException('Άγνωστο κανάλι.');
    }

    /**
     * Device ids that should get a push for this job/event.
     * 'all': every member's enabled devices, filtered by each user's event preferences.
     * 'selected': only the users/devices chosen on the job (explicit choice, preferences ignored),
     * still limited to current members of the tenant and to enabled devices.
     * @return int[]
     */
    public static function pushSubscriptions(array $job, int $tenantId, string $event): array
    {
        $db = App::db();
        if (($job['push_target'] ?? 'all') !== 'selected') {
            $users = self::pushRecipients($tenantId, $event);
            if (!$users) {
                return [];
            }
            $in = implode(',', array_fill(0, count($users), '?'));
            return array_map('intval', $db->column("SELECT id FROM push_subscriptions WHERE enabled = 1 AND user_id IN ($in)", $users));
        }
        return array_map('intval', $db->column(
            "SELECT DISTINCT s.id FROM job_push_targets t
             JOIN push_subscriptions s ON (t.subscription_id = s.id OR (t.subscription_id IS NULL AND s.user_id = t.user_id))
             JOIN memberships m ON m.user_id = s.user_id AND m.tenant_id = ?
             JOIN users u ON u.id = s.user_id AND u.status = 'active'
             WHERE t.job_id = ? AND s.enabled = 1",
            [$tenantId, $job['id']],
        ));
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

    /** Recipient of the send in progress (so a failure can still say who it was for). */
    private static ?string $lastTarget = null;

    /** "Κινητό Γιώργου (Γιώργος), Laptop (Μαρία)" for a list of device ids. */
    private static function deviceNames(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return '—';
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = App::db()->all(
            "SELECT s.label, s.user_agent, u.name FROM push_subscriptions s JOIN users u ON u.id = s.user_id WHERE s.id IN ($in) ORDER BY u.name, s.id",
            $ids,
        );
        $names = array_map(fn ($r) => ($r['label'] ?: ($r['user_agent'] ?: 'Συσκευή')) . ' (' . $r['name'] . ')', $rows);
        return mb_substr(implode(', ', $names) ?: count($ids) . ' συσκευές', 0, 255);
    }

    private static function log(array $tenant, array $job, ?array $run, string $event, string $channel, string $target, string $status, ?string $error, ?string $subject = null, ?string $body = null, ?int $runId = null): void
    {
        App::db()->insert('notification_log', [
            'tenant_id' => $tenant['id'],
            'job_id' => $job['id'],
            'run_id' => $run['id'] ?? $runId,
            'event' => $event,
            'channel' => $channel,
            'target' => mb_substr($target, 0, 255),
            'subject' => $subject !== null && $subject !== '' ? mb_substr($subject, 0, 255) : null,
            'body' => $body !== null ? mb_substr($body, 0, 5000) : null,
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
