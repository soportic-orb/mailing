<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\DB;

/** Month calendar of campaigns: scheduled, planned (drafts with a date), sent. Drag to reschedule. */
final class CalendarController extends Controller
{
    public function index(): never
    {
        $ym = str_input('mes', 7);
        $first = preg_match('/^\d{4}-\d{2}$/', $ym) ? new \DateTimeImmutable($ym . '-01', app_tz()) : new \DateTimeImmutable('first day of this month', app_tz());
        $first = $first->setTime(0, 0);
        $start = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
        $end = $start->modify('+42 days');
        $utc = new \DateTimeZone('UTC');
        $rows = DB::all("SELECT id, name, subject, status, scheduled_at, started_at, finished_at, recipients FROM campaigns
            WHERE project_id = ? AND status <> 'cancelled' AND COALESCE(finished_at, started_at, scheduled_at) >= ? AND COALESCE(finished_at, started_at, scheduled_at) < ?",
            [$this->pid(), $start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')]);
        $byDay = [];
        foreach ($rows as $r) {
            $when = local_dt($r['finished_at'] ?: ($r['started_at'] ?: $r['scheduled_at']));
            if ($when) {
                $r['_time'] = $when->format('H:i');
                $byDay[$when->format('Y-m-d')][] = $r;
            }
        }
        foreach ($byDay as &$list) {
            usort($list, static fn ($a, $b) => strcmp($a['_time'], $b['_time']));
        }
        unset($list);
        $this->view('calendar/index', [
            'title' => 'Calendari',
            'first' => $first,
            'start' => $start,
            'byDay' => $byDay,
            'unscheduled' => DB::all("SELECT id, name, status FROM campaigns WHERE project_id = ? AND status = 'draft' AND scheduled_at IS NULL ORDER BY updated_at DESC LIMIT 20", [$this->pid()]),
        ]);
    }
}
