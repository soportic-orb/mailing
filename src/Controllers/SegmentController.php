<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\DB;
use Mailing\Services\Activity;
use Mailing\Services\Contacts;
use Mailing\Services\Segments;

final class SegmentController extends Controller
{
    public function index(): never
    {
        $pid = $this->pid();
        $segments = DB::all('SELECT * FROM segments WHERE project_id = ? ORDER BY name', [$pid]);
        $lists = array_column(DB::all('SELECT id, name FROM lists WHERE project_id = ?', [$pid]), 'name', 'id');
        $campaigns = array_column(DB::all('SELECT id, name FROM campaigns WHERE project_id = ?', [$pid]), 'name', 'id');
        foreach ($segments as &$s) {
            $rules = json_decode((string) $s['rules'], true) ?: [];
            $s['_desc'] = Segments::describe($rules, $lists, $campaigns);
            $s['_count'] = Segments::count($rules, $pid);
        }
        unset($s);
        $this->view('segments/index', ['title' => 'Segments', 'segments' => $segments]);
    }

    public function edit(?string $id = null): never
    {
        $pid = $this->pid();
        $s = $id ? $this->own('segments', (int) $id) : null;
        $this->view('segments/form', [
            'title' => $s ? $s['name'] : 'Segment nou',
            's' => $s,
            'rules' => Segments::clean($s ? json_decode((string) $s['rules'], true) : ['match' => 'all', 'conditions' => []]),
            'fields' => Contacts::fields($pid),
            'lists' => DB::all('SELECT id, name FROM lists WHERE project_id = ? ORDER BY name', [$pid]),
            'campaigns' => DB::all("SELECT id, name FROM campaigns WHERE project_id = ? AND status IN ('sent', 'sending', 'paused') ORDER BY finished_at DESC LIMIT 100", [$pid]),
        ]);
    }

    public function save(?string $id = null): never
    {
        $pid = $this->pid();
        $s = $id ? $this->own('segments', (int) $id) : null;
        $name = str_input('name', 120);
        $rules = Segments::clean(json_decode((string) input('rules', ''), true));
        if ($name === '') {
            $this->fail('Posa un nom al segment.');
        }
        if (!$rules['conditions']) {
            $this->fail('Afegeix almenys una condició.');
        }
        $now = now_utc();
        if ($s) {
            DB::update('segments', ['name' => $name, 'rules' => json_encode($rules, JSON_UNESCAPED_UNICODE), 'updated_at' => $now], 'id = ?', [$s['id']]);
        } else {
            DB::insert('segments', ['project_id' => $pid, 'name' => $name, 'rules' => json_encode($rules, JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now]);
            Activity::log('segment.created', $name, $pid);
        }
        $this->done('Segment desat.', '/segments');
    }

    public function count(): never
    {
        $rules = Segments::clean(json_decode((string) input('rules', ''), true));
        json_response(['ok' => true, 'count' => Segments::count($rules, $this->pid())]);
    }

    public function destroy(string $id): never
    {
        $s = $this->own('segments', (int) $id);
        DB::exec('DELETE FROM segments WHERE id = ?', [$s['id']]);
        $this->done('Segment eliminat.', '/segments');
    }
}
