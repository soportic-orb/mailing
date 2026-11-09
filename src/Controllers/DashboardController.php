<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\Auth;
use Mailing\Core\Context;
use Mailing\Core\DB;
use Mailing\Mailjet\Client;
use Mailing\Services\Cron;
use Mailing\Services\Stats;

final class DashboardController extends Controller
{
    public function index(): never
    {
        $project = Context::project();
        if (!$project) {
            $this->view('dashboard/empty', ['title' => 'Inici']);
        }
        $pid = (int) $project['id'];
        $warnings = [];
        if (!Client::forProject($project)->configured()) {
            $warnings[] = ['Mailjet encara no està configurat: no es pot enviar res.', Auth::isAdmin() ? '/configuracio' : null];
        }
        if (!DB::value('SELECT COUNT(*) FROM senders WHERE project_id = ?', [$pid])) {
            $warnings[] = ['Aquest projecte no té cap adreça d\'emissió. Afegeix-ne una a Remitents.', '/remitents'];
        }
        $age = Cron::age();
        if ($age === null || $age > 600) {
            $warnings[] = ['La tasca programada (cron) no s\'està executant: els enviaments només avancen quan algú obre la plataforma. Afegeix-la a CloudPanel.', Auth::isAdmin() ? '/configuracio#cron' : null];
        }
        $this->view('dashboard/index', [
            'title' => 'Inici',
            'project' => $project,
            'warnings' => $warnings,
            'stats' => Stats::project($pid),
            'upcoming' => DB::all("SELECT * FROM campaigns WHERE project_id = ? AND status IN ('scheduled', 'sending', 'paused') ORDER BY COALESCE(scheduled_at, created_at) LIMIT 8", [$pid]),
            'drafts' => DB::all("SELECT * FROM campaigns WHERE project_id = ? AND status = 'draft' ORDER BY updated_at DESC LIMIT 5", [$pid]),
            'recent' => DB::all("SELECT c.*, (SELECT COUNT(*) FROM messages m WHERE m.campaign_id = c.id AND m.status = 'sent') AS n_sent,
                    (SELECT COUNT(*) FROM messages m WHERE m.campaign_id = c.id AND m.opened_at IS NOT NULL) AS n_open,
                    (SELECT COUNT(*) FROM messages m WHERE m.campaign_id = c.id AND m.clicked_at IS NOT NULL) AS n_click
                FROM campaigns c WHERE c.project_id = ? AND c.status = 'sent' ORDER BY c.finished_at DESC LIMIT 6", [$pid]),
            'growth' => Stats::growth($pid),
        ]);
    }

    public function switchProject(): never
    {
        if (!Context::switchTo(int_input('project_id'))) {
            $this->fail('No tens accés a aquest projecte.');
        }
        $ref = (string) parse_url($_SERVER['HTTP_REFERER'] ?? '/', PHP_URL_PATH);
        // Pages of a specific record belong to the previous project.
        redirect(preg_match('#^/[a-z]+(/\d+)#', $ref, $m) ? '/' . explode('/', trim($ref, '/'))[0] : ($ref ?: '/'));
    }
}
