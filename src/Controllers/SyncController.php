<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\DB;
use Mailing\Services\Activity;
use Mailing\Services\Sync;

final class SyncController extends Controller
{
    public function index(): never
    {
        $this->view('sync/index', [
            'title' => 'Sincronització',
            'connectors' => DB::all('SELECT s.*, l.name AS list_name FROM connectors s LEFT JOIN lists l ON l.id = s.list_id WHERE s.project_id = ? ORDER BY s.name', [$this->pid()]),
            'token' => $_SESSION['new_token'] ?? null,
        ]);
    }

    public function edit(?string $id = null): never
    {
        $c = $id ? $this->own('connectors', (int) $id) : null;
        unset($_SESSION['new_token']);
        $this->view('sync/form', [
            'title' => $c ? $c['name'] : 'Connexió nova',
            'c' => $c,
            'cfg' => $c ? Sync::config($c) : [],
            'type' => $c['type'] ?? (isset(Sync::TYPES[input('tipus')]) ? (string) input('tipus') : 'api'),
            'lists' => DB::all('SELECT id, name FROM lists WHERE project_id = ? ORDER BY name', [$this->pid()]),
            'history' => $c ? DB::all('SELECT * FROM imports WHERE connector_id = ? ORDER BY id DESC LIMIT 10', [$c['id']]) : [],
        ]);
    }

    public function save(?string $id = null): never
    {
        $pid = $this->pid();
        $c = $id ? $this->own('connectors', (int) $id) : null;
        $type = $c['type'] ?? (string) input('type');
        if (!isset(Sync::TYPES[$type])) {
            $this->fail('Tria el tipus de connexió.');
        }
        $name = str_input('name', 120);
        if ($name === '') {
            $this->fail('Posa un nom a la connexió (p. ex. «Clients d\'Emergencing»).');
        }
        $listId = int_input('list_id') ?: null;
        $newList = str_input('new_list', 120);
        if ($newList !== '') {
            $listId = DB::insert('lists', ['project_id' => $pid, 'name' => $newList, 'created_at' => now_utc()]);
        } elseif ($listId && !DB::value('SELECT id FROM lists WHERE id = ? AND project_id = ?', [$listId, $pid])) {
            $listId = null;
        }
        $old = $c ? Sync::config($c) : [];
        $cfg = match ($type) {
            'mysql' => [
                'host' => str_input('host', 190) ?: 'localhost', 'port' => int_input('port', 3306) ?: 3306, 'database' => str_input('database', 120),
                'username' => str_input('username', 120), 'password' => (string) input('password', '') !== '' ? (string) input('password') : ($old['password'] ?? ''),
                'query' => trim((string) input('query', '')),
            ],
            'json' => [
                'url' => str_input('url', 1000), 'path' => str_input('path', 120), 'header_name' => str_input('header_name', 60),
                'header_value' => (string) input('header_value', '') !== '' ? (string) input('header_value') : ($old['header_value'] ?? ''),
            ],
            default => [],
        };
        $row = ['name' => $name, 'list_id' => $listId, 'mode' => input('mode') === 'mirror' ? 'mirror' : 'add',
            'interval_minutes' => max(15, min(10080, int_input('interval', 360))), 'active' => input('active', '1') === '1' ? 1 : 0];
        if ($c) {
            DB::update('connectors', $row, 'id = ?', [$c['id']]);
            $cid = (int) $c['id'];
        } else {
            $cid = DB::insert('connectors', $row + ['project_id' => $pid, 'type' => $type, 'created_at' => now_utc()]);
            Activity::log('sync.created', $name, $pid);
        }
        Sync::saveConfig($cid, $cfg);
        if (!$c && $type === 'api') {
            $_SESSION['new_token'] = ['id' => $cid, 'token' => Sync::newToken($cid)];
            redirect('/sincronitzacio');
        }
        if (input('test') === '1' && $type !== 'api') {
            $r = Sync::run(DB::one('SELECT * FROM connectors WHERE id = ?', [$cid]));
            $r['ok'] ? $this->done('Desat i executat: ' . $r['message'], '/sincronitzacio/' . $cid) : $this->fail('Desat, però la sincronització ha fallat: ' . $r['message'], '/sincronitzacio/' . $cid);
        }
        $this->done('Connexió desada.', '/sincronitzacio/' . $cid);
    }

    public function run(string $id): never
    {
        $c = $this->own('connectors', (int) $id);
        $r = Sync::run($c);
        $r['ok'] ? $this->done($r['message'], '/sincronitzacio/' . $c['id']) : $this->fail($r['message'], '/sincronitzacio/' . $c['id']);
    }

    public function token(string $id): never
    {
        $c = $this->own('connectors', (int) $id);
        if ($c['type'] !== 'api') {
            $this->fail('Només les connexions per API tenen clau.');
        }
        $_SESSION['new_token'] = ['id' => (int) $c['id'], 'token' => Sync::newToken((int) $c['id'])];
        flash('ok', 'Clau nova generada. L\'anterior ja no funciona.');
        redirect('/sincronitzacio');
    }

    public function destroy(string $id): never
    {
        $c = $this->own('connectors', (int) $id);
        DB::exec('DELETE FROM connectors WHERE id = ?', [$c['id']]);
        $this->done('Connexió eliminada. Els contactes ja importats es conserven.', '/sincronitzacio');
    }
}
