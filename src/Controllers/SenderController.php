<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\DB;
use Mailing\Mailjet\Client;
use Mailing\Services\Activity;

/**
 * Sending addresses of a project. Mailjet only sends from validated senders
 * (or from addresses of a validated domain with SPF/DKIM), so they are
 * synchronised with the Mailjet account.
 */
final class SenderController extends Controller
{
    public function index(): never
    {
        $project = $this->project();
        $domains = [];
        $error = null;
        $client = Client::forProject($project);
        if ($client->configured() && input('dns') === '1') {
            try {
                $domains = $client->domains();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $this->view('senders/index', [
            'title' => 'Remitents',
            'project' => $project,
            'senders' => DB::all('SELECT * FROM senders WHERE project_id = ? ORDER BY email', [$project['id']]),
            'configured' => $client->configured(),
            'domains' => $domains,
            'error' => $error,
        ]);
    }

    public function store(): never
    {
        $project = $this->project();
        $email = normalize_email(str_input('email', 190));
        $name = str_input('name', 120);
        $reply = normalize_email(str_input('reply_to', 190));
        if (!valid_email($email)) {
            $this->fail('L\'adreça no és vàlida.');
        }
        if ($reply !== '' && !valid_email($reply)) {
            $this->fail('L\'adreça de resposta no és vàlida.');
        }
        if (DB::value('SELECT id FROM senders WHERE project_id = ? AND email = ?', [$project['id'], $email])) {
            $this->fail('Aquest remitent ja hi és.');
        }
        $status = null;
        $mjId = null;
        $note = '';
        $client = Client::forProject($project);
        if ($client->configured()) {
            try {
                $existing = array_values(array_filter($client->senders(), static fn ($s) => $s['email'] === $email || $s['email'] === '*@' . substr(strrchr($email, '@'), 1)));
                if ($existing) {
                    $status = $existing[0]['status'];
                    $mjId = $existing[0]['id'] ?: null;
                } elseif (input('register') === '1') {
                    $r = $client->createSender($email, $name);
                    $mjId = $r['id'];
                    $status = 'Inactive';
                    $note = ' Mailjet ha enviat un correu de validació a ' . $email . ': obre l\'enllaç i després clica «Sincronitza amb Mailjet».';
                }
            } catch (\Throwable $e) {
                $note = ' (No s\'ha pogut consultar Mailjet: ' . $e->getMessage() . ')';
            }
        }
        $id = DB::insert('senders', ['project_id' => $project['id'], 'email' => $email, 'name' => $name, 'reply_to' => $reply ?: null,
            'mailjet_id' => $mjId, 'mailjet_status' => $status, 'checked_at' => $status ? now_utc() : null, 'created_at' => now_utc()]);
        if (!$project['default_sender_id']) {
            DB::update('projects', ['default_sender_id' => $id], 'id = ?', [$project['id']]);
        }
        Activity::log('sender.created', $email, (int) $project['id']);
        $this->done('Remitent afegit.' . $note, '/remitents');
    }

    public function sync(): never
    {
        $project = $this->project();
        try {
            $remote = Client::forProject($project)->senders();
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
        $byEmail = [];
        foreach ($remote as $s) {
            $byEmail[$s['email']] = $s;
        }
        $n = 0;
        foreach (DB::all('SELECT * FROM senders WHERE project_id = ?', [$project['id']]) as $s) {
            $domain = '*@' . substr((string) strrchr($s['email'], '@'), 1);
            $m = $byEmail[$s['email']] ?? $byEmail[$domain] ?? null;
            DB::update('senders', ['mailjet_status' => $m['status'] ?? 'No trobat', 'mailjet_id' => $m['id'] ?? null, 'checked_at' => now_utc()], 'id = ?', [$s['id']]);
            $n++;
        }
        $this->done($n . ' remitents comprovats a Mailjet.', '/remitents');
    }

    public function update(string $id): never
    {
        $s = $this->own('senders', (int) $id);
        $reply = normalize_email(str_input('reply_to', 190));
        if ($reply !== '' && !valid_email($reply)) {
            $this->fail('L\'adreça de resposta no és vàlida.');
        }
        DB::update('senders', ['name' => str_input('name', 120), 'reply_to' => $reply ?: null], 'id = ?', [$s['id']]);
        $this->done('Remitent desat.', '/remitents');
    }

    public function makeDefault(string $id): never
    {
        $s = $this->own('senders', (int) $id);
        DB::update('projects', ['default_sender_id' => $s['id']], 'id = ?', [$this->pid()]);
        $this->done('Remitent per defecte: ' . $s['email'], '/remitents');
    }

    public function destroy(string $id): never
    {
        $s = $this->own('senders', (int) $id);
        if (DB::value("SELECT COUNT(*) FROM campaigns WHERE sender_id = ? AND status IN ('scheduled', 'sending', 'paused')", [$s['id']]) || DB::value("SELECT COUNT(*) FROM automations WHERE sender_id = ? AND status = 'active'", [$s['id']])) {
            $this->fail('Hi ha campanyes programades o en curs, o automatitzacions actives, amb aquest remitent.');
        }
        DB::exec('UPDATE campaigns SET sender_id = NULL WHERE sender_id = ? AND status = \'draft\'', [$s['id']]);
        DB::exec('DELETE FROM senders WHERE id = ?', [$s['id']]);
        DB::exec('UPDATE projects SET default_sender_id = NULL WHERE default_sender_id = ?', [$s['id']]);
        $this->done('Remitent eliminat.', '/remitents');
    }
}
