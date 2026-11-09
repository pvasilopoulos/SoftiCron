<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Response;
use App\Services\Reports;

final class ReportController extends Controller
{
    public const PERIODS = ['7d' => '7 ημέρες', '30d' => '30 ημέρες', '90d' => '90 ημέρες', 'month' => 'Τρέχων μήνας', 'prev_month' => 'Προηγούμενος μήνας', 'custom' => 'Προσαρμοσμένη'];

    /** @return array{0:int,1:int,2:array} [fromTs, toTs, filters] */
    private function range(): array
    {
        $tz = new \DateTimeZone($this->tenant()['timezone']);
        $f = ['period' => (string) $this->req->query('period', '30d'), 'from' => (string) $this->req->query('from', ''), 'to' => (string) $this->req->query('to', ''), 'group' => (string) $this->req->query('group', '')];
        $now = new \DateTimeImmutable('now', $tz);
        switch ($f['period']) {
            case '7d': $from = $now->modify('-7 days'); $to = $now; break;
            case '90d': $from = $now->modify('-90 days'); $to = $now; break;
            case 'month': $from = $now->modify('first day of this month')->setTime(0, 0); $to = $now; break;
            case 'prev_month': $from = $now->modify('first day of last month')->setTime(0, 0); $to = $now->modify('first day of this month')->setTime(0, 0)->modify('-1 second'); break;
            case 'custom':
                $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from']) ? new \DateTimeImmutable($f['from'] . ' 00:00:00', $tz) : $now->modify('-30 days');
                $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to']) ? new \DateTimeImmutable($f['to'] . ' 23:59:59', $tz) : $now;
                break;
            default: $f['period'] = '30d'; $from = $now->modify('-30 days'); $to = $now;
        }
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        return [$from->getTimestamp(), min($to->getTimestamp(), time()), $f];
    }

    public function index(): Response
    {
        $this->authorize('reports.view');
        [$fromTs, $toTs, $f] = $this->range();
        $report = Reports::compute($this->tenantId(), $fromTs, $toTs, $f['group']);
        $groups = $this->db->column('SELECT DISTINCT group_name FROM jobs WHERE tenant_id = ? AND group_name IS NOT NULL ORDER BY group_name', [$this->tenantId()]);
        return $this->view('reports/index', [
            'title' => 'Αναφορές & SLA', 'nav' => 'reports', 'r' => $report, 'f' => $f, 'groups' => $groups,
            'fromTs' => $fromTs, 'toTs' => $toTs, 'canEmail' => Auth::can('reports.email'), 'canExport' => Auth::can('reports.export'),
        ]);
    }

    public function export(): Response
    {
        $this->authorize('reports.export');
        [$fromTs, $toTs, $f] = $this->range();
        $r = Reports::compute($this->tenantId(), $fromTs, $toTs, $f['group']);
        $num = fn ($v, $d = 2) => $v === null ? '' : number_format((float) $v, $d, ',', '');
        $rows = array_map(fn ($j) => [
            $j['name'], $j['group'], $j['type'], $j['runs'], $j['failed'], $num($j['success_rate']), $num($j['uptime'], 3), $j['downtime_min'],
            $num($j['sla']), $j['sla_met'] === null ? '' : ($j['sla_met'] ? 'Εντός' : 'Εκτός'), $j['avg_ms'], $j['p95_ms'], $j['max_ms'],
        ], $r['jobs']);
        return Response::csv('sla-report-' . date('Ymd', $fromTs) . '-' . date('Ymd', $toTs) . '.csv',
            ['Job', 'Ομάδα', 'Τύπος', 'Εκτελέσεις', 'Αποτυχίες', 'Επιτυχία %', 'Uptime %', 'Downtime (λεπτά)', 'Στόχος SLA %', 'SLA', 'Μέση διάρκεια (ms)', 'p95 (ms)', 'Μέγιστη (ms)'], $rows);
    }

    /** Sends the report for the selected period now, to the configured recipients. */
    public function email(): Response
    {
        $this->authorize('reports.email');
        [$fromTs, $toTs] = $this->range();
        try {
            $to = Reports::sendReport($this->tenant(), $fromTs, $toTs);
            $this->flash('success', 'Η αναφορά στάλθηκε σε: ' . $to);
        } catch (\Throwable $e) {
            $this->flash('error', 'Η αναφορά δεν στάλθηκε: ' . $e->getMessage());
        }
        return $this->back('reports');
    }
}
