<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\DB;
use Mailing\Core\Settings;
use Mailing\Services\Automations;
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
            [$add, $remove, $consent] = self::listNames($item);
            $data = Sync::mapItem($pid, $item);
            $r = Contacts::upsert($pid, $data, $c['list_id'] ? (int) $c['list_id'] : null, 'sync:' . $c['id']);
            $counts[$r['result']]++;
            $row = ['email' => normalize_email((string) ($item['email'] ?? '')), 'result' => $r['result']];
            $optIn = ($data['status'] ?? '') === 'subscribed';
            if ($r['id'] && ($add || $remove)) {
                $row += self::applyLists($c, (int) $r['id'], $add, $remove, $optIn);
            }
            if ($r['id'] && $optIn && $consent !== '') {
                self::consent($c, (int) $r['id'], $consent);
            }
            $out[] = $row;
        }
        DB::update('connectors', ['last_run_at' => now_utc(), 'last_status' => 'ok', 'last_message' => 'API: ' . count($items) . ' contactes (' . $counts['created'] . ' nous, ' . $counts['updated'] . ' actualitzats)'], 'id = ?', [$c['id']]);
        json_response(['ok' => true] + $counts + ['results' => $out]);
    }

    /**
     * An event of another platform ("registre", "pagament"…): creates or
     * updates the contact and enrols it in the automations waiting for it.
     */
    public function event(): never
    {
        $c = $this->connector();
        $body = $this->body();
        $event = strtolower(trim((string) ($body['event'] ?? '')));
        if (!preg_match('/^[a-z0-9_.:-]{1,60}$/', $event)) {
            json_response(['ok' => false, 'message' => 'Indica «event»: el nom de l\'esdeveniment (lletres, números, _ . : -).'], 422);
        }
        $item = isset($body['contact']) && is_array($body['contact']) ? $body['contact'] : $body;
        unset($item['event']);
        $pid = (int) $c['project_id'];
        [$add, $remove, $consent] = self::listNames($item);
        $data = Sync::mapItem($pid, $item);
        $r = Contacts::upsert($pid, $data, $c['list_id'] ? (int) $c['list_id'] : null, 'sync:' . $c['id']);
        if (!$r['id']) {
            json_response(['ok' => false, 'message' => 'Falta un «email» vàlid.'], 422);
        }
        $optIn = ($data['status'] ?? '') === 'subscribed';
        if ($add || $remove) {
            self::applyLists($c, (int) $r['id'], $add, $remove, $optIn);
        }
        if ($optIn && $consent !== '') {
            self::consent($c, (int) $r['id'], $consent);
        }
        $n = Automations::fireEvent($pid, $event, (int) $r['id']);
        DB::update('connectors', ['last_run_at' => now_utc(), 'last_status' => 'ok', 'last_message' => 'API: esdeveniment «' . $event . '» · ' . $n . ' automatitzacions'], 'id = ?', [$c['id']]);
        json_response(['ok' => true, 'event' => $event, 'contact' => $r['result'], 'enrolled' => $n]);
    }

    /**
     * "lists" / "remove_lists" of an item (names or ids), taken out of it so
     * they do not become fields, plus the "consent" text (proof of an
     * explicit opt-in). @return array{0: string[], 1: string[], 2: string}
     */
    private static function listNames(array &$item): array
    {
        $consent = is_scalar($item['consent'] ?? null) ? mb_substr(trim((string) $item['consent']), 0, 400) : '';
        unset($item['consent']);
        $get = static function (string $k) use (&$item): array {
            $v = $item[$k] ?? [];
            unset($item[$k]);
            $v = is_array($v) ? $v : (is_scalar($v) && trim((string) $v) !== '' ? [$v] : []);
            return array_slice(array_values(array_unique(array_filter(array_map(static fn ($x) => is_scalar($x) ? mb_substr(trim((string) $x), 0, 120) : '', $v), 'strlen'))), 0, 20);
        };
        return [$get('lists'), $get('remove_lists'), $consent];
    }

    /**
     * Adds the contact to lists of the connector's project (created by name if
     * missing) and takes it out of others. Someone who unsubscribed from a list
     * is not subscribed again (unless this is an explicit opt-in: "status":
     * "subscribed"), and that record is kept when "removing".
     *
     * @return array{lists?: string[], removed?: string[]}
     */
    private static function applyLists(array $c, int $contactId, array $add, array $remove, bool $optIn = false): array
    {
        $pid = (int) $c['project_id'];
        $find = static function (string $ref, bool $create) use ($pid, $c): ?array {
            $row = ctype_digit($ref)
                ? DB::one('SELECT id, name FROM lists WHERE id = ? AND project_id = ?', [(int) $ref, $pid])
                : DB::one('SELECT id, name FROM lists WHERE name = ? AND project_id = ? ORDER BY id LIMIT 1', [$ref, $pid]);
            if (!$row && $create && !ctype_digit($ref)) {
                $id = DB::insert('lists', ['project_id' => $pid, 'name' => $ref, 'description' => 'Creada per l\'API («' . $c['name'] . '»).', 'public' => 0, 'created_at' => now_utc()]);
                $row = ['id' => $id, 'name' => $ref];
            }
            return $row;
        };
        $out = [];
        foreach ($add as $ref) {
            if ($l = $find($ref, true)) {
                DB::exec('INSERT ' . ($optIn ? '' : 'IGNORE ') . 'INTO list_contacts (list_id, contact_id, status, source, added_at) VALUES (?, ?, ?, ?, ?)'
                    . ($optIn ? " ON DUPLICATE KEY UPDATE status = 'subscribed'" : ''), [$l['id'], $contactId, 'subscribed', 'sync:' . $c['id'], now_utc()]);
                $out['lists'][] = $l['name'];
            }
        }
        foreach ($remove as $ref) {
            if (($l = $find($ref, false)) && !in_array($l['name'], $out['lists'] ?? [], true)) {
                DB::exec("DELETE FROM list_contacts WHERE list_id = ? AND contact_id = ? AND status = 'subscribed'", [$l['id'], $contactId]);
                $out['removed'][] = $l['name'];
            }
        }
        return $out;
    }

    /** Proof of consent (GDPR) sent by the platform: what was accepted, where and when. */
    private static function consent(array $c, int $contactId, string $text): void
    {
        DB::update('contacts', ['consent_at' => now_utc()], 'id = ?', [$contactId]);
        DB::insert('events', ['contact_id' => $contactId, 'type' => 'consent', 'created_at' => now_utc(),
            'detail' => mb_substr('API «' . $c['name'] . '» · ' . $text, 0, 500)]);
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
