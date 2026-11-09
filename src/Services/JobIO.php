<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/**
 * Converts jobs to/from a portable array (REST API, JSON import/export, version snapshots).
 * Secret values (secret headers, auth passwords/tokens) are never exported.
 */
final class JobIO
{
    public const FORMAT = 'softicron.jobs/v1';

    /** Fields copied 1:1 between the DB row and the portable array. */
    private const PLAIN = [
        'name', 'description', 'type', 'method', 'url', 'body_type', 'body', 'timezone', 'timeout_sec',
        'max_retries', 'retry_strategy', 'retry_delay_sec', 'overlap_policy', 'success_codes', 'success_contains',
        'slow_threshold_ms', 'active_from', 'active_until', 'notify_emails', 'notify_phones', 'notify_telegram_chat',
        'grace_minutes', 'notify_after_failures', 'escalate_after_minutes', 'sla_target', 'status_name', 'push_target',
    ];

    private const INTS = ['timeout_sec', 'max_retries', 'retry_delay_sec', 'slow_threshold_ms', 'grace_minutes', 'notify_after_failures', 'escalate_after_minutes'];

    public static function toArray(array $job, bool $withState = true): array
    {
        $db = App::db();
        $out = [];
        if ($withState) {
            $out['id'] = (int) $job['id'];
        }
        foreach (self::PLAIN as $k) {
            $v = $job[$k] ?? null;
            $out[$k] = $v !== null && in_array($k, self::INTS, true) ? (int) $v : $v;
        }
        $out['sla_target'] = $job['sla_target'] !== null ? (float) $job['sla_target'] : null;
        $out['group'] = $job['group_name'];
        $out['enabled'] = (bool) $job['enabled'];
        $out['cron'] = $job['cron_expr'];
        $out['show_on_status'] = (bool) $job['show_on_status'];
        $out['headers'] = array_map(fn ($h) => [
            'name' => (string) $h['name'],
            'value' => !empty($h['secret']) ? null : (string) $h['value'],
            'secret' => !empty($h['secret']),
        ], HttpExecutor::headers($job));
        $auth = ['type' => $job['auth_type'] ?? 'none'];
        $cfg = JobAuth::config($job);
        foreach (JobAuth::FIELDS[$auth['type']] ?? [] as $key => [, , $secret]) {
            $auth[$key] = $secret ? null : ($cfg[$key] ?? '');
        }
        $out['auth'] = $auth;
        $out['escalate_channels'] = $job['escalate_channels'] ? explode(',', (string) $job['escalate_channels']) : [];
        $notify = [];
        if (!empty($job['id'])) {
            foreach ($db->all('SELECT event, channel FROM job_notifications WHERE job_id = ? ORDER BY event, channel', [$job['id']]) as $n) {
                $notify[$n['event']][] = $n['channel'];
            }
        }
        $out['notify'] = (object) $notify;
        if ($withState) {
            $out['ping_url'] = $job['type'] === 'heartbeat' && $job['ping_token'] ? abs_url('ping/' . $job['ping_token']) : null;
            $out['last_status'] = $job['last_status'];
            $out['last_run_at'] = self::iso($job['last_run_at']);
            $out['next_run_at'] = self::iso($job['next_run_at']);
            $out['last_duration_ms'] = $job['last_duration_ms'] !== null ? (int) $job['last_duration_ms'] : null;
            $out['created_at'] = self::iso($job['created_at']);
            $out['updated_at'] = self::iso($job['updated_at']);
        }
        return $out;
    }

    /**
     * Builds the form-shaped input that JobController::collect() understands.
     * With an existing job, missing keys keep their current value (PATCH semantics),
     * and secret header/auth values left null/empty keep the stored secret.
     */
    public static function toInput(array $data, ?array $existing): array
    {
        $base = $existing ? self::toArray($existing, false) : [];
        if ($existing) {
            $base['notify'] = (array) $base['notify'];
        }
        $d = array_replace($base, $data);
        $in = [];
        foreach (self::PLAIN as $k) {
            if (array_key_exists($k, $d) && $d[$k] !== null) {
                $in[$k] = is_bool($d[$k]) ? ($d[$k] ? '1' : '') : (string) $d[$k];
            }
        }
        if (isset($d['body']) && !isset($d['body_type'])) {
            $in['body_type'] = 'text';
        }
        $in['group_name'] = (string) ($d['group'] ?? '');
        $in['cron_expr'] = (string) ($d['cron'] ?? '');
        $in['enabled'] = !empty($d['enabled']) ? '1' : '';
        $in['show_on_status'] = !empty($d['show_on_status']) ? '1' : '';
        $in['h_name'] = $in['h_value'] = $in['h_secret'] = [];
        foreach ((array) ($d['headers'] ?? []) as $h) {
            if (!is_array($h)) {
                continue;
            }
            $in['h_name'][] = (string) ($h['name'] ?? '');
            $in['h_value'][] = (string) ($h['value'] ?? '');
            $in['h_secret'][] = !empty($h['secret']) ? '1' : '0';
        }
        $auth = is_array($d['auth'] ?? null) ? $d['auth'] : ['type' => 'none'];
        $in['auth_type'] = (string) ($auth['type'] ?? 'none');
        $in['auth'] = [];
        foreach (JobAuth::FIELDS[$in['auth_type']] ?? [] as $key => $def) {
            $in['auth'][$key] = (string) ($auth[$key] ?? '');
        }
        $in['escalate_channels'] = array_values(array_map('strval', (array) ($d['escalate_channels'] ?? [])));
        $in['notify'] = [];
        foreach ((array) ($d['notify'] ?? []) as $event => $channels) {
            foreach ((array) $channels as $ch) {
                $in['notify'][(string) $event][(string) $ch] = '1';
            }
        }
        if ($existing) {
            $in['type'] = $existing['type'];
            // Keep push recipients and connectors that the portable format does not carry.
            $in['email_connector_id'] = (string) ($existing['email_connector_id'] ?? '');
            $in['telegram_connector_id'] = (string) ($existing['telegram_connector_id'] ?? '');
            $in['schedule_ui'] = isset($data['cron']) ? '' : (string) ($existing['schedule_ui'] ?? '');
            $in['push_users'] = App::db()->column('SELECT user_id FROM job_push_targets WHERE job_id = ? AND user_id IS NOT NULL', [$existing['id']]);
            $in['push_devices'] = App::db()->column('SELECT subscription_id FROM job_push_targets WHERE job_id = ? AND subscription_id IS NOT NULL', [$existing['id']]);
        } else {
            $in['type'] = ($d['type'] ?? 'http') === 'heartbeat' ? 'heartbeat' : 'http';
            if (($in['push_target'] ?? '') === 'selected') {
                $in['push_target'] = 'all'; // recipients are tenant-specific and not portable
            }
        }
        return $in;
    }

    public static function iso(?string $utc): ?string
    {
        return $utc ? gmdate('Y-m-d\TH:i:s\Z', strtotime($utc . ' UTC')) : null;
    }
}
