<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\DB;
use Mailing\Editor\Document;
use Mailing\Services\Activity;
use Mailing\Services\Contacts;
use Mailing\Services\Forms;

/** Signup pages: administration (per project). The public side is PublicFormController. */
final class FormController extends Controller
{
    public function index(): never
    {
        $pid = $this->pid();
        $this->view('forms/index', [
            'title' => 'Formularis d\'alta',
            'forms' => DB::all('SELECT f.*, (SELECT COUNT(*) FROM form_signups s WHERE s.form_id = f.id AND s.confirmed_at IS NOT NULL) AS signups,
                (SELECT COUNT(*) FROM form_signups s WHERE s.form_id = f.id AND s.confirmed_at IS NULL) AS pending
                FROM forms f WHERE f.project_id = ? ORDER BY f.title', [$pid]),
            'lists' => array_column(DB::all('SELECT id, name FROM lists WHERE project_id = ?', [$pid]), 'name', 'id'),
        ]);
    }

    public function edit(?string $id = null): never
    {
        $pid = $this->pid();
        $f = $id ? $this->own('forms', (int) $id) : null;
        $project = $this->project();
        $this->view('forms/form', [
            'title' => $f ? $f['title'] : 'Formulari nou',
            'f' => $f,
            'project' => $project,
            'lists' => DB::all('SELECT id, name FROM lists WHERE project_id = ? ORDER BY name', [$pid]),
            'custom' => Contacts::fields($pid),
            'chosen' => $f ? Forms::listIds($f) : [],
            'fields' => $f ? array_column(Forms::fields($f), 'required', 'key') : ['first_name' => false],
            'hasSender' => (bool) $project['default_sender_id'],
            'recent' => $f ? DB::all('SELECT s.*, c.id AS cid FROM form_signups s LEFT JOIN contacts c ON c.id = s.contact_id WHERE s.form_id = ? ORDER BY s.id DESC LIMIT 15', [$f['id']]) : [],
        ]);
    }

    public function save(?string $id = null): never
    {
        $pid = $this->pid();
        $f = $id ? $this->own('forms', (int) $id) : null;
        $title = str_input('title', 190);
        if ($title === '') {
            $this->fail('Posa un títol a la pàgina.');
        }
        $lists = DB::column('SELECT id FROM lists WHERE project_id = ? AND id IN (' . DB::in($this->ids('lists')) . ')', array_merge([$pid], $this->ids('lists')));
        $newList = str_input('new_list', 120);
        if ($newList !== '') {
            $lists[] = DB::insert('lists', ['project_id' => $pid, 'name' => $newList, 'created_at' => now_utc()]);
        }
        if (!$lists) {
            $this->fail('Tria almenys una llista on s\'apuntaran les persones.');
        }
        $fields = [];
        foreach ((array) input('fields', []) as $key) {
            $key = (string) $key;
            if (in_array($key, Forms::BASE_FIELDS, true) || preg_match('/^camp:[a-z0-9_]{1,40}$/', $key)) {
                $fields[] = ['key' => $key, 'required' => in_array($key, (array) input('required', []), true)];
            }
        }
        $double = input('double_optin') === '1';
        if ($double && !$this->project()['default_sender_id']) {
            $this->fail('Per enviar el correu de confirmació, el projecte necessita un remitent per defecte (Remitents).');
        }
        $url = static function (string $v): ?string {
            $v = trim($v);
            return $v !== '' && preg_match('#^https?://\S+$#', $v) ? mb_substr($v, 0, 500) : null;
        };
        $slugIn = str_input('slug', 80);
        $slug = Forms::slug($slugIn !== '' ? $slugIn : $title, (int) ($f['id'] ?? 0));
        $row = [
            'title' => $title, 'slug' => $slug, 'intro' => str_input('intro', 5000) ?: null,
            'language' => isset(Document::LANGS[input('language')]) ? (string) input('language') : 'ca',
            'list_ids' => json_encode(array_values(array_map('intval', $lists))), 'choose_lists' => input('choose_lists') === '1' ? 1 : 0,
            'fields' => json_encode($fields), 'button_text' => str_input('button_text', 80),
            'success_text' => str_input('success_text', 1000) ?: null, 'consent_text' => str_input('consent_text', 1000) ?: null,
            'privacy_url' => $url((string) input('privacy_url', '')), 'redirect_url' => $url((string) input('redirect_url', '')),
            'double_optin' => $double ? 1 : 0, 'active' => input('active') === '1' ? 1 : 0, 'updated_at' => now_utc(),
        ];
        if ($f) {
            DB::update('forms', $row, 'id = ?', [$f['id']]);
            $fid = (int) $f['id'];
        } else {
            $fid = DB::insert('forms', $row + ['project_id' => $pid, 'created_at' => now_utc()]);
            Activity::log('form.created', $title, $pid);
        }
        $this->done('Formulari desat. Adreça pública: ' . url('f/' . $slug), '/formularis/' . $fid);
    }

    public function destroy(string $id): never
    {
        $f = $this->own('forms', (int) $id);
        DB::exec('DELETE FROM form_signups WHERE form_id = ?', [$f['id']]);
        DB::exec('DELETE FROM forms WHERE id = ?', [$f['id']]);
        Activity::log('form.deleted', $f['title'], $this->pid());
        $this->done('Formulari eliminat. Els contactes que s\'hi van apuntar es conserven.', '/formularis');
    }
}
