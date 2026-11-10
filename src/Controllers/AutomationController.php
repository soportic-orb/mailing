<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\Auth;
use Mailing\Core\DB;
use Mailing\Editor\Document;
use Mailing\Services\Audience;
use Mailing\Services\Automations;
use Mailing\Services\Contacts;
use Mailing\Services\Cron;

/** Automations: list, flow editor, activity and settings. */
final class AutomationController extends Controller
{
    public function index(): never
    {
        $pid = $this->pid();
        $rows = DB::all("SELECT a.*,
                (SELECT COUNT(*) FROM automation_runs r WHERE r.automation_id = a.id) AS n_total,
                (SELECT COUNT(*) FROM automation_runs r WHERE r.automation_id = a.id AND r.status = 'active') AS n_active,
                (SELECT COUNT(*) FROM messages m JOIN campaigns c ON c.id = m.campaign_id WHERE c.automation_id = a.id AND m.status = 'sent') AS n_sent,
                (SELECT COUNT(*) FROM messages m JOIN campaigns c ON c.id = m.campaign_id WHERE c.automation_id = a.id AND m.opened_at IS NOT NULL) AS n_open
            FROM automations a WHERE a.project_id = ? ORDER BY FIELD(a.status, 'active', 'paused', 'draft'), a.updated_at DESC", [$pid]);
        foreach ($rows as &$r) {
            $r['trigger'] = Automations::describeStep(['type' => 'trigger', 'config' => Automations::cleanConfig('trigger', json_decode((string) $r['trigger_config'], true) ?: ['kind' => $r['trigger_type']])], ['steps' => [], 'edges' => []], $pid);
        }
        unset($r);
        $this->view('automations/index', ['title' => 'Automatitzacions', 'rows' => $rows]);
    }

    public function store(): never
    {
        $name = str_input('name', 190);
        if ($name === '') {
            $this->fail('Posa un nom a l\'automatització.');
        }
        $preset = str_input('preset', 20);
        $trigger = str_input('trigger', 10);
        $id = Automations::create($this->project(), $name, isset(Automations::TRIGGERS[$trigger]) ? $trigger : 'list', in_array($preset, ['welcome', 'birthday'], true) ? $preset : '');
        $this->done('Automatització creada. Configura el flux i activa-la quan estigui a punt.', '/automatitzacions/' . $id);
    }

    public function show(string $id): never
    {
        $project = $this->project();
        $a = $this->own('automations', (int) $id);
        $pid = (int) $project['id'];
        $tab = in_array(input('vista'), ['activitat', 'configuracio'], true) ? (string) input('vista') : 'flux';
        $data = [
            'title' => $a['name'],
            'wide' => $tab === 'flux',
            'a' => $a,
            'project' => $project,
            'tab' => $tab,
            'totals' => Automations::totals((int) $a['id']),
            'sent' => DB::one("SELECT COUNT(*) AS sent, SUM(m.opened_at IS NOT NULL) AS opened, SUM(m.clicked_at IS NOT NULL) AS clicked FROM messages m JOIN campaigns c ON c.id = m.campaign_id
                WHERE c.automation_id = ? AND m.status = 'sent'", [$a['id']]) ?? [],
            'checks' => Automations::checks($a, $project),
        ];
        if ($tab === 'flux') {
            $data['cfg'] = $this->editorConfig($a, $project);
        } elseif ($tab === 'activitat') {
            $filter = str_input('estat', 10);
            $where = 'r.automation_id = ?';
            $params = [$a['id']];
            if (in_array($filter, ['active', 'done', 'exited'], true)) {
                $where .= ' AND r.status = ?';
                $params[] = $filter;
            }
            $q = str_input('q', 100);
            if ($q !== '') {
                $where .= ' AND c.email LIKE ?';
                $params[] = '%' . $q . '%';
            }
            $data['filter'] = $filter;
            $data['q'] = $q;
            $data['page'] = $this->paginate("SELECT COUNT(*) FROM automation_runs r LEFT JOIN contacts c ON c.id = r.contact_id WHERE $where",
                "SELECT r.*, c.email, c.first_name, c.last_name FROM automation_runs r LEFT JOIN contacts c ON c.id = r.contact_id WHERE $where ORDER BY r.updated_at DESC, r.id DESC", $params, 50);
            $data['log'] = DB::all('SELECT l.*, c.email FROM automation_log l LEFT JOIN contacts c ON c.id = l.contact_id WHERE l.automation_id = ? ORDER BY l.id DESC LIMIT 60', [$a['id']]);
            $data['flow'] = Automations::decode($a);
        } else {
            $data['senders'] = DB::all('SELECT * FROM senders WHERE project_id = ? ORDER BY email', [$pid]);
            $data['lists'] = DB::all("SELECT l.*, (SELECT COUNT(*) FROM list_contacts lc JOIN contacts ct ON ct.id = lc.contact_id WHERE lc.list_id = l.id AND lc.status = 'subscribed' AND ct.status = 'subscribed') AS n FROM lists l WHERE l.project_id = ? ORDER BY l.name", [$pid]);
            $data['segments'] = DB::all('SELECT * FROM segments WHERE project_id = ? ORDER BY name', [$pid]);
        }
        $this->view('automations/show', $data);
    }

    private function editorConfig(array $a, array $project): array
    {
        $pid = (int) $project['id'];
        $flow = Automations::decode($a);
        $campaigns = [];
        foreach (DB::all("SELECT id, name, doc FROM campaigns WHERE automation_id = ? AND project_id = ? AND status = 'auto'", [$a['id'], $pid]) as $c) {
            $doc = Document::decode($c['doc']);
            $campaigns[$c['id']] = [
                'langs' => array_map(static fn ($l) => ['subject' => $l['subject'], 'blocks' => count($l['doc']['blocks'])], $doc['langs']),
                'edit' => '/campanyes/' . $c['id'] . '/editor', 'preview' => '/campanyes/' . $c['id'] . '/previsualitza', 'report' => '/campanyes/' . $c['id'] . '/informe',
            ];
        }
        $fields = [];
        foreach (Contacts::BASE_FIELDS as $k => $l) {
            if ($k !== 'external_id') {
                $fields[] = ['key' => $k, 'label' => $l, 'type' => 'text'];
            }
        }
        $fields[] = ['key' => 'created_at', 'label' => 'Data d\'alta', 'type' => 'date'];
        foreach (Contacts::fields($pid) as $f) {
            $fields[] = ['key' => 'camp:' . $f['key'], 'label' => $f['label'], 'type' => $f['type']];
        }
        return [
            'flow' => $flow,
            'stats' => Automations::stepStats($a),
            'campaigns' => $campaigns ?: new \stdClass(),
            'editable' => Auth::canEdit(),
            'status' => $a['status'],
            'lists' => DB::all('SELECT id, name FROM lists WHERE project_id = ? ORDER BY name', [$pid]),
            'forms' => DB::all('SELECT id, title AS name FROM forms WHERE project_id = ? ORDER BY title', [$pid]),
            'segments' => DB::all('SELECT id, name FROM segments WHERE project_id = ? ORDER BY name', [$pid]),
            'templates' => DB::all('SELECT id, name FROM templates WHERE project_id = ? OR project_id IS NULL ORDER BY name', [$pid]),
            'fields' => $fields,
            'langs' => Document::LANGS,
            'lang' => $project['default_language'] ?: 'ca',
            'triggers' => array_map(static fn ($t) => ['label' => $t[0], 'help' => $t[1]], Automations::TRIGGERS),
            'units' => Automations::UNITS,
            'fieldOps' => \Mailing\Services\Segments::FIELD_OPS,
            'apiUrl' => url('api/v1/events'),
            'urls' => ['save' => '/automatitzacions/' . $a['id'] . '/flux', 'simulate' => '/automatitzacions/' . $a['id'] . '/prova', 'settings' => '/automatitzacions/' . $a['id'] . '?vista=configuracio'],
        ];
    }

    /** Saves the flow from the editor (JSON). */
    public function saveFlow(string $id): never
    {
        $project = $this->project();
        $a = $this->own('automations', (int) $id);
        $r = Automations::saveFlow($a, input('flow'), $project);
        $a = $this->own('automations', (int) $id);
        $cfg = $this->editorConfig($a, $project);
        $checks = Automations::checks($a, $project);
        $held = $a['status'] === 'active' && Automations::blocking($checks) ? ' Hi ha passos incomplets: qui hi arribi s\'hi esperarà fins que els completis.' : '';
        json_response(['ok' => true, 'message' => 'Flux desat.' . ($r['moved'] ? ' ' . $r['moved'] . ' persones que eren en passos eliminats han passat al pas següent.' : '') . $held,
            'flow' => $cfg['flow'], 'campaigns' => $cfg['campaigns'], 'stats' => $cfg['stats'],
            'checks' => array_values(array_filter($checks, static fn ($c) => !$c['ok']))]);
    }

    public function update(string $id): never
    {
        $a = $this->own('automations', (int) $id);
        $pid = $this->pid();
        $name = str_input('name', 190);
        $sender = int_input('sender_id') ?: null;
        if ($sender && !DB::value('SELECT id FROM senders WHERE id = ? AND project_id = ?', [$sender, $pid])) {
            $sender = null;
        }
        $reply = normalize_email(str_input('reply_to', 190));
        if ($reply !== '' && !valid_email($reply)) {
            $this->fail('L\'adreça de resposta no és vàlida.');
        }
        DB::update('automations', ['name' => $name !== '' ? $name : $a['name'], 'sender_id' => $sender, 'reply_to' => $reply ?: null, 'updated_by' => Auth::id(), 'updated_at' => now_utc()], 'id = ?', [$a['id']]);
        // The email campaigns follow the new name and sender.
        $a = $this->own('automations', (int) $id);
        Automations::saveFlow($a, Automations::decode($a), $this->project());
        $this->done('Configuració desada.', '/automatitzacions/' . $a['id'] . '?vista=configuracio');
    }

    public function activate(string $id): never
    {
        $a = $this->own('automations', (int) $id);
        try {
            Automations::activate($a, $this->project());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage());
        }
        Cron::maybeInline();
        $this->done('Automatització activada.', '/automatitzacions/' . $a['id']);
    }

    public function pause(string $id): never
    {
        $a = $this->own('automations', (int) $id);
        Automations::pause($a);
        $this->done('Automatització pausada: els contactes es queden on són i no s\'envia res fins que la tornis a activar.', '/automatitzacions/' . $a['id']);
    }

    public function destroy(string $id): never
    {
        $a = $this->own('automations', (int) $id);
        if ($a['status'] === 'active') {
            $this->fail('Pausa l\'automatització abans d\'eliminar-la.');
        }
        Automations::delete($a);
        $this->done('Automatització eliminada.', '/automatitzacions');
    }

    /** Manual enrolment of lists / segments. */
    public function enroll(string $id): never
    {
        $a = $this->own('automations', (int) $id);
        if ($a['status'] !== 'active') {
            $this->fail('Activa primer l\'automatització.');
        }
        $aud = ['lists' => $this->ids('lists'), 'segments' => $this->ids('segments')];
        if (Audience::isEmpty(Audience::clean($aud))) {
            $this->fail('Tria almenys una llista o un segment.');
        }
        $n = Automations::enrollAudience($a, $aud);
        Cron::maybeInline();
        $this->done($n . ' contactes inscrits (els que ja hi eren no es tornen a inscriure).', '/automatitzacions/' . $a['id'] . '?vista=activitat');
    }

    /** Test path of a contact (JSON). */
    public function simulate(string $id): never
    {
        $a = $this->own('automations', (int) $id);
        $email = normalize_email(str_input('email', 190));
        $contact = valid_email($email) ? DB::one('SELECT * FROM contacts WHERE project_id = ? AND email = ?', [$this->pid(), $email]) : null;
        if (!$contact) {
            json_response(['ok' => false, 'message' => 'No hi ha cap contacte amb aquesta adreça en aquest projecte.'], 404);
        }
        json_response(['ok' => true, 'path' => Automations::simulate($a, $contact),
            'status' => $contact['status'] !== 'subscribed' ? 'Atenció: el contacte no està subscrit (' . (Contacts::STATUSES[$contact['status']] ?? $contact['status']) . '): no entraria al flux.' : '']);
    }

    public function removeRun(string $id, string $run): never
    {
        $a = $this->own('automations', (int) $id);
        $r = DB::one('SELECT * FROM automation_runs WHERE id = ? AND automation_id = ?', [(int) $run, $a['id']]);
        if (!$r) {
            abort(404);
        }
        Automations::remove($r, 'Tret manualment per ' . (Auth::user()['name'] ?? ''));
        $back = (string) parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH);
        $this->done('Contacte tret de l\'automatització.', str_starts_with($back, '/contactes/') ? $back : '/automatitzacions/' . $a['id'] . '?vista=activitat');
    }
}
