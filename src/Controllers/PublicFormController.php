<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\DB;
use Mailing\Core\View;
use Mailing\Editor\Brand;
use Mailing\Services\Contacts;
use Mailing\Services\Forms;

/** Public signup page /f/{slug} (also embeddable with ?embed=1) and the confirmation link. */
final class PublicFormController
{
    private function load(string $slug): array
    {
        $f = DB::one('SELECT * FROM forms WHERE slug = ?', [$slug]);
        $p = $f ? DB::one('SELECT * FROM projects WHERE id = ? AND archived = 0', [$f['project_id']]) : null;
        if (!$f || !$p) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo View::render('errors/error', ['code' => 404, 'message' => 'Aquesta pàgina no existeix.'], 'layouts/bare');
            exit;
        }
        return [$f, $p];
    }

    private function render(array $f, array $p, array $state): never
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        $embed = isset($_GET['embed']);
        echo View::render('forms/public', [
            'f' => $f, 'p' => $p, 'state' => $state, 'embed' => $embed,
            'logo' => Brand::logoUrl($p),
            'fields' => Forms::fields($f),
            'custom' => array_column(Contacts::fields((int) $p['id']), 'label', 'key'),
            'lists' => ($ids = Forms::listIds($f)) ? DB::all('SELECT id, name, description FROM lists WHERE project_id = ? AND id IN (' . DB::in($ids) . ') ORDER BY name', array_merge([(int) $p['id']], $ids)) : [],
            'stamp' => Forms::stamp((int) $f['id']),
        ], null);
        exit;
    }

    public function show(string $slug): never
    {
        [$f, $p] = $this->load($slug);
        DB::exec('UPDATE forms SET views = views + 1 WHERE id = ?', [$f['id']]);
        $this->render($f, $p, $f['active'] ? [] : ['done' => true, 'ok' => false, 'message' => Forms::t($f, 'closed')]);
    }

    public function submit(string $slug): never
    {
        [$f, $p] = $this->load($slug);
        $r = Forms::submit($f, $p, $_POST);
        if ($r['ok'] && empty($r['pending']) && $f['redirect_url'] && !isset($_GET['embed'])) {
            redirect((string) $f['redirect_url']);
        }
        $this->render($f, $p, $r['ok'] ? ['done' => true, 'ok' => true, 'pending' => !empty($r['pending']), 'message' => $r['message']] : ['error' => $r['message'], 'old' => $_POST]);
    }

    public function confirm(string $slug, string $token): never
    {
        [$f, $p] = $this->load($slug);
        $s = strlen($token) === 40 ? DB::one('SELECT * FROM form_signups WHERE token = ? AND form_id = ?', [$token, $f['id']]) : null;
        if (!$s) {
            $this->render($f, $p, ['done' => true, 'ok' => false, 'message' => Forms::t($f, 'expired')]);
        }
        Forms::confirm($s, $f, true);
        if ($f['redirect_url']) {
            redirect((string) $f['redirect_url']);
        }
        $this->render($f, $p, ['done' => true, 'ok' => true, 'message' => $f['success_text'] ?: Forms::t($f, 'confirmed')]);
    }
}
