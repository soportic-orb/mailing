<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\DB;
use Mailing\Services\Activity;
use Mailing\Services\Contacts;
use Mailing\Services\Importer;

final class ImportController extends Controller
{
    public function form(): never
    {
        $this->view('contacts/import', [
            'title' => 'Importa contactes',
            'lists' => DB::all('SELECT id, name FROM lists WHERE project_id = ? ORDER BY name', [$this->pid()]),
            'history' => DB::all('SELECT i.*, l.name AS list_name FROM imports i LEFT JOIN lists l ON l.id = i.list_id WHERE i.project_id = ? ORDER BY i.id DESC LIMIT 10', [$this->pid()]),
            'step' => 'upload',
        ]);
    }

    public function upload(): never
    {
        try {
            $f = Importer::store($_FILES['file'] ?? []);
            $path = Importer::path($f['token']);
            $preview = Importer::preview((string) $path);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), '/contactes/importa');
        }
        if (!$preview['header'] || !$preview['total']) {
            $this->fail('El fitxer és buit o no s\'ha pogut llegir.', '/contactes/importa');
        }
        $this->view('contacts/import', [
            'title' => 'Importa contactes',
            'step' => 'map',
            'token' => $f['token'],
            'filename' => $f['name'],
            'preview' => $preview,
            'fields' => Contacts::fields($this->pid()),
            'lists' => DB::all('SELECT id, name FROM lists WHERE project_id = ? ORDER BY name', [$this->pid()]),
            'listId' => int_input('list_id'),
        ]);
    }

    public function run(): never
    {
        $pid = $this->pid();
        $path = Importer::path(str_input('token', 24));
        if (!$path) {
            $this->fail('El fitxer ja no hi és: torna\'l a pujar.', '/contactes/importa');
        }
        if (input('consent') !== '1') {
            $this->fail('Confirma que tens el consentiment dels contactes.', '/contactes/importa');
        }
        $mapping = [];
        foreach ((array) input('map', []) as $i => $target) {
            $target = (string) $target;
            if ($target === 'new') {
                $label = trim((string) (input('newname', [])[$i] ?? ''));
                $target = $label !== '' ? 'new:' . $label : '';
            }
            $mapping[(int) $i] = $target;
        }
        $listId = int_input('list_id') ?: null;
        $newList = str_input('new_list', 120);
        if ($newList !== '') {
            $listId = DB::insert('lists', ['project_id' => $pid, 'name' => $newList, 'created_at' => now_utc()]);
        } elseif ($listId && !DB::value('SELECT id FROM lists WHERE id = ? AND project_id = ?', [$listId, $pid])) {
            $listId = null;
        }
        try {
            $r = Importer::run($pid, $path, str_input('filename', 255), $mapping, $listId, input('overwrite') === '1', input('as_unsub') === '1' ? 'unsubscribed' : 'subscribed');
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), '/contactes/importa');
        }
        @unlink($path);
        Activity::log('contacts.imported', str_input('filename', 255) . ': ' . $r['created'] . ' nous, ' . $r['updated'] . ' actualitzats', $pid);
        $msg = 'Importació feta: ' . $r['created'] . ' nous, ' . $r['updated'] . ' actualitzats, ' . $r['skipped'] . ' descartats.';
        $this->done($msg, $listId ? '/contactes?llista=' . $listId : '/contactes');
    }
}
