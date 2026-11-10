<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Env;
use App\Services\Channels\ChatSender;
use App\Services\Channels\TelegramSender;

/**
 * Incident acknowledgement ("Το είδα"): someone takes ownership of a failing job.
 * An incident is identified by the job and its failing_since timestamp; links carry an HMAC of both,
 * so they work from Slack/Telegram/email without signing in and stop working once the job recovers.
 */
final class Incidents
{
    public static function token(int $jobId, string $failingSince): string
    {
        return substr(hash_hmac('sha256', 'ack|' . $jobId . '|' . $failingSince, (string) Env::get('APP_KEY', 'softicron')), 0, 32);
    }

    /** Public "Το είδα" link for the current incident of a job, or null when it is not failing. */
    public static function ackUrl(int $jobId): ?string
    {
        $since = App::db()->value('SELECT failing_since FROM jobs WHERE id = ?', [$jobId]);
        return $since ? abs_url('ack/' . $jobId . '/' . self::token($jobId, (string) $since)) : null;
    }

    public static function isAcked(array $job): bool
    {
        return !empty($job['acked_at']) && !empty($job['failing_since']);
    }

    /**
     * Marks the current incident as acknowledged. Returns false when there is nothing to acknowledge.
     * @param string $via ui | link | api
     */
    public static function ack(array $job, ?int $userId, string $name, string $note = '', string $via = 'ui'): bool
    {
        if (empty($job['failing_since']) || !empty($job['acked_at'])) {
            return false;
        }
        $db = App::db();
        $name = mb_substr(trim($name) ?: 'Άγνωστος', 0, 120);
        $note = mb_substr(trim($note), 0, 500);
        $db->update('jobs', [
            'acked_at' => $db->now(), 'acked_by' => $userId, 'acked_name' => $name, 'ack_note' => $note !== '' ? $note : null,
        ], 'id = :id', ['id' => $job['id']]);
        $tenant = $db->one('SELECT * FROM tenants WHERE id = ?', [$job['tenant_id']]);
        Audit::log('incident.acked', 'job', (int) $job['id'], $job['name'] . ' · ' . $name . ($note !== '' ? ' — ' . $note : '') . ($via === 'link' ? ' (από σύνδεσμο)' : ''), tenantId: (int) $job['tenant_id']);
        if ($tenant) {
            InAppFeed::record('acked', $job, null, $tenant, $name . ' ανέλαβε' . ($note !== '' ? ': ' . $note : ''));
            self::announce($job, $tenant, "✋ {$name} ανέλαβε το συμβάν «{$job['name']}»" . ($note !== '' ? "\n" . $note : '') . "\nΟι επαναλαμβανόμενες ειδοποιήσεις και η κλιμάκωση σταματούν μέχρι να επανέλθει.");
        }
        return true;
    }

    public static function unack(array $job, string $by): void
    {
        App::db()->update('jobs', ['acked_at' => null, 'acked_by' => null, 'acked_name' => null, 'ack_note' => null], 'id = :id', ['id' => $job['id']]);
        Audit::log('incident.unacked', 'job', (int) $job['id'], $job['name'] . ' · ' . $by, tenantId: (int) $job['tenant_id']);
    }

    /** Posts a short note to the chat channels (Slack, Teams, Discord, Telegram) that got the failure. */
    private static function announce(array $job, array $tenant, string $text): void
    {
        $tid = (int) $tenant['id'];
        $channels = App::db()->column(
            "SELECT DISTINCT channel FROM job_notifications WHERE job_id = ? AND event = 'failed' AND channel IN ('slack','teams','discord','telegram')",
            [$job['id']],
        );
        $ctx = ['job' => ['link' => abs_url('jobs/' . $job['id'] . '/edit')], 'run' => ['link' => abs_url('jobs/' . $job['id'] . '/edit')], 'app' => ['url' => abs_url('')]];
        foreach ($channels as $ch) {
            try {
                if ($ch === 'telegram') {
                    $conn = Connectors::find($tid, $job['telegram_connector_id'] ? (int) $job['telegram_connector_id'] : null) ?? Connectors::default($tid, 'telegram');
                    if ($conn) {
                        $cfg = Connectors::config($conn);
                        $chat = (string) ($job['notify_telegram_chat'] ?: ($cfg['default_chat_id'] ?? '') ?: Settings::get($tid, 'notify.telegram_chat', ''));
                        TelegramSender::send($cfg, $chat, e($text));
                    }
                    continue;
                }
                $conn = Connectors::default($tid, $ch);
                if ($conn) {
                    ChatSender::send($conn['type'], Connectors::config($conn), 'Ανάληψη συμβάντος', $text, $ctx, 'acked');
                }
            } catch (\Throwable $e) {
                App::log('ack announce ' . $ch . ': ' . $e->getMessage());
            }
        }
    }
}
