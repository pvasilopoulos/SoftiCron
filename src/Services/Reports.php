<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Services\Channels\EmailSender;

/** Availability / SLA statistics per job for a period. */
final class Reports
{
    /**
     * @return array{jobs: array, totals: array, daily: array, from: string, to: string}
     */
    public static function compute(int $tenantId, int $fromTs, int $toTs, string $group = ''): array
    {
        $db = App::db();
        $from = gmdate('Y-m-d H:i:s', $fromTs);
        $to = gmdate('Y-m-d H:i:s', $toTs);
        $sql = 'SELECT id, name, group_name, type, sla_target, enabled, created_at FROM jobs WHERE tenant_id = ?';
        $p = [$tenantId];
        if ($group === '__none') {
            $sql .= ' AND group_name IS NULL';
        } elseif ($group !== '') {
            $sql .= ' AND group_name = ?';
            $p[] = $group;
        }
        $jobs = $db->all($sql . ' ORDER BY group_name IS NULL, group_name, name', $p);
        $out = [];
        $tot = ['runs' => 0, 'ok' => 0, 'failed' => 0, 'down' => 0, 'span' => 0, 'sla_total' => 0, 'sla_met' => 0];
        foreach ($jobs as $j) {
            $runs = $db->all(
                "SELECT status, duration_ms, COALESCE(started_at, scheduled_at) AS t FROM job_runs
                 WHERE job_id = ? AND status IN ('success','failed') AND COALESCE(started_at, scheduled_at) BETWEEN ? AND ? ORDER BY t, id",
                [$j['id'], $from, $to],
            );
            // State just before the period, to know whether we start "down".
            $before = $db->value(
                "SELECT status FROM job_runs WHERE job_id = ? AND status IN ('success','failed') AND COALESCE(started_at, scheduled_at) < ? ORDER BY id DESC LIMIT 1",
                [$j['id'], $from],
            );
            $start = max($fromTs, strtotime($j['created_at'] . ' UTC'));
            $span = max(1, $toTs - $start);
            $down = 0;
            $downSince = $before === 'failed' ? $start : null;
            $durations = [];
            $ok = $failed = 0;
            foreach ($runs as $r) {
                $t = strtotime($r['t'] . ' UTC');
                if ($r['status'] === 'failed') {
                    $failed++;
                    $downSince ??= $t;
                } else {
                    $ok++;
                    if ($downSince !== null) {
                        $down += max(0, $t - $downSince);
                        $downSince = null;
                    }
                }
                if ($r['duration_ms'] !== null) {
                    $durations[] = (int) $r['duration_ms'];
                }
            }
            if ($downSince !== null) {
                $down += max(0, min($toTs, time()) - $downSince);
            }
            sort($durations);
            $n = count($durations);
            $total = $ok + $failed;
            $uptime = $total || $before ? max(0, 100 - 100 * $down / $span) : null;
            $sla = $j['sla_target'] !== null ? (float) $j['sla_target'] : null;
            $row = [
                'id' => (int) $j['id'], 'name' => $j['name'], 'group' => $j['group_name'], 'type' => $j['type'], 'enabled' => (bool) $j['enabled'],
                'runs' => $total, 'ok' => $ok, 'failed' => $failed,
                'success_rate' => $total ? 100 * $ok / $total : null,
                'uptime' => $uptime,
                'downtime_min' => (int) round($down / 60),
                'avg_ms' => $n ? (int) round(array_sum($durations) / $n) : null,
                'p95_ms' => $n ? $durations[(int) min($n - 1, ceil(0.95 * $n) - 1)] : null,
                'max_ms' => $n ? $durations[$n - 1] : null,
                'sla' => $sla,
                'sla_met' => $sla !== null && $uptime !== null ? $uptime >= $sla : null,
            ];
            $out[] = $row;
            $tot['runs'] += $total;
            $tot['ok'] += $ok;
            $tot['failed'] += $failed;
            if ($uptime !== null) {
                $tot['down'] += $down;
                $tot['span'] += $span;
            }
            if ($row['sla_met'] !== null) {
                $tot['sla_total']++;
                $tot['sla_met'] += $row['sla_met'] ? 1 : 0;
            }
        }
        $totals = [
            'runs' => $tot['runs'], 'failed' => $tot['failed'],
            'success_rate' => $tot['runs'] ? 100 * $tot['ok'] / $tot['runs'] : null,
            'uptime' => $tot['span'] ? max(0, 100 - 100 * $tot['down'] / $tot['span']) : null,
            'downtime_min' => (int) round($tot['down'] / 60),
            'sla_total' => $tot['sla_total'], 'sla_met' => $tot['sla_met'],
        ];
        // Daily series for the chart (tenant timezone days).
        $tz = new \DateTimeZone((string) $db->value('SELECT timezone FROM tenants WHERE id = ?', [$tenantId]) ?: 'UTC');
        $daily = [];
        $ids = array_column($out, 'id');
        if ($ids) {
            $in = implode(',', $ids);
            foreach ($db->all("SELECT COALESCE(started_at, scheduled_at) AS t, status FROM job_runs WHERE job_id IN ($in) AND status IN ('success','failed') AND COALESCE(started_at, scheduled_at) BETWEEN ? AND ?", [$from, $to]) as $r) {
                $d = (new \DateTimeImmutable($r['t'], new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
                $daily[$d] ??= ['ok' => 0, 'failed' => 0];
                $daily[$d][$r['status'] === 'success' ? 'ok' : 'failed']++;
            }
        }
        $days = [];
        for ($d = (new \DateTimeImmutable('@' . $fromTs))->setTimezone($tz)->setTime(0, 0); $d->getTimestamp() <= $toTs; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $days[] = ['date' => $k, 'label' => $d->format('d/m'), 'ok' => $daily[$k]['ok'] ?? 0, 'failed' => $daily[$k]['failed'] ?? 0];
        }
        return ['jobs' => $out, 'totals' => $totals, 'daily' => $days, 'from' => $from, 'to' => $to];
    }

    public static function pct(?float $v, int $dec = 2): string
    {
        return $v === null ? '—' : number_format($v, $dec, ',', '.') . '%';
    }

    /** Weekly e-mail report (Mondays 08:00 tenant time), driven by the scheduler. */
    public static function sendWeeklyDue(): void
    {
        $db = App::db();
        foreach ($db->all("SELECT * FROM tenants WHERE status = 'active'") as $tenant) {
            $tid = (int) $tenant['id'];
            if (Settings::get($tid, 'reports.weekly_enabled') !== '1') {
                continue;
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone($tenant['timezone'] ?: 'Europe/Athens'));
            $week = $now->format('o-W');
            if ($now->format('N') !== '1' || (int) $now->format('G') < 8 || Settings::get($tid, 'reports.weekly_last') === $week) {
                continue;
            }
            Settings::set($tid, 'reports.weekly_last', $week);
            try {
                self::sendReport($tenant, $now->modify('-7 days')->setTime(0, 0)->getTimestamp(), $now->setTime(0, 0)->getTimestamp() - 1);
            } catch (\Throwable $e) {
                App::log('weekly report tenant ' . $tid . ': ' . $e->getMessage());
            }
        }
    }

    /** @throws \RuntimeException */
    public static function sendReport(array $tenant, int $fromTs, int $toTs, ?array $to = null): string
    {
        $tid = (int) $tenant['id'];
        $to ??= array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) (Settings::get($tid, 'reports.weekly_emails') ?: Settings::get($tid, 'notify.emails', ''))) ?: []));
        if (!$to) {
            throw new \RuntimeException('Δεν έχουν οριστεί παραλήπτες για την αναφορά.');
        }
        $conn = Connectors::default($tid, 'email') ?? throw new \RuntimeException('Δεν υπάρχει email connector.');
        $r = self::compute($tid, $fromTs, $toTs);
        $tz = new \DateTimeZone($tenant['timezone'] ?: 'Europe/Athens');
        $period = (new \DateTimeImmutable('@' . $fromTs))->setTimezone($tz)->format('d/m') . ' – ' . (new \DateTimeImmutable('@' . $toTs))->setTimezone($tz)->format('d/m/Y');
        $title = 'Εβδομαδιαία αναφορά · ' . $period;
        $rows = '';
        foreach ($r['jobs'] as $j) {
            $color = $j['sla_met'] === false ? '#B91C1C' : ($j['failed'] ? '#92400E' : '#166534');
            $rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #EEF1F5">' . e($j['name']) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #EEF1F5;text-align:right;color:' . $color . ';font-weight:600">' . e(self::pct($j['uptime'])) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #EEF1F5;text-align:right">' . (int) $j['runs'] . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #EEF1F5;text-align:right">' . (int) $j['failed'] . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #EEF1F5;text-align:right">' . e(fmt_ms($j['p95_ms'])) . '</td></tr>';
        }
        $t = $r['totals'];
        $text = "Διαθεσιμότητα: " . self::pct($t['uptime']) . "\nΕκτελέσεις: {$t['runs']} · Αποτυχίες: {$t['failed']}\nSLA: {$t['sla_met']}/{$t['sla_total']} jobs εντός στόχου";
        $table = '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:14px"><tr style="text-align:left;color:#64748B"><th style="padding:6px 8px">Job</th><th style="padding:6px 8px;text-align:right">Uptime</th><th style="padding:6px 8px;text-align:right">Εκτελέσεις</th><th style="padding:6px 8px;text-align:right">Αποτυχίες</th><th style="padding:6px 8px;text-align:right">p95</th></tr>' . $rows . '</table>';
        $html = str_replace('</div><p style="margin:24px 0 0">', $table . '</div><p style="margin:24px 0 0">', Notifier::emailHtml($tenant, $title, $text, abs_url('reports'), null));
        EmailSender::send($conn['type'], Connectors::config($conn), $to, $title, $html, $text);
        return implode(', ', $to);
    }
}
