<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\DB;
use Mailing\Services\Activity;

final class ListController extends Controller
{
    public function index(): never
    {
        $this->view('lists/index', [
            'title' => 'Llistes',
            'lists' => DB::all("SELECT l.*,
                    (SELECT COUNT(*) FROM list_contacts lc JOIN contacts c ON c.id = lc.contact_id WHERE lc.list_id = l.id AND lc.status = 'subscribed' AND c.status = 'subscribed') AS active,
                    (SELECT COUNT(*) FROM list_contacts lc WHERE lc.list_id = l.id) AS total,
                    (SELECT COUNT(*) FROM connectors s WHERE s.list_id = l.id) AS syncs
                FROM lists l WHERE l.project_id = ? ORDER BY l.name", [$this->pid()]),
        ]);
    }

    public function store(): never
    {
        $name = str_input('name', 120);
        if ($name === '') {
            $this->fail('Posa un nom a la llista.');
        }
        DB::insert('lists', ['project_id' => $this->pid(), 'name' => $name, 'description' => str_input('description', 500) ?: null, 'created_at' => now_utc()]);
        Activity::log('list.created', $name, $this->pid());
        $this->done('Llista creada.', '/llistes');
    }

    public function update(string $id): never
    {
        $l = $this->own('lists', (int) $id);
        $name = str_input('name', 120);
        DB::update('lists', ['name' => $name !== '' ? $name : $l['name'], 'description' => str_input('description', 500) ?: null], 'id = ?', [$l['id']]);
        $this->done('Llista desada.', '/llistes');
    }

    public function destroy(string $id): never
    {
        $l = $this->own('lists', (int) $id);
        DB::exec('DELETE FROM list_contacts WHERE list_id = ?', [$l['id']]);
        DB::exec('UPDATE connectors SET list_id = NULL WHERE list_id = ?', [$l['id']]);
        DB::exec('DELETE FROM lists WHERE id = ?', [$l['id']]);
        Activity::log('list.deleted', $l['name'], $this->pid());
        $this->done('Llista eliminada. Els contactes es conserven.', '/llistes');
    }
}
