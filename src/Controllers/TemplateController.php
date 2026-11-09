<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\Auth;
use Mailing\Core\DB;
use Mailing\Editor\Brand;
use Mailing\Editor\Document;
use Mailing\Editor\TemplateFormat;
use Mailing\Services\Activity;
use Mailing\Services\Campaigns;
use Mailing\Services\Contacts;

final class TemplateController extends Controller
{
    public function index(): never
    {
        $this->view('templates/index', [
            'title' => 'Plantilles',
            'templates' => DB::all('SELECT * FROM templates WHERE project_id = ? OR project_id IS NULL ORDER BY project_id IS NULL, name', [$this->pid()]),
        ]);
    }

    public function store(): never
    {
        $project = $this->project();
        $name = str_input('name', 190) ?: 'Plantilla nova';
        $global = input('global') === '1' && Auth::isAdmin();
        Brand::apply($project);
        $lang = str_input('language', 5);
        $doc = Document::blank(isset(Document::LANGS[$lang]) ? $lang : ($project['default_language'] ?: 'ca'));
        $now = now_utc();
        $id = DB::insert('templates', ['project_id' => $global ? null : $project['id'], 'name' => $name, 'doc' => Document::encode($doc), 'version' => 1,
            'created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now]);
        Campaigns::snapshot('template', $id, 1, $name, $doc);
        Activity::log('template.created', $name, (int) $project['id']);
        redirect('/plantilles/' . $id . '/editor');
    }

    private function find(int $id): array
    {
        $t = DB::one('SELECT * FROM templates WHERE id = ? AND (project_id = ? OR project_id IS NULL)', [$id, $this->pid()]);
        if (!$t) {
            abort(404, 'No s\'ha trobat la plantilla.');
        }
        return $t;
    }

    public function duplicate(string $id): never
    {
        $t = $this->find((int) $id);
        $now = now_utc();
        $new = DB::insert('templates', ['project_id' => $this->pid(), 'name' => mb_substr($t['name'] . ' (còpia)', 0, 190), 'doc' => $t['doc'], 'version' => 1,
            'created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now]);
        Campaigns::snapshot('template', $new, 1, $t['name'], Document::decode($t['doc']));
        $this->done('Plantilla duplicada.', '/plantilles');
    }

    public function destroy(string $id): never
    {
        $t = $this->find((int) $id);
        if ($t['project_id'] === null && !Auth::isAdmin()) {
            $this->fail('Les plantilles comunes només les eliminen les persones administradores.');
        }
        DB::exec('DELETE FROM templates WHERE id = ?', [$t['id']]);
        DB::exec("DELETE FROM doc_versions WHERE owner_type = 'template' AND owner_id = ?", [$t['id']]);
        Activity::log('template.deleted', $t['name'], $this->pid());
        $this->done('Plantilla eliminada. Les campanyes que se\'n van fer no canvien.', '/plantilles');
    }

    /** Import a template (JSON file or pasted text, e.g. designed by Claude). */
    public function import(): never
    {
        $project = $this->project();
        $raw = '';
        $f = $_FILES['file'] ?? null;
        if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            if ($f['size'] > 2 * 1024 * 1024) {
                $this->fail('El fitxer és massa gran (màxim 2 MB).', '/plantilles');
            }
            $raw = (string) file_get_contents($f['tmp_name']);
        } else {
            $raw = (string) input('json', '');
        }
        if (trim($raw) === '') {
            $this->fail('Tria un fitxer o enganxa el JSON de la plantilla.', '/plantilles');
        }
        try {
            $r = TemplateFormat::import($raw, $project['default_language'] ?: 'ca');
        } catch (\RuntimeException $e) {
            $this->fail('No s\'ha pogut importar: ' . $e->getMessage(), '/plantilles');
        }
        $name = str_input('name', 190) ?: ($r['name'] ?: 'Plantilla importada');
        $global = input('global') === '1' && Auth::isAdmin();
        $now = now_utc();
        $id = DB::insert('templates', ['project_id' => $global ? null : $project['id'], 'name' => $name, 'doc' => Document::encode($r['doc']), 'version' => 1,
            'created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now]);
        Campaigns::snapshot('template', $id, 1, $name, $r['doc']);
        Activity::log('template.imported', $name, (int) $project['id']);
        $msg = 'Plantilla «' . $name . '» importada.' . ($r['warnings'] ? ' Avisos: ' . implode(' ', $r['warnings']) : ' Revisa-la a l\'editor.');
        flash($r['warnings'] ? 'err' : 'ok', $msg);
        redirect('/plantilles/' . $id . '/editor');
    }

    public function export(string $id): never
    {
        $t = $this->find((int) $id);
        $file = (Contacts::fieldKey($t['name']) ?: 'plantilla') . '.mailing.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace('_', '-', $file) . '"');
        header('Cache-Control: no-store');
        echo TemplateFormat::export((string) $t['name'], Document::decode($t['doc']));
        exit;
    }

    /** The format guide to give Claude (Markdown). */
    public function format(): never
    {
        header('Content-Type: text/markdown; charset=utf-8');
        if (input('baixa') === '1') {
            header('Content-Disposition: attachment; filename="format-plantilles-mailing.md"');
        }
        echo TemplateFormat::spec();
        exit;
    }

    public function preview(string $id): never
    {
        $t = $this->find((int) $id);
        $html = Document::render(Document::decode($t['doc']), $this->project());
        $lang = str_input('lang', 5);
        CampaignController::htmlResponse((string) ($html[$lang] ?? reset($html)));
    }
}
