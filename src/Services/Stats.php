<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\DB;

final class Stats
{
    /** @return array<string, int> */
    public static function campaign(int $campaignId): array
    {
        $r = DB::one("SELECT COUNT(*) AS total,
                SUM(status = 'queued') AS queued, SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed, SUM(status = 'skipped') AS skipped,
                SUM(delivered_at IS NOT NULL) AS delivered, SUM(opened_at IS NOT NULL) AS opened, SUM(open_count) AS opens,
                SUM(clicked_at IS NOT NULL) AS clicked, SUM(click_count) AS clicks, SUM(bounced_at IS NOT NULL) AS bounced,
                SUM(bounce_hard) AS hard, SUM(spam_at IS NOT NULL) AS spam, SUM(blocked_at IS NOT NULL) AS blocked, SUM(unsub_at IS NOT NULL) AS unsub
            FROM messages WHERE campaign_id = ?", [$campaignId]) ?? [];
        return array_map('intval', $r);
    }

    /** @return array<int, array{url:string, clicks:int, people:int}> */
    public static function links(int $campaignId): array
    {
        return DB::all("SELECT url, COUNT(*) AS clicks, COUNT(DISTINCT message_id) AS people FROM events
            WHERE campaign_id = ? AND type = 'click' AND url IS NOT NULL AND url <> '' GROUP BY url ORDER BY clicks DESC LIMIT 100", [$campaignId]);
    }

    /**
     * Opens and clicks per hour for the first 72 hours (or per day after that).
     *
     * @return array{labels: array<int,string>, opens: array<int,int>, clicks: array<int,int>}
     */
    public static function timeline(array $campaign): array
    {
        $start = $campaign['started_at'] ? strtotime($campaign['started_at'] . ' UTC') : null;
        if (!$start) {
            return ['labels' => [], 'opens' => [], 'clicks' => []];
        }
        $rows = DB::all("SELECT type, FLOOR((UNIX_TIMESTAMP(created_at) - ?) / 3600) AS h, COUNT(*) AS n FROM events
            WHERE campaign_id = ? AND type IN ('open', 'click') AND created_at >= ? GROUP BY type, h", [$start, $campaign['id'], gmdate('Y-m-d H:i:s', $start)]);
        $hours = 72;
        $opens = array_fill(0, $hours, 0);
        $clicks = array_fill(0, $hours, 0);
        foreach ($rows as $r) {
            $h = (int) $r['h'];
            if ($h >= 0 && $h < $hours) {
                $r['type'] === 'open' ? $opens[$h] += (int) $r['n'] : $clicks[$h] += (int) $r['n'];
            }
        }
        $labels = [];
        for ($i = 0; $i < $hours; $i++) {
            $labels[] = local_dt(gmdate('Y-m-d H:i:s', $start + $i * 3600))->format('d/m H') . 'h';
        }
        return ['labels' => $labels, 'opens' => $opens, 'clicks' => $clicks];
    }

    /** Overview of a project. */
    public static function project(int $projectId): array
    {
        $contacts = DB::one("SELECT COUNT(*) AS total, SUM(status = 'subscribed') AS subscribed, SUM(status = 'unsubscribed') AS unsubscribed,
                SUM(status IN ('bounced', 'complained', 'blocked')) AS suppressed, SUM(created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)) AS new30
            FROM contacts WHERE project_id = ?", [$projectId]) ?? [];
        $sent = DB::one("SELECT COUNT(*) AS sent, SUM(m.opened_at IS NOT NULL) AS opened, SUM(m.clicked_at IS NOT NULL) AS clicked, SUM(m.bounced_at IS NOT NULL) AS bounced, SUM(m.unsub_at IS NOT NULL) AS unsub
            FROM messages m JOIN campaigns c ON c.id = m.campaign_id WHERE c.project_id = ? AND m.status = 'sent' AND m.sent_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)", [$projectId]) ?? [];
        return ['contacts' => array_map('intval', $contacts), 'sent90' => array_map('intval', $sent)];
    }

    /** Contacts added per month (last 12 months). @return array<string,int> */
    public static function growth(int $projectId): array
    {
        $rows = DB::all("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS n FROM contacts WHERE project_id = ? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 12 MONTH) GROUP BY ym", [$projectId]);
        $map = array_column($rows, 'n', 'ym');
        $out = [];
        for ($i = 11; $i >= 0; $i--) {
            $ym = gmdate('Y-m', strtotime(gmdate('Y-m-01') . " -$i month"));
            $out[$ym] = (int) ($map[$ym] ?? 0);
        }
        return $out;
    }
}
