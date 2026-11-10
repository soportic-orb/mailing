<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\Crypto;
use Mailing\Core\DB;
use Mailing\Core\Http;

/**
 * Contact synchronisation with the other platforms (Emergencing, SysRevAI…).
 *
 *  - api:   the platform pushes contacts to /api/v1/contacts with a token (real time).
 *  - mysql: we read its database with a SELECT, every N minutes.
 *  - json:  we read a JSON endpoint of the platform, every N minutes.
 *
 * Columns / keys: email (required), first_name, last_name, language,
 * external_id; any other one becomes a custom field. Mode "add" only adds and
 * updates; "mirror" also takes out of the list whoever is no longer there.
 */
final class Sync
{
    public const TYPES = ['api' => 'API (la plataforma envia els contactes)', 'mysql' => 'Base de dades MySQL (consulta SQL)', 'json' => 'Adreça JSON (URL)'];
    public const BASE = ['email', 'first_name', 'last_name', 'language', 'external_id', 'status'];

    public static function config(array $connector): array
    {
        return json_decode((string) (Crypto::decrypt($connector['config'] ?? null) ?? ''), true) ?: [];
    }

    public static function saveConfig(int $id, array $config): void
    {
        DB::update('connectors', ['config' => Crypto::encrypt(json_encode($config, JSON_UNESCAPED_UNICODE))], 'id = ?', [$id]);
    }

    /** New API token (shown once; only its hash is stored). */
    public static function newToken(int $id): string
    {
        $token = 'mlg_' . random_token(40);
        DB::update('connectors', ['token_hash' => hash('sha256', $token), 'token_hint' => substr($token, 0, 8)], 'id = ?', [$id]);
        return $token;
    }

    public static function byToken(string $token): ?array
    {
        if (!str_starts_with($token, 'mlg_')) {
            return null;
        }
        return DB::one("SELECT * FROM connectors WHERE token_hash = ? AND type = 'api' AND active = 1", [hash('sha256', $token)]);
    }

    /** Runs a pull connector now. @return array{ok:bool, message:string} */
    public static function run(array $connector): array
    {
        @set_time_limit(600);
        try {
            $rows = match ($connector['type']) {
                'mysql' => self::fetchMysql(self::config($connector)),
                'json' => self::fetchJson(self::config($connector)),
                default => throw new \RuntimeException('Aquest connector rep els contactes per l\'API: no cal executar-lo.'),
            };
            $projectId = (int) $connector['project_id'];
            $contacts = [];
            foreach ($rows as $row) {
                $contacts[] = self::mapItem($projectId, (array) $row);
            }
            $r = Importer::apply($projectId, $contacts, $connector['list_id'] ? (int) $connector['list_id'] : null,
                'sync:' . $connector['id'], true, $connector['name'], (int) $connector['id'], $connector['mode'] === 'mirror');
            $msg = count($contacts) . ' registres: ' . $r['created'] . ' nous, ' . $r['updated'] . ' actualitzats, ' . $r['skipped'] . ' descartats'
                . ($r['removed'] ? ', ' . $r['removed'] . ' tret(s) de la llista' : '') . '.';
            DB::update('connectors', ['last_run_at' => now_utc(), 'last_status' => 'ok', 'last_message' => $msg], 'id = ?', [$connector['id']]);
            return ['ok' => true, 'message' => $msg];
        } catch (\Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 1000);
            DB::update('connectors', ['last_run_at' => now_utc(), 'last_status' => 'error', 'last_message' => $msg], 'id = ?', [$connector['id']]);
            logger('Sync ' . $connector['id'] . ': ' . $msg, [], 'sync');
            Notify::syncFailed($connector, $msg);
            return ['ok' => false, 'message' => $msg];
        }
    }

    /** Pull connectors whose interval has passed. */
    public static function runDue(int $max = 5): int
    {
        $due = DB::all("SELECT * FROM connectors WHERE active = 1 AND type IN ('mysql', 'json') AND (last_run_at IS NULL OR last_run_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL interval_minutes MINUTE)) ORDER BY last_run_at LIMIT " . $max);
        foreach ($due as $c) {
            self::run($c);
        }
        return count($due);
    }

    /** One item (array) → contact data for Contacts::upsert(). */
    public static function mapItem(int $projectId, array $item): array
    {
        $c = ['fields' => []];
        $lower = array_change_key_case($item, CASE_LOWER);
        foreach ($lower as $k => $v) {
            if (is_array($v) && $k === 'fields') {
                foreach ($v as $fk => $fv) {
                    if (is_scalar($fv) || $fv === null) {
                        $c['fields'][(string) $fk] = (string) $fv;
                    }
                }
                continue;
            }
            if (!is_scalar($v) && $v !== null) {
                continue;
            }
            $aliases = ['nom' => 'first_name', 'nombre' => 'first_name', 'cognoms' => 'last_name', 'apellidos' => 'last_name', 'idioma' => 'language', 'id' => 'external_id', 'correu' => 'email', 'correo' => 'email'];
            $k = $aliases[$k] ?? $k;
            if (in_array($k, self::BASE, true)) {
                $c[$k] = (string) $v;
            } else {
                $c['fields'][$k] = (string) $v;
            }
        }
        foreach (array_keys($c['fields']) as $label) {
            $key = Contacts::ensureField($projectId, (string) $label);
            if ($key !== $label) {
                $c['fields'][$key] = $c['fields'][$label];
                unset($c['fields'][$label]);
            }
        }
        if (isset($c['status']) && !in_array($c['status'], ['subscribed', 'unsubscribed'], true)) {
            unset($c['status']);
        }
        return $c;
    }

    /** @return array<int, array<string, mixed>> */
    public static function fetchMysql(array $cfg): array
    {
        $query = trim((string) ($cfg['query'] ?? ''));
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $query) || preg_match('/;\s*\S/', $query)) {
            throw new \RuntimeException('La consulta ha de ser una sola instrucció SELECT.');
        }
        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|GRANT|INTO\s+OUTFILE|LOAD_FILE)\b/i', $query)) {
            throw new \RuntimeException('La consulta només pot llegir dades (SELECT).');
        }
        $pdo = DB::connect((string) ($cfg['host'] ?? 'localhost'), (int) ($cfg['port'] ?? 3306), (string) ($cfg['database'] ?? ''), (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''));
        try {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        } catch (\Throwable) {
        }
        $rows = $pdo->query(rtrim($query, "; \n\t"))->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows && !array_key_exists('email', array_change_key_case($rows[0], CASE_LOWER))) {
            throw new \RuntimeException('La consulta ha de tornar una columna «email» (fes servir AS email).');
        }
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    public static function fetchJson(array $cfg): array
    {
        $url = (string) ($cfg['url'] ?? '');
        if (!preg_match('#^https?://#', $url)) {
            throw new \RuntimeException('L\'adreça ha de començar per https://.');
        }
        $headers = [];
        if (!empty($cfg['header_name']) && !empty($cfg['header_value'])) {
            $headers[(string) $cfg['header_name']] = (string) $cfg['header_value'];
        }
        $r = Http::request('GET', $url, null, $headers, 60);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new \RuntimeException('L\'adreça ha respost amb l\'error ' . ($r['status'] ?: $r['error']) . '.');
        }
        if (!is_array($r['json'])) {
            throw new \RuntimeException('L\'adreça no ha retornat JSON vàlid.');
        }
        $data = $r['json'];
        $path = trim((string) ($cfg['path'] ?? ''));
        if ($path !== '') {
            $data = Http::get($data, $path, []);
        } elseif (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        } elseif (isset($data['contacts']) && is_array($data['contacts'])) {
            $data = $data['contacts'];
        }
        if (!is_array($data) || ($data && !array_is_list($data))) {
            throw new \RuntimeException('No s\'ha trobat la llista de contactes al JSON (indica el camí, p. ex. «data»).');
        }
        return $data;
    }
}
