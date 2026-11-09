<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\DB;
use Mailing\Core\Settings;
use Mailing\Services\Contacts;
use Mailing\Services\Cron;
use Mailing\Services\Sync;
use Mailing\Services\Webhooks;

/**
 * Machine endpoints: Mailjet webhooks, the contacts API for the other
 * platforms (Bearer token of an "api" connector) and the external cron URL.
 */
final class ApiController
{
    private function body(): array
    {
        $d = json_decode((string) file_get_contents('php://input'), true);
        return is_array($d) ? $d : $_POST;
    }

    public function mailjet(): never
    {
        $key = (string) ($_GET['key'] ?? '');
        if (!Settings::has('webhook_key') || !hash_equals(Settings::get('webhook_key'), $key)) {
            json_response(['ok' => false], 403);
        }
        $raw = (string) file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            json_response(['ok' => false, 'message' => 'JSON no vàlid'], 400);
        }
        try {
            $n = Webhooks::handle($payload);
        } catch (\Throwable $e) {
            logger('Webhook: ' . $e->getMessage(), [], 'error');
            // 200 anyway: Mailjet would retry the whole batch forever.
            json_response(['ok' => false]);
        }
        json_response(['ok' => true, 'processed' => $n]);
    }

    private function connector(): array
    {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $token = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
        $c = $token !== '' ? Sync::byToken($token) : null;
        if (!$c) {
            json_response(['ok' => false, 'message' => 'Clau d\'API no vàlida.'], 401);
        }
        return $c;
    }

    public function ping(): never
    {
        $c = $this->connector();
        $p = DB::one('SELECT name FROM projects WHERE id = ?', [$c['project_id']]);
        json_response(['ok' => true, 'project' => $p['name'] ?? null, 'connector' => $c['name']]);
    }

    public function contacts(): never
    {
        $c = $this->connector();
        $body = $this->body();
        $items = isset($body['contacts']) && is_array($body['contacts']) ? $body['contacts'] : (isset($body['email']) ? [$body] : []);
        if (!$items || count($items) > 1000) {
            json_response(['ok' => false, 'message' => 'Envia «contacts» (llista de fins a 1.000) o un sol contacte amb «email».'], 422);
        }
        $pid = (int) $c['project_id'];
        $out = [];
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'invalid' => 0];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $counts['invalid']++;
                continue;
            }
            $data = Sync::mapItem($pid, $item);
            $r = Contacts::upsert($pid, $data, $c['list_id'] ? (int) $c['list_id'] : null, 'sync:' . $c['id']);
            $counts[$r['result']]++;
            $out[] = ['email' => normalize_email((string) ($item['email'] ?? '')), 'result' => $r['result']];
        }
        DB::update('connectors', ['last_run_at' => now_utc(), 'last_status' => 'ok', 'last_message' => 'API: ' . count($items) . ' contactes (' . $counts['created'] . ' nous, ' . $counts['updated'] . ' actualitzats)'], 'id = ?', [$c['id']]);
        json_response(['ok' => true] + $counts + ['results' => $out]);
    }

    private function find(array $c): ?array
    {
        $b = $this->body();
        $email = normalize_email((string) ($b['email'] ?? ''));
        return valid_email($email) ? DB::one('SELECT * FROM contacts WHERE project_id = ? AND email = ?', [$c['project_id'], $email]) : null;
    }

    public function unsubscribe(): never
    {
        $c = $this->connector();
        $ct = $this->find($c);
        if (!$ct) {
            json_response(['ok' => false, 'message' => 'Contacte no trobat.'], 404);
        }
        Contacts::setStatus((int) $ct['id'], 'unsubscribed', 'API: ' . $c['name']);
        json_response(['ok' => true]);
    }

    public function remove(): never
    {
        $c = $this->connector();
        $ct = $this->find($c);
        if (!$ct || !$c['list_id']) {
            json_response(['ok' => false, 'message' => $ct ? 'Aquesta connexió no té llista.' : 'Contacte no trobat.'], 404);
        }
        DB::exec('DELETE FROM list_contacts WHERE list_id = ? AND contact_id = ?', [$c['list_id'], $ct['id']]);
        json_response(['ok' => true]);
    }

    public function cron(string $key): never
    {
        if (!Settings::has('cron_key') || !hash_equals(Settings::get('cron_key'), $key)) {
            json_response(['ok' => false], 403);
        }
        json_response(['ok' => true, 'result' => Cron::run('cron')]);
    }
}
