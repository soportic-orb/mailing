<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\Auth;
use Mailing\Core\DB;
use Mailing\Editor\Brand;
use Mailing\Editor\Document;
use Mailing\Services\Activity;
use Mailing\Services\Campaigns;

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
        $doc = Document::blank($project['default_language'] ?: 'ca');
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

    public function preview(string $id): never
    {
        $t = $this->find((int) $id);
        $html = Document::render(Document::decode($t['doc']), $this->project());
        CampaignController::htmlResponse((string) reset($html));
    }
}
