<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\DB;
use Mailing\Services\Activity;
use Mailing\Services\Contacts;
use Mailing\Services\Spreadsheet;

final class ContactController extends Controller
{
    /** WHERE for the list filters (also used by export and bulk "all matching"). */
    private function filters(): array
    {
        $where = 'c.project_id = ?';
        $params = [$this->pid()];
        $q = str_input('q', 100);
        if ($q !== '') {
            $where .= ' AND (c.email LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, " ", c.last_name) LIKE ?)';
            array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
        }
        $list = int_input('llista');
        if ($list) {
            $where .= " AND c.id IN (SELECT contact_id FROM list_contacts WHERE list_id = ? AND status = 'subscribed')";
            $params[] = $list;
        }
        $status = str_input('estat', 20);
        if (isset(Contacts::STATUSES[$status])) {
            $where .= ' AND c.status = ?';
            $params[] = $status;
        }
        return [$where, $params, ['q' => $q, 'llista' => $list, 'estat' => $status]];
    }

    public function index(): never
    {
        [$where, $params, $f] = $this->filters();
        $page = $this->paginate("SELECT COUNT(*) FROM contacts c WHERE $where",
            "SELECT c.*, (SELECT GROUP_CONCAT(l.name ORDER BY l.name SEPARATOR ', ') FROM list_contacts lc JOIN lists l ON l.id = lc.list_id WHERE lc.contact_id = c.id AND lc.status = 'subscribed') AS list_names
             FROM contacts c WHERE $where ORDER BY c.created_at DESC, c.id DESC", $params, 50);
        $this->view('contacts/index', [
            'title' => 'Contactes', 'page' => $page, 'f' => $f,
            'lists' => DB::all('SELECT id, name FROM lists WHERE project_id = ? ORDER BY name', [$this->pid()]),
            'counts' => array_column(DB::all('SELECT status, COUNT(*) AS n FROM contacts WHERE project_id = ? GROUP BY status', [$this->pid()]), 'n', 'status'),
        ]);
    }

    public function create(): never
    {
        $this->view('contacts/form', ['title' => 'Contacte nou', 'c' => null, 'fields' => Contacts::fields($this->pid()),
            'lists' => DB::all('SELECT id, name FROM lists WHERE project_id = ? ORDER BY name', [$this->pid()]), 'inLists' => [int_input('llista')]]);
    }

    private function data(): array
    {
        $fields = [];
        foreach (Contacts::fields($this->pid()) as $fd) {
            $fields[$fd['key']] = str_input('f_' . $fd['key'], 1000);
        }
        return [
            'email' => str_input('email', 190), 'first_name' => str_input('first_name', 120), 'last_name' => str_input('last_name', 190),
            'language' => str_input('language', 5), 'fields' => $fields, 'status' => str_input('status', 12) ?: 'subscribed',
        ];
    }

    public function store(): never
    {
        $pid = $this->pid();
        $data = $this->data();
        if (!valid_email(normalize_email($data['email']))) {
            $this->fail('El correu electrònic no és vàlid.');
        }
        if (DB::value('SELECT id FROM contacts WHERE project_id = ? AND email = ?', [$pid, normalize_email($data['email'])])) {
            $this->fail('Ja hi ha un contacte amb aquest correu en aquest projecte.');
        }
        if (input('consent') !== '1') {
            $this->fail('Confirma que aquesta persona ha donat el consentiment per rebre correus.');
        }
        $r = Contacts::upsert($pid, $data, null, 'manual');
        $this->syncLists((int) $r['id']);
        Activity::log('contact.created', $data['email'], $pid);
        $this->done('Contacte creat.', '/contactes/' . $r['id']);
    }

    private function syncLists(int $contactId): void
    {
        $want = DB::column('SELECT id FROM lists WHERE project_id = ? AND id IN (' . DB::in($this->ids('lists')) . ')', array_merge([$this->pid()], $this->ids('lists')));
        $all = DB::column('SELECT id FROM lists WHERE project_id = ?', [$this->pid()]);
        foreach ($all as $lid) {
            if (in_array($lid, $want)) {
                DB::exec("INSERT INTO list_contacts (list_id, contact_id, status, source, added_at) VALUES (?, ?, 'subscribed', 'manual', ?) ON DUPLICATE KEY UPDATE status = 'subscribed'", [$lid, $contactId, now_utc()]);
            } else {
                DB::exec('DELETE FROM list_contacts WHERE list_id = ? AND contact_id = ?', [$lid, $contactId]);
            }
        }
    }

    public function show(string $id): never
    {
        $c = $this->own('contacts', (int) $id);
        $this->view('contacts/form', [
            'title' => $c['email'], 'c' => $c, 'fields' => Contacts::fields($this->pid()),
            'lists' => DB::all('SELECT id, name FROM lists WHERE project_id = ? ORDER BY name', [$this->pid()]),
            'inLists' => array_map('intval', DB::column("SELECT list_id FROM list_contacts WHERE contact_id = ? AND status = 'subscribed'", [$c['id']])),
            'messages' => DB::all('SELECT m.*, ca.name AS campaign FROM messages m JOIN campaigns ca ON ca.id = m.campaign_id WHERE m.contact_id = ? ORDER BY m.id DESC LIMIT 50', [$c['id']]),
            'events' => DB::all("SELECT e.*, ca.name AS campaign FROM events e LEFT JOIN campaigns ca ON ca.id = e.campaign_id WHERE e.contact_id = ? ORDER BY e.id DESC LIMIT 50", [$c['id']]),
            'runs' => DB::all('SELECT r.*, a.name AS automation, a.flow FROM automation_runs r JOIN automations a ON a.id = r.automation_id WHERE r.contact_id = ? AND a.project_id = ? ORDER BY r.status = \'active\' DESC, r.id DESC LIMIT 30', [$c['id'], $this->pid()]),
        ]);
    }

    public function update(string $id): never
    {
        $c = $this->own('contacts', (int) $id);
        $data = $this->data();
        $email = normalize_email($data['email']);
        if (!valid_email($email)) {
            $this->fail('El correu electrònic no és vàlid.');
        }
        if ($email !== $c['email'] && DB::value('SELECT id FROM contacts WHERE project_id = ? AND email = ?', [$this->pid(), $email])) {
            $this->fail('Ja hi ha un altre contacte amb aquest correu.');
        }
        $fields = json_decode((string) $c['fields'], true) ?: [];
        foreach ($data['fields'] as $k => $v) {
            if ($v === '') {
                unset($fields[$k]);
            } else {
                $fields[$k] = $v;
            }
        }
        $status = isset(Contacts::STATUSES[$data['status']]) ? $data['status'] : $c['status'];
        DB::update('contacts', [
            'email' => $email, 'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'language' => Contacts::normalizeLang($data['language']),
            'fields' => $fields ? json_encode($fields, JSON_UNESCAPED_UNICODE) : null, 'status' => $status,
            'unsubscribed_at' => $status === 'unsubscribed' ? ($c['unsubscribed_at'] ?: now_utc()) : null, 'updated_at' => now_utc(),
        ], 'id = ?', [$c['id']]);
        if ($status !== $c['status']) {
            DB::insert('events', ['contact_id' => $c['id'], 'type' => 'status', 'detail' => $status . ': canvi manual', 'created_at' => now_utc()]);
        }
        $this->syncLists((int) $c['id']);
        $this->done('Contacte desat.', '/contactes/' . $c['id']);
    }

    public function destroy(string $id): never
    {
        $c = $this->own('contacts', (int) $id);
        Contacts::delete($this->pid(), [(int) $c['id']]);
        Activity::log('contact.deleted', $c['email'], $this->pid());
        $this->done('Contacte eliminat amb totes les seves dades.', '/contactes');
    }

    /** GDPR: everything about a contact, as JSON. */
    public function gdpr(string $id): never
    {
        $c = $this->own('contacts', (int) $id);
        header('Content-Disposition: attachment; filename="contacte-' . (int) $c['id'] . '.json"');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(Contacts::export((int) $c['id']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function bulk(): never
    {
        $pid = $this->pid();
        $action = str_input('action', 20);
        if (input('scope') === 'filtered') {
            [$where, $params] = $this->filters();
            $ids = array_map('intval', DB::column("SELECT c.id FROM contacts c WHERE $where", $params));
        } else {
            $ids = $this->ids('ids');
            $ids = $ids ? array_map('intval', DB::column('SELECT id FROM contacts WHERE project_id = ? AND id IN (' . DB::in($ids) . ')', array_merge([$pid], $ids))) : [];
        }
        if (!$ids) {
            $this->fail('No has seleccionat cap contacte.');
        }
        $list = int_input('list_id');
        if (in_array($action, ['add_list', 'remove_list'], true) && !DB::value('SELECT id FROM lists WHERE id = ? AND project_id = ?', [$list, $pid])) {
            $this->fail('Tria la llista.');
        }
        $n = count($ids);
        switch ($action) {
            case 'add_list':
                $st = DB::pdo()->prepare("INSERT INTO list_contacts (list_id, contact_id, status, source, added_at) VALUES (?, ?, 'subscribed', 'manual', ?) ON DUPLICATE KEY UPDATE status = 'subscribed'");
                foreach ($ids as $cid) {
                    $st->execute([$list, $cid, now_utc()]);
                }
                $msg = $n . ' contactes afegits a la llista.';
                break;
            case 'remove_list':
                DB::exec('DELETE FROM list_contacts WHERE list_id = ? AND contact_id IN (' . DB::in($ids) . ')', array_merge([$list], $ids));
                $msg = $n . ' contactes trets de la llista.';
                break;
            case 'unsubscribe':
                foreach ($ids as $cid) {
                    Contacts::setStatus($cid, 'unsubscribed', 'baixa manual');
                }
                $msg = $n . ' contactes donats de baixa.';
                break;
            case 'resubscribe':
                DB::exec("UPDATE contacts SET status = 'subscribed', unsubscribed_at = NULL, updated_at = ? WHERE status = 'unsubscribed' AND id IN (" . DB::in($ids) . ')', array_merge([now_utc()], $ids));
                $msg = 'Contactes de baixa tornats a subscriure (només els que havien demanat la baixa, no els rebots ni les queixes).';
                break;
            case 'delete':
                Contacts::delete($pid, $ids);
                $msg = $n . ' contactes eliminats.';
                break;
            default:
                $this->fail('Acció desconeguda.');
        }
        Activity::log('contacts.' . $action, $n . ' contactes', $pid);
        $this->done($msg, '/contactes' . (input('back') ? '?' . (string) input('back') : ''));
    }

    public function export(): never
    {
        [$where, $params] = $this->filters();
        $fields = Contacts::fields($this->pid());
        $rows = DB::all("SELECT c.*, (SELECT GROUP_CONCAT(l.name SEPARATOR ', ') FROM list_contacts lc JOIN lists l ON l.id = lc.list_id WHERE lc.contact_id = c.id AND lc.status = 'subscribed') AS list_names FROM contacts c WHERE $where ORDER BY c.id", $params);
        $header = array_merge(['email', 'nom', 'cognoms', 'idioma', 'estat', 'llistes', 'alta', 'origen'], array_column($fields, 'label'));
        $out = (static function () use ($rows, $fields) {
            foreach ($rows as $r) {
                $f = json_decode((string) $r['fields'], true) ?: [];
                yield array_merge([$r['email'], $r['first_name'], $r['last_name'], $r['language'], Contacts::STATUSES[$r['status']] ?? $r['status'], $r['list_names'], $r['created_at'], $r['source']],
                    array_map(static fn ($fd) => $f[$fd['key']] ?? '', $fields));
            }
        })();
        Spreadsheet::download('contactes-' . $this->project()['slug'] . '-' . gmdate('Ymd') . '.csv', $header, $out);
    }

    public function fields(): never
    {
        $this->view('contacts/fields', ['title' => 'Camps dels contactes', 'fields' => Contacts::fields($this->pid())]);
    }

    public function storeField(): never
    {
        $label = str_input('label', 120);
        $key = Contacts::ensureField($this->pid(), $label, in_array(input('type'), ['text', 'number', 'date'], true) ? (string) input('type') : 'text');
        if ($key === '') {
            $this->fail('Escriu el nom del camp.');
        }
        $this->done('Camp creat: {{camp:' . $key . '}}', '/contactes/camps');
    }

    public function destroyField(string $key): never
    {
        DB::exec('DELETE FROM fields WHERE project_id = ? AND `key` = ?', [$this->pid(), $key]);
        $this->done('Camp eliminat (els valors que ja tenen els contactes es conserven a la base de dades).', '/contactes/camps');
    }
}
