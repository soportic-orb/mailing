<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\DB;
use Mailing\Core\Settings;
use Mailing\Services\Activity;
use Mailing\Services\Backup;
use Mailing\Services\Migrations;
use Mailing\Services\Updater;

final class UpdateController extends Controller
{
    public function index(): never
    {
        $this->view('update/index', [
            'title' => 'Actualització',
            'remote' => Settings::get('update_remote_version'),
            'lastCheck' => Settings::get('update_last_check'),
            'preflight' => Updater::preflight(),
            'history' => Updater::history(),
            'backups' => Backup::list(),
            'migrations' => Migrations::status(),
            'result' => $_SESSION['update_result'] ?? null,
        ]);
    }

    public function check(): never
    {
        $r = Updater::check();
        if ($r['error']) {
            $this->fail($r['error'], '/actualitzacio');
        }
        $this->done($r['available'] ? 'Hi ha una versió nova: ' . $r['remote'] . ' (tens la ' . $r['local'] . ').' : 'Ja tens la versió més nova (' . $r['local'] . ').', '/actualitzacio');
    }

    public function apply(): never
    {
        $r = (new Updater())->apply();
        $_SESSION['update_result'] = $r;
        Activity::log('update.' . ($r['ok'] ? 'ok' : 'error'), $r['from'] . ' → ' . $r['to']);
        $r['ok'] ? $this->done('Actualitzat a la versió ' . $r['to'] . '.', '/actualitzacio') : $this->fail('L\'actualització no s\'ha pogut completar. Mira el registre.', '/actualitzacio');
    }

    public function migrate(): never
    {
        $r = Migrations::run();
        $r['ok'] ? $this->done($r['ran'] ? 'Migracions aplicades: ' . implode(', ', $r['ran']) : 'No hi havia migracions pendents.', '/actualitzacio')
            : $this->fail('Ha fallat ' . ($r['failed'] ?? '') . ': ' . ($r['error'] ?? ''), '/actualitzacio');
    }

    public function backup(): never
    {
        try {
            $name = Backup::create('manual');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), '/actualitzacio');
        }
        $this->done('Còpia creada: ' . $name, '/actualitzacio');
    }

    public function download(string $name): never
    {
        $file = BASE_PATH . Backup::DIR . '/' . basename($name);
        if (!preg_match('/^db-[\w-]+\.sql\.gz$/', basename($name)) || !is_file($file)) {
            abort(404);
        }
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($name) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    public function activity(): never
    {
        $page = $this->paginate('SELECT COUNT(*) FROM activity', 'SELECT a.*, u.name AS who, p.name AS project FROM activity a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN projects p ON p.id = a.project_id ORDER BY a.id DESC', [], 100);
        $this->view('update/activity', ['title' => 'Activitat', 'page' => $page]);
    }
}
