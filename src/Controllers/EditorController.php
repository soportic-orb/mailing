<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\Auth;
use Mailing\Core\DB;
use Mailing\Editor\Brand;
use Mailing\Editor\Document;
use Mailing\Editor\Images;
use Mailing\Editor\MailDoc;
use Mailing\Editor\MergeTags;
use Mailing\Services\Activity;
use Mailing\Services\Ai;
use Mailing\Services\Campaigns;
use Mailing\Services\Contacts;

/**
 * The visual email editor (ported from the Tauler's Mailing editor), shared
 * by campaigns and templates.
 */
final class EditorController extends Controller
{
    /** @return array{0: string, 1: array} [type, row] */
    private function owner(string $kind, int $id): array
    {
        if ($kind === 'campanyes') {
            return ['campaign', $this->own('campaigns', $id)];
        }
        $t = DB::one('SELECT * FROM templates WHERE id = ? AND (project_id = ? OR project_id IS NULL)', [$id, $this->pid()]);
        if (!$t) {
            abort(404, 'No s\'ha trobat la plantilla.');
        }
        if ($t['project_id'] === null && !Auth::isAdmin()) {
            abort(403, 'Les plantilles comunes a tots els projectes només les editen les persones administradores.');
        }
        return ['template', $t];
    }

    public function edit(string $kind, string $id): never
    {
        [$type, $row] = $this->owner($kind, (int) $id);
        if ($type === 'campaign' && !Campaigns::editable($row)) {
            flash('err', 'Aquesta campanya ja s\'ha enviat (o s\'està enviant): el contingut no es pot canviar. Duplica-la per fer-ne una de nova.');
            redirect('/campanyes/' . $row['id']);
        }
        $project = $this->project();
        Brand::apply($project);
        $doc = Document::decode($row['doc'], $project['default_language']);
        $defaults = [];
        foreach (array_keys(MailDoc::TYPES) as $t) {
            $defaults[$t] = MailDoc::defaults($t);
        }
        $logo = Brand::logoUrl($project);
        $images = ['brand:logo' => $logo];
        foreach ($doc['langs'] as $l) {
            $images += Images::urlsIn($l['doc'], $logo);
        }
        $ai = new Ai($project);
        $base = '/' . $kind . '/' . $row['id'];
        $this->view('editor/editor', [
            'title' => ($type === 'campaign' ? $row['name'] : 'Plantilla: ' . $row['name']),
            'wide' => true,
            'type' => $type,
            'row' => $row,
            'project' => $project,
            'ai' => $ai,
            'cfg' => [
                'id' => (int) $row['id'],
                'isTemplate' => $type === 'template',
                'name' => $type === 'template' ? $row['name'] : '',
                'doc' => $doc,
                'version' => (int) $row['version'],
                'types' => MailDoc::TYPES,
                'defaults' => $defaults,
                'fonts' => MailDoc::FONTS,
                'images' => $images,
                'sources' => new \stdClass(),
                'langs' => Document::LANGS,
                'tail' => Document::TAIL,
                'brand' => ['name' => (string) $project['name'], 'why' => array_combine(array_keys(Document::LANGS), array_map(static fn ($l) => Document::why($project, $l), array_keys(Document::LANGS))), 'address' => trim(implode(' · ', array_filter([(string) ($project['company'] ?? ''), (string) ($project['postal_address'] ?? '')])))],
                'merge' => MergeTags::available(Contacts::fields((int) $project['id'])) + ['unsubscribe_url' => 'Enllaç de baixa', 'view_url' => 'Enllaç «veure al navegador»'],
                'me' => (string) (Auth::user()['email'] ?? ''),
                'csrf' => \Mailing\Core\Csrf::token(),
                'ai' => ['show' => $ai->enabled() && ($ai->textProviders() || $ai->imageReady() || Auth::isAdmin()), 'text' => (bool) $ai->textProviders(), 'image' => $ai->imageReady(), 'provider' => $ai->defaultTextProvider(), 'maxRefs' => Ai::MAX_REFS],
                'urls' => [
                    'update' => $base . '/doc',
                    'render' => '/editor/previsualitza',
                    'upload' => '/editor/imatges',
                    'versions' => $base . '/versions',
                    'test' => $type === 'campaign' ? $base . '/prova' : null,
                    'asTemplate' => $base . '/com-a-plantilla',
                    'image' => '/img/' . str_repeat('X', 40),
                    'aiText' => '/editor/ia/text',
                    'aiImage' => '/editor/ia/imatge',
                    'translate' => '/editor/tradueix',
                    'back' => $type === 'campaign' ? $base : '/plantilles',
                ],
            ],
        ]);
    }

    public function save(string $kind, string $id): never
    {
        [$type, $row] = $this->owner($kind, (int) $id);
        if ($type === 'campaign' && !Campaigns::editable($row)) {
            json_response(['ok' => false, 'message' => 'Aquesta campanya ja s\'està enviant o s\'ha enviat: no es pot canviar.'], 422);
        }
        if ($type === 'template') {
            $name = str_input('name', 190);
            if ($name !== '' && $name !== $row['name']) {
                DB::update('templates', ['name' => $name], 'id = ?', [$row['id']]);
                $row['name'] = $name;
            }
        }
        $r = Campaigns::saveDoc($type, $row, input('doc'), int_input('base_version'), (bool) input('force', false), $this->project());
        if (!empty($r['conflict'])) {
            json_response(['ok' => false, 'message' => $r['message'], 'version' => $r['version']], 409);
        }
        json_response(['ok' => true, 'result' => $r['result'], 'version' => $r['version'], 'saved' => 'Desat ara per ' . (Auth::user()['name'] ?? '')]);
    }

    public function versions(string $kind, string $id): never
    {
        [$type, $row] = $this->owner($kind, (int) $id);
        $rows = DB::all('SELECT v.version, v.name, v.created_at, u.name AS who FROM doc_versions v LEFT JOIN users u ON u.id = v.saved_by WHERE v.owner_type = ? AND v.owner_id = ? ORDER BY v.version DESC LIMIT 60', [$type, $row['id']]);
        $base = '/' . $kind . '/' . $row['id'] . '/versions/';
        json_response(['ok' => true, 'versions' => array_map(static fn ($v) => [
            'version' => (int) $v['version'], 'when' => fmt_dt($v['created_at']), 'by' => (string) ($v['who'] ?? '—'),
            'status' => '', 'current' => (int) $v['version'] === (int) $row['version'],
            'preview' => $base . $v['version'], 'restore' => $base . $v['version'] . '/restaura',
        ], $rows)]);
    }

    public function version(string $kind, string $id, string $v): never
    {
        [$type, $row] = $this->owner($kind, (int) $id);
        $ver = DB::one('SELECT doc FROM doc_versions WHERE owner_type = ? AND owner_id = ? AND version = ?', [$type, $row['id'], (int) $v]);
        if (!$ver) {
            abort(404);
        }
        $html = Document::render(Document::decode($ver['doc']), $this->project());
        CampaignController::htmlResponse((string) reset($html));
    }

    public function restore(string $kind, string $id, string $v): never
    {
        [$type, $row] = $this->owner($kind, (int) $id);
        $ver = DB::one('SELECT doc FROM doc_versions WHERE owner_type = ? AND owner_id = ? AND version = ?', [$type, $row['id'], (int) $v]);
        if (!$ver || ($type === 'campaign' && !Campaigns::editable($row))) {
            $this->fail('No es pot recuperar aquesta versió.');
        }
        Campaigns::saveDoc($type, $row, json_decode($ver['doc'], true), 0, true, $this->project());
        $this->done('Versió ' . (int) $v . ' recuperada (desada com a versió nova).', '/' . $kind . '/' . $row['id'] . '/editor');
    }

    public function asTemplate(string $kind, string $id): never
    {
        [, $row] = $this->owner($kind, (int) $id);
        $name = str_input('name', 190) ?: $row['name'] . ' (plantilla)';
        $now = now_utc();
        $tid = DB::insert('templates', ['project_id' => $this->pid(), 'name' => $name, 'doc' => $row['doc'], 'version' => 1,
            'created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now]);
        Campaigns::snapshot('template', $tid, 1, $name, Document::decode($row['doc']));
        Activity::log('template.created', $name, $this->pid());
        $this->done('Plantilla «' . $name . '» creada. La trobaràs a Plantilles.', '/plantilles');
    }

    /** Server-side render of the current editor state (preview). */
    public function render(): never
    {
        $doc = Document::clean(input('doc'));
        $lang = str_input('lang', 5);
        $html = Document::render($doc, $this->project());
        CampaignController::htmlResponse((string) ($html[$lang] ?? reset($html)));
    }

    public function upload(): never
    {
        $f = $_FILES['image'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            json_response(['ok' => false, 'message' => 'Tria una imatge (màxim ' . intdiv(Images::MAX_KB, 1024) . ' MB).'], 422);
        }
        try {
            $img = Images::storeBytes((string) file_get_contents($f['tmp_name']), (string) $f['name'], $this->pid(), Auth::id());
        } catch (\RuntimeException $e) {
            json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        json_response(['ok' => true, 'src' => 'img:' . $img['token'], 'url' => url('img/' . $img['token']), 'width' => $img['width'], 'height' => $img['height']]);
    }

    public function aiText(): never
    {
        @set_time_limit(180);
        try {
            $data = [
                'format' => str_input('format', 20), 'tone' => str_input('tone', 20), 'length' => str_input('length', 20),
                'title' => str_input('title', 255), 'context' => str_input('context', 20000), 'current' => str_input('current', 20000), 'lang' => str_input('lang', 5),
            ];
            $r = (new Ai($this->project()))->text(str_input('provider', 10), str_input('prompt', 4000), $data);
        } catch (\RuntimeException $e) {
            json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        json_response(['ok' => true] + $r);
    }

    public function aiImage(): never
    {
        @set_time_limit(300);
        $ai = new Ai($this->project());
        try {
            $refs = [];
            $files = $_FILES['refs'] ?? null;
            if ($files && is_array($files['name'])) {
                foreach ($files['name'] as $i => $n) {
                    if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) {
                        throw new \RuntimeException('Una de les imatges de referència no s\'ha pogut pujar.');
                    }
                    $refs[] = $ai->prepareRef((string) file_get_contents($files['tmp_name'][$i]), (string) $n);
                }
            }
            foreach ((array) input('ref_srcs', []) as $src) {
                $b = is_string($src) ? Images::bytes($src) : null;
                if ($b) {
                    $refs[] = $ai->prepareRef($b['data'], $b['name']);
                }
            }
            if (count($refs) > Ai::MAX_REFS) {
                throw new \RuntimeException('Com a màxim ' . Ai::MAX_REFS . ' imatges de referència.');
            }
            $img = $ai->image(str_input('prompt', 3000), str_input('shape', 12) ?: 'landscape', str_input('style', 14) ?: 'photo',
                input('no_text', '1') === '1', Auth::id(), $refs, input('keep_details') === '1');
        } catch (\RuntimeException $e) {
            json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        json_response(['ok' => true, 'src' => 'img:' . $img['token'], 'url' => url('img/' . $img['token'])]);
    }

    public function translate(): never
    {
        @set_time_limit(300);
        try {
            $r = (new Ai($this->project()))->translate(
                ['subject' => str_input('subject', 255), 'preheader' => str_input('preheader', 255), 'doc' => input('doc', [])],
                str_input('from', 5), str_input('to', 5), str_input('provider', 10) ?: null
            );
        } catch (\RuntimeException $e) {
            json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        json_response(['ok' => true] + $r);
    }
}
