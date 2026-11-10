<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\DB;
use Mailing\Core\Settings;

/**
 * Notices: stored for the bell (/avisos) and pushed to the devices of each
 * recipient by the cron (flush), never while a campaign is being sent.
 * Every user chooses which kinds they want (settings "notify_prefs_<id>").
 */
final class Notify
{
    /** kind => [label, help, on by default] */
    public const KINDS = [
        'campaign_sent' => ['Campanya enviada', 'Amb el resum de lliurament', true],
        'campaign_failed' => ['Campanya fallida o aturada', 'Mailjet la rebutja, falta el remitent o s\'atura per errors', true],
        'campaign_started' => ['Comença l\'enviament programat', 'Només de les campanyes que has programat tu', false],
        'bounces' => ['Rebots o queixes alts', 'Rebots per sobre del 3 % o queixes de brossa per sobre del 0,1 %', true],
        'automation' => ['Automatitzacions retingudes', 'Algú s\'espera en un pas incomplet o un correu del flux falla', true],
        'sync' => ['Sincronització fallida', 'Una connexió MySQL o JSON dona error', false],
        'system' => ['Sistema', 'El cron no s\'executa o hi ha una actualització disponible (administradors)', true],
    ];

    public static function enabled(): bool
    {
        return Settings::get('push_enabled') === '1';
    }

    /** @return array<string, bool> */
    public static function prefs(int $userId): array
    {
        $saved = json_decode(Settings::get('notify_prefs_' . $userId), true) ?: [];
        $out = [];
        foreach (self::KINDS as $k => $d) {
            $out[$k] = array_key_exists($k, $saved) ? (bool) $saved[$k] : $d[2];
        }
        return $out;
    }

    public static function savePrefs(int $userId, array $on): void
    {
        $out = [];
        foreach (array_keys(self::KINDS) as $k) {
            $out[$k] = in_array($k, $on, true);
        }
        Settings::set('notify_prefs_' . $userId, json_encode($out));
    }

    /** Active users who edit a project (its editors and every administrator). */
    public static function editors(int $projectId): array
    {
        return array_map('intval', DB::column("SELECT u.id FROM users u WHERE u.active = 1 AND (u.role = 'admin'
            OR (u.role = 'editor' AND EXISTS (SELECT 1 FROM user_projects up WHERE up.user_id = u.id AND up.project_id = ?)))", [$projectId]));
    }

    public static function admins(): array
    {
        return array_map('intval', DB::column("SELECT id FROM users WHERE active = 1 AND role = 'admin'"));
    }

    /**
     * Creates the notice for each recipient who wants this kind.
     *
     * @param int[] $userIds
     */
    public static function send(array $userIds, string $kind, string $title, string $body, string $url, ?int $projectId = null, string $tag = ''): int
    {
        $n = 0;
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            if ($uid <= 0 || !(self::prefs($uid)[$kind] ?? false)) {
                continue;
            }
            DB::insert('notifications', ['user_id' => $uid, 'project_id' => $projectId, 'kind' => $kind, 'title' => mb_substr($title, 0, 190),
                'body' => mb_substr($body, 0, 500), 'url' => mb_substr($url, 0, 500), 'tag' => mb_substr($tag, 0, 60), 'created_at' => now_utc()]);
            $n++;
        }
        return $n;
    }

    /** Was a notice with this tag created in the last $hours? (avoid repeats) */
    public static function recent(string $tag, int $hours = 24): bool
    {
        return (bool) DB::value('SELECT COUNT(*) FROM notifications WHERE tag = ? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)', [$tag, $hours]);
    }

    private static function projectName(int $projectId): string
    {
        return (string) (DB::value('SELECT name FROM projects WHERE id = ?', [$projectId]) ?: '');
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------

    public static function campaignSent(array $c): void
    {
        $s = Stats::campaign((int) $c['id']);
        self::send(self::editors((int) $c['project_id']), 'campaign_sent', 'Campanya enviada · ' . self::projectName((int) $c['project_id']),
            '«' . $c['name'] . '»: ' . fmt_n($s['sent']) . ' de ' . fmt_n($s['total']) . ' enviats' . ($s['failed'] ? ', ' . fmt_n($s['failed']) . ' rebutjats' : '') . '. Toca per veure l\'informe.',
            '/campanyes/' . $c['id'] . '/informe', (int) $c['project_id'], 'campaign-' . $c['id']);
    }

    public static function campaignFailed(array $c, string $reason): void
    {
        self::send(self::editors((int) $c['project_id']), 'campaign_failed', 'Enviament aturat · ' . self::projectName((int) $c['project_id']),
            '«' . $c['name'] . '»: ' . $reason, '/campanyes/' . $c['id'], (int) $c['project_id'], 'campaign-' . $c['id']);
    }

    public static function campaignStarted(array $c, int $recipients): void
    {
        if ($c['updated_by']) {
            self::send([(int) $c['updated_by']], 'campaign_started', 'Comença l\'enviament · ' . self::projectName((int) $c['project_id']),
                '«' . $c['name'] . '» s\'està enviant a ' . fmt_n($recipients) . ' destinataris.', '/campanyes/' . $c['id'], (int) $c['project_id'], 'campaign-' . $c['id']);
        }
    }

    public static function automationHeld(array $a, string $stepLabel, string $problem): void
    {
        $tag = 'automation-' . $a['id'];
        if (self::recent($tag, 12)) {
            return;
        }
        self::send(self::editors((int) $a['project_id']), 'automation', 'Automatització amb un pas a mitges · ' . self::projectName((int) $a['project_id']),
            '«' . $a['name'] . '»: hi ha contactes esperant a ' . $stepLabel . '. ' . $problem . '.', '/automatitzacions/' . $a['id'], (int) $a['project_id'], $tag);
    }

    public static function automationEmailFailed(array $c, string $reason): void
    {
        $tag = 'automation-' . $c['automation_id'];
        if (self::recent($tag, 12)) {
            return;
        }
        self::send(self::editors((int) $c['project_id']), 'automation', 'Un correu d\'automatització no surt · ' . self::projectName((int) $c['project_id']),
            '«' . $c['name'] . '»: ' . $reason, '/automatitzacions/' . (int) $c['automation_id'], (int) $c['project_id'], $tag);
    }

    public static function syncFailed(array $connector, string $message): void
    {
        $tag = 'sync-' . $connector['id'];
        if (self::recent($tag, 12)) {
            return;
        }
        self::send(self::editors((int) $connector['project_id']), 'sync', 'Sincronització fallida · ' . self::projectName((int) $connector['project_id']),
            '«' . $connector['name'] . '»: ' . mb_substr($message, 0, 200), '/sincronitzacio', (int) $connector['project_id'], $tag);
    }

    // ------------------------------------------------------------------
    // Cron
    // ------------------------------------------------------------------

    /** Checks that run every minute: bounce rates, stale cron, available update. */
    public static function watch(string $via): void
    {
        // Bounces and complaints of campaigns started in the last 24 hours (at least 20 sent).
        foreach (DB::all("SELECT c.* FROM campaigns c WHERE c.status IN ('sending', 'sent', 'paused') AND c.started_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)") as $c) {
            $s = Stats::campaign((int) $c['id']);
            if ($s['sent'] < 20 || self::recent('bounces-' . $c['id'], 48)) {
                continue;
            }
            $bounce = $s['bounced'] / $s['sent'];
            $spam = $s['spam'] / $s['sent'];
            if ($bounce > 0.03 || $spam > 0.001) {
                $what = $bounce > 0.03 ? 'rebots ' . pct($s['bounced'], $s['sent']) : 'queixes de brossa ' . pct($s['spam'], $s['sent']);
                self::send(self::editors((int) $c['project_id']), 'bounces', 'Rebots o queixes alts · ' . self::projectName((int) $c['project_id']),
                    '«' . $c['name'] . '»: ' . $what . '. Revisa la llista abans del proper enviament.', '/campanyes/' . $c['id'] . '/informe?filtre=' . ($bounce > 0.03 ? 'bounced' : 'spam'), (int) $c['project_id'], 'bounces-' . $c['id']);
            }
        }
        // Without a real cron the platform only moves when someone opens it.
        $age = Cron::age();
        if ($via === 'web' && ($age === null || $age > 1800) && !self::recent('system-cron', 24)) {
            self::send(self::admins(), 'system', 'La tasca programada no s\'executa', 'Els enviaments només avancen quan algú obre la plataforma. Afegeix el cron a CloudPanel.', '/configuracio#cron', null, 'system-cron');
        }
        // Available update, checked once a day.
        $last = Settings::get('update_notice_check');
        if (!$last || strtotime($last . ' UTC') < time() - 86400) {
            Settings::set('update_notice_check', now_utc());
            try {
                $u = Updater::check();
                if ($u['available'] && Settings::get('update_notified') !== $u['remote']) {
                    Settings::set('update_notified', (string) $u['remote']);
                    self::send(self::admins(), 'system', 'Actualització disponible: ' . $u['remote'], 'Ara teniu la ' . $u['local'] . '. Es pot instal·lar des d\'Actualització.', '/actualitzacio', null, 'system-update');
                }
            } catch (\Throwable) {
            }
        }
    }

    /** Pushes the notices not pushed yet. */
    public static function flush(int $limit = 100): array
    {
        $out = ['pushed' => 0, 'gone' => 0];
        $rows = DB::all('SELECT * FROM notifications WHERE pushed = 0 ORDER BY id LIMIT ' . max(1, $limit));
        if (!$rows) {
            return $out;
        }
        DB::exec('UPDATE notifications SET pushed = 1 WHERE id IN (' . DB::in(array_column($rows, 'id')) . ')', array_column($rows, 'id'));
        if (!self::enabled()) {
            return $out;
        }
        foreach ($rows as $n) {
            if (strtotime($n['created_at'] . ' UTC') < time() - 86400) {
                continue; // too old to be news
            }
            $unread = (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$n['user_id']]);
            $payload = ['title' => $n['title'], 'body' => $n['body'], 'url' => '/avisos/' . $n['id'], 'tag' => $n['tag'] ?: 'n' . $n['id'], 'badge' => $unread];
            foreach (DB::all('SELECT * FROM push_subscriptions WHERE user_id = ?', [$n['user_id']]) as $sub) {
                try {
                    $status = WebPush::send($sub, $payload, $n['tag'], 86400, $n['kind'] === 'campaign_failed' ? 'high' : 'normal');
                } catch (\Throwable $e) {
                    logger('Push ' . $sub['id'] . ': ' . $e->getMessage(), [], 'push');
                    $status = 0;
                }
                if ($status >= 200 && $status < 300) {
                    DB::update('push_subscriptions', ['last_ok_at' => now_utc(), 'failures' => 0], 'id = ?', [$sub['id']]);
                    $out['pushed']++;
                } elseif (in_array($status, [404, 410], true) || $sub['failures'] >= 20) {
                    DB::exec('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]);
                    $out['gone']++;
                } else {
                    DB::exec('UPDATE push_subscriptions SET failures = failures + 1 WHERE id = ?', [$sub['id']]);
                }
            }
        }
        return $out;
    }

    /** Endpoint of a browser push service (https only; plain http only for tests). */
    public static function validEndpoint(string $url): bool
    {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) {
            return false;
        }
        if (($p['scheme'] ?? '') === 'https') {
            return !filter_var($p['host'], FILTER_VALIDATE_IP) && $p['host'] !== 'localhost';
        }
        return env('PUSH_ALLOW_HTTP') === true && ($p['scheme'] ?? '') === 'http';
    }
}
