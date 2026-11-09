<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\Context;
use Mailing\Core\Crypto;
use Mailing\Core\DB;
use Mailing\Editor\Document;
use Mailing\Editor\Images;
use Mailing\Services\Activity;
use Mailing\Services\Contacts;

/** Projects = the platforms / clients Soportic sends for (Emergencing, SysRevAI…). */
final class ProjectController extends Controller
{
    public function index(): never
    {
        $this->view('projects/index', [
            'title' => 'Projectes',
            'projects' => DB::all("SELECT p.*, (SELECT COUNT(*) FROM contacts c WHERE c.project_id = p.id AND c.status = 'subscribed') AS n_contacts,
                (SELECT COUNT(*) FROM campaigns ca WHERE ca.project_id = p.id AND ca.status = 'sent') AS n_sent,
                (SELECT COUNT(*) FROM senders s WHERE s.project_id = p.id) AS n_senders
                FROM projects p ORDER BY p.archived, p.name"),
        ]);
    }

    public function edit(?string $id = null): never
    {
        $p = $id ? DB::one('SELECT * FROM projects WHERE id = ?', [(int) $id]) : null;
        if ($id && !$p) {
            abort(404);
        }
        $this->view('projects/form', ['title' => $p ? $p['name'] : 'Projecte nou', 'p' => $p]);
    }

    public function save(?string $id = null): never
    {
        $p = $id ? DB::one('SELECT * FROM projects WHERE id = ?', [(int) $id]) : null;
        $name = str_input('name', 120);
        if ($name === '') {
            $this->fail('Posa un nom al projecte.');
        }
        $slug = Contacts::fieldKey(str_input('slug', 60) ?: $name);
        $slug = str_replace('_', '-', $slug) ?: 'projecte';
        if (DB::value('SELECT id FROM projects WHERE slug = ? AND id <> ?', [$slug, (int) ($p['id'] ?? 0)])) {
            $slug .= '-' . random_int(2, 99);
        }
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string) input('color')) ? strtolower((string) input('color')) : '#ac1966';
        $website = str_input('website', 255);
        if ($website !== '' && !preg_match('#^https?://#', $website)) {
            $website = 'https://' . $website;
        }
        $row = [
            'name' => $name, 'slug' => $slug, 'color' => $color, 'website' => $website ?: null,
            'company' => str_input('company', 190) ?: null, 'postal_address' => str_input('postal_address', 500) ?: null,
            'default_language' => isset(Document::LANGS[input('default_language')]) ? (string) input('default_language') : 'ca',
        ];
        $logoUrl = str_input('logo_url', 255);
        if (!empty($_FILES['logo']['tmp_name']) && ($_FILES['logo']['error'] ?? 1) === UPLOAD_ERR_OK) {
            try {
                $img = Images::storeBytes((string) file_get_contents($_FILES['logo']['tmp_name']), (string) $_FILES['logo']['name'], $p ? (int) $p['id'] : null, null);
                $row['logo_src'] = 'img:' . $img['token'];
            } catch (\RuntimeException $e) {
                $this->fail($e->getMessage());
            }
        } elseif ($logoUrl !== '' && preg_match('#^https://#', $logoUrl)) {
            $row['logo_src'] = $logoUrl;
        } elseif (input('logo_remove') === '1') {
            $row['logo_src'] = null;
        }
        $key = trim((string) input('mailjet_key', ''));
        $secret = trim((string) input('mailjet_secret', ''));
        if ($key !== '') {
            $row['mailjet_key'] = $key === '-' ? null : Crypto::encrypt($key);
        }
        if ($secret !== '') {
            $row['mailjet_secret'] = $secret === '-' ? null : Crypto::encrypt($secret);
        }
        if ($p) {
            DB::update('projects', $row, 'id = ?', [$p['id']]);
            $pid = (int) $p['id'];
        } else {
            $pid = DB::insert('projects', $row + ['created_at' => now_utc()]);
            Activity::log('project.created', $name, $pid);
            Context::switchTo($pid);
        }
        $this->done('Projecte desat.', $p ? '/projectes' : '/remitents');
    }

    public function archive(string $id): never
    {
        $p = DB::one('SELECT * FROM projects WHERE id = ?', [(int) $id]);
        if (!$p) {
            abort(404);
        }
        DB::update('projects', ['archived' => $p['archived'] ? 0 : 1], 'id = ?', [$p['id']]);
        Context::reset();
        $this->done($p['archived'] ? 'Projecte recuperat.' : 'Projecte arxivat (no s\'esborra res).', '/projectes');
    }
}
