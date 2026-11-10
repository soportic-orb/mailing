<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\Auth;
use Mailing\Core\DB;
use Mailing\Editor\Document;
use Mailing\Services\Audience;
use Mailing\Services\Activity;
use Mailing\Services\Campaigns;
use Mailing\Services\Cron;
use Mailing\Services\Stats;

final class CampaignController extends Controller
{
    public function index(): never
    {
        $pid = $this->pid();
        $status = str_input('estat', 20);
        $q = str_input('q', 100);
        $where = "c.project_id = ? AND c.status <> 'auto'";
        $params = [$pid];
        if (isset(Campaigns::STATUSES[$status])) {
            $where .= ' AND c.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where .= ' AND (c.name LIKE ? OR c.subject LIKE ?)';
            array_push($params, '%' . $q . '%', '%' . $q . '%');
        }
        $page = $this->paginate("SELECT COUNT(*) FROM campaigns c WHERE $where",
            "SELECT c.*, (SELECT COUNT(*) FROM messages m WHERE m.campaign_id = c.id AND m.status = 'sent') AS n_sent,
                (SELECT COUNT(*) FROM messages m WHERE m.campaign_id = c.id AND m.opened_at IS NOT NULL) AS n_open,
                (SELECT COUNT(*) FROM messages m WHERE m.campaign_id = c.id AND m.clicked_at IS NOT NULL) AS n_click
             FROM campaigns c WHERE $where ORDER BY FIELD(c.status, 'sending', 'paused', 'scheduled', 'draft') DESC, COALESCE(c.finished_at, c.scheduled_at, c.updated_at) DESC", $params, 30);
        $counts = array_column(DB::all("SELECT status, COUNT(*) AS n FROM campaigns WHERE project_id = ? AND status <> 'auto' GROUP BY status", [$pid]), 'n', 'status');
        $this->view('campaigns/index', ['title' => 'Campanyes', 'page' => $page, 'status' => $status, 'q' => $q, 'counts' => $counts]);
    }

    public function create(): never
    {
        $pid = $this->pid();
        $this->view('campaigns/create', [
            'title' => 'Nova campanya',
            'templates' => DB::all('SELECT id, name, doc FROM templates WHERE project_id = ? OR project_id IS NULL ORDER BY project_id IS NULL, name', [$pid]),
            'senders' => DB::all('SELECT * FROM senders WHERE project_id = ? ORDER BY email', [$pid]),
            'project' => $this->project(),
        ]);
    }

    public function store(): never
    {
        $project = $this->project();
        $name = str_input('name', 190);
        if ($name === '') {
            $this->fail('Posa un nom a la campanya (només el veureu vosaltres).');
        }
        $sender = int_input('sender_id') ?: null;
        if ($sender && !DB::value('SELECT id FROM senders WHERE id = ? AND project_id = ?', [$sender, $project['id']])) {
            $sender = null;
        }
        $id = Campaigns::create($project, $name, int_input('template_id') ?: null, $sender, str_input('language', 5));
        Activity::log('campaign.created', $name, (int) $project['id']);
        redirect('/campanyes/' . $id . '/editor');
    }

    public function show(string $id): never
    {
        $project = $this->project();
        $c = $this->own('campaigns', (int) $id);
        if ($c['status'] === 'auto') {
            redirect('/automatitzacions/' . (int) $c['automation_id']);
        }
        $doc = Document::decode($c['doc']);
        $pid = (int) $project['id'];
        $this->view('campaigns/show', [
            'title' => $c['name'],
            'c' => $c,
            'doc' => $doc,
            'project' => $project,
            'audience' => Audience::clean($c['audience']),
            'senders' => DB::all('SELECT * FROM senders WHERE project_id = ? ORDER BY email', [$pid]),
            'lists' => DB::all("SELECT l.*, (SELECT COUNT(*) FROM list_contacts lc JOIN contacts ct ON ct.id = lc.contact_id WHERE lc.list_id = l.id AND lc.status = 'subscribed' AND ct.status = 'subscribed') AS n FROM lists l WHERE l.project_id = ? ORDER BY l.name", [$pid]),
            'segments' => DB::all('SELECT * FROM segments WHERE project_id = ? ORDER BY name', [$pid]),
            'checks' => Campaigns::checks($c, $project),
            'count' => Audience::count(Audience::clean($c['audience']), $pid),
            'stats' => in_array($c['status'], ['sending', 'paused', 'sent'], true) ? Stats::campaign((int) $c['id']) : null,
            'me' => Auth::user(),
        ]);
    }

    public function update(string $id): never
    {
        $c = $this->own('campaigns', (int) $id);
        if (!Campaigns::editable($c)) {
            $this->fail('Una campanya que s\'està enviant o ja s\'ha enviat no es pot canviar. Duplica-la per fer-ne una de nova.');
        }
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
        $audience = Audience::clean(['all' => input('all') === '1', 'lists' => input('lists', []), 'segments' => input('segments', []), 'exclude_lists' => input('exclude_lists', []), 'lang' => str_input('lang', 5)]);
        DB::update('campaigns', [
            'name' => $name !== '' ? $name : $c['name'], 'sender_id' => $sender, 'reply_to' => $reply ?: null,
            'audience' => json_encode($audience), 'updated_by' => Auth::id(), 'updated_at' => now_utc(),
        ], 'id = ?', [$c['id']]);
        $this->done('Configuració desada.', '/campanyes/' . $c['id']);
    }

    /** Live recipient count while choosing lists and segments. */
    public function countAudience(string $id): never
    {
        $this->own('campaigns', (int) $id);
        $a = Audience::clean(['all' => input('all') === '1', 'lists' => input('lists', []), 'segments' => input('segments', []), 'exclude_lists' => input('exclude_lists', [])]);
        json_response(['ok' => true, 'count' => Audience::count($a, $this->pid())]);
    }

    public function test(string $id): never
    {
        $c = $this->own('campaigns', (int) $id);
        $emails = preg_split('/[\s,;]+/', (string) input('emails', '')) ?: [];
        try {
            $n = Campaigns::sendTest($c, $this->project(), $emails, str_input('lang', 5) ?: null);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage());
        }
        $this->done($n === 1 ? 'Prova enviada. Mira la safata d\'entrada (i la de correu brossa).' : $n . ' proves enviades.', '/campanyes/' . $c['id']);
    }

    public function schedule(string $id): never
    {
        $c = $this->own('campaigns', (int) $id);
        $now = input('when') === 'now';
        $utc = null;
        if (!$now) {
            $utc = to_utc(str_input('date', 10), str_input('time', 5) ?: '09:00');
            if (!$utc) {
                $this->fail('Indica la data i l\'hora de l\'enviament.');
            }
        }
        try {
            Campaigns::schedule($c, $this->project(), $utc);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage());
        }
        if ($now) {
            Cron::maybeInline();
            $this->done('Enviament en marxa. Pots seguir-ne el progrés aquí.', '/campanyes/' . $c['id']);
        }
        $this->done('Campanya programada per al ' . fmt_dt($utc) . '.', '/campanyes/' . $c['id']);
    }

    public function unschedule(string $id): never
    {
        $c = $this->own('campaigns', (int) $id);
        Campaigns::unschedule($c);
        $this->done('Programació anul·lada: torna a ser un esborrany.', '/campanyes/' . $c['id']);
    }

    public function pause(string $id): never
    {
        Campaigns::pause($this->own('campaigns', (int) $id));
        $this->done('Enviament pausat.', '/campanyes/' . $id);
    }

    public function resume(string $id): never
    {
        $c = $this->own('campaigns', (int) $id);
        Campaigns::resume($c);
        DB::update('campaigns', ['error' => null], 'id = ?', [$c['id']]);
        Cron::maybeInline();
        $this->done('Enviament represo.', '/campanyes/' . $id);
    }

    public function cancel(string $id): never
    {
        Campaigns::cancel($this->own('campaigns', (int) $id));
        $this->done('Enviament aturat definitivament. Els correus pendents no s\'enviaran.', '/campanyes/' . $id);
    }

    public function duplicate(string $id): never
    {
        $c = $this->own('campaigns', (int) $id);
        $new = Campaigns::duplicate($c, $this->project());
        $this->done('Campanya duplicada.', '/campanyes/' . $new);
    }

    public function destroy(string $id): never
    {
        $c = $this->own('campaigns', (int) $id);
        if (!in_array($c['status'], ['draft', 'cancelled', 'failed'], true)) {
            $this->fail('Només es poden eliminar els esborranys i les campanyes cancel·lades. Les enviades es conserven per a les estadístiques.');
        }
        DB::exec('DELETE FROM messages WHERE campaign_id = ?', [$c['id']]);
        DB::exec("DELETE FROM doc_versions WHERE owner_type = 'campaign' AND owner_id = ?", [$c['id']]);
        DB::exec('DELETE FROM campaigns WHERE id = ?', [$c['id']]);
        Activity::log('campaign.deleted', $c['name'], (int) $c['project_id']);
        $this->done('Campanya eliminada.', '/campanyes');
    }

    /** Calendar drag & drop: new date (keeps the time). */
    public function move(string $id): never
    {
        $c = $this->own('campaigns', (int) $id);
        if (!in_array($c['status'], ['draft', 'scheduled'], true)) {
            json_response(['ok' => false, 'message' => 'Només es poden moure els esborranys i les campanyes programades.'], 422);
        }
        $date = str_input('date', 10);
        $time = local_dt($c['scheduled_at'])?->format('H:i') ?? '09:00';
        $utc = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? to_utc($date, $time) : null;
        if (!$utc) {
            json_response(['ok' => false, 'message' => 'Data no vàlida.'], 422);
        }
        if ($c['status'] === 'scheduled' && strtotime($utc . ' UTC') < time()) {
            json_response(['ok' => false, 'message' => 'Una campanya programada no es pot moure al passat.'], 422);
        }
        DB::update('campaigns', ['scheduled_at' => $utc, 'updated_at' => now_utc(), 'updated_by' => Auth::id()], 'id = ?', [$c['id']]);
        Activity::log('campaign.moved', $c['name'] . ' → ' . $utc . ' UTC', (int) $c['project_id']);
        json_response(['ok' => true, 'message' => ($c['status'] === 'scheduled' ? 'Reprogramada' : 'Data prevista canviada') . ': ' . fmt_dt($utc) . '.']);
    }

    public function preview(string $id): never
    {
        $c = $this->own('campaigns', (int) $id);
        // Not sent yet: as it would go out now. Sent: exactly what was sent.
        $html = in_array($c['status'], ['sending', 'paused', 'sent'], true)
            ? (json_decode((string) $c['html'], true) ?: [])
            : Document::render(Document::decode($c['doc']), $this->project());
        $lang = str_input('lang', 5);
        self::htmlResponse((string) ($html[$lang] ?? reset($html) ?: ''));
    }

    public static function htmlResponse(string $html): never
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src * data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
        echo $html;
        exit;
    }
}
