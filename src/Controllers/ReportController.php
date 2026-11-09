<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\DB;
use Mailing\Services\Audience;
use Mailing\Services\Spreadsheet;
use Mailing\Services\Stats;

final class ReportController extends Controller
{
    public function show(string $id): never
    {
        $c = $this->own('campaigns', (int) $id);
        $filter = str_input('filtre', 12);
        $where = 'm.campaign_id = ?';
        $map = [
            'opened' => 'm.opened_at IS NOT NULL', 'notopened' => "m.opened_at IS NULL AND m.status = 'sent'", 'clicked' => 'm.clicked_at IS NOT NULL',
            'bounced' => 'm.bounced_at IS NOT NULL', 'unsub' => 'm.unsub_at IS NOT NULL', 'spam' => 'm.spam_at IS NOT NULL',
            'failed' => "m.status IN ('failed', 'skipped', 'cancelled')", 'queued' => "m.status = 'queued'",
        ];
        if (isset($map[$filter])) {
            $where .= ' AND ' . $map[$filter];
        }
        $page = $this->paginate("SELECT COUNT(*) FROM messages m WHERE $where", "SELECT m.*, ct.first_name, ct.last_name FROM messages m LEFT JOIN contacts ct ON ct.id = m.contact_id WHERE $where ORDER BY m.clicked_at IS NULL, m.opened_at IS NULL, m.id", [$c['id']], 50);
        $sender = $c['sender_id'] ? DB::one('SELECT * FROM senders WHERE id = ?', [$c['sender_id']]) : null;
        $this->view('reports/show', [
            'title' => 'Informe: ' . $c['name'],
            'c' => $c,
            's' => Stats::campaign((int) $c['id']),
            'links' => Stats::links((int) $c['id']),
            'timeline' => Stats::timeline($c),
            'page' => $page,
            'filter' => $filter,
            'sender' => $sender,
            'audience' => Audience::describe(Audience::clean($c['audience']), $this->pid()),
            'langs' => DB::all('SELECT language, COUNT(*) AS n FROM messages WHERE campaign_id = ? GROUP BY language', [$c['id']]),
        ]);
    }

    public function export(string $id): never
    {
        $c = $this->own('campaigns', (int) $id);
        $rows = DB::all('SELECT m.*, ct.first_name, ct.last_name FROM messages m LEFT JOIN contacts ct ON ct.id = m.contact_id WHERE m.campaign_id = ? ORDER BY m.id', [$c['id']]);
        Spreadsheet::download('destinataris-campanya-' . $c['id'] . '.csv',
            ['email', 'nom', 'cognoms', 'idioma', 'estat', 'enviat', 'lliurat', 'obert', 'obertures', 'clic', 'clics', 'rebot', 'rebot dur', 'brossa', 'baixa', 'error'],
            (static function () use ($rows) {
                foreach ($rows as $m) {
                    yield [$m['email'], $m['first_name'], $m['last_name'], $m['language'], $m['status'], $m['sent_at'], $m['delivered_at'], $m['opened_at'], $m['open_count'],
                        $m['clicked_at'], $m['click_count'], $m['bounced_at'], $m['bounce_hard'] ? 'sí' : '', $m['spam_at'], $m['unsub_at'], $m['error']];
                }
            })());
    }
}
