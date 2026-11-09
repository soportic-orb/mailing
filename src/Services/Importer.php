<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\Auth;
use Mailing\Core\DB;

/**
 * Contact import from CSV / XLSX: upload → column mapping (guessed) → run.
 * Also used by the sync connectors (rows already mapped).
 */
final class Importer
{
    private const GUESS = [
        'email' => ['email', 'e-mail', 'correu', 'correu electronic', 'correo', 'correo electronico', 'mail', 'adreca electronica'],
        'first_name' => ['nom', 'nombre', 'first name', 'firstname', 'name', 'first_name'],
        'last_name' => ['cognoms', 'cognom', 'apellidos', 'apellido', 'last name', 'lastname', 'surname', 'last_name'],
        'language' => ['idioma', 'llengua', 'language', 'lang', 'lengua'],
        'external_id' => ['id', 'id extern', 'external id', 'external_id', 'codi', 'codigo', 'user_id'],
    ];

    /** Stores the uploaded file and returns its token. */
    public static function store(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('No s\'ha rebut el fitxer (potser és massa gran).');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
            throw new \RuntimeException('El fitxer ha de ser CSV o Excel (.xlsx).');
        }
        $token = random_token(24);
        $dest = BASE_PATH . '/storage/imports/' . $token . '.' . ($ext === 'xlsx' ? 'xlsx' : 'csv');
        if (!move_uploaded_file((string) $file['tmp_name'], $dest) && !rename((string) $file['tmp_name'], $dest)) {
            throw new \RuntimeException('No s\'ha pogut desar el fitxer a storage/imports.');
        }
        return ['token' => $token, 'name' => mb_substr((string) $file['name'], 0, 255)];
    }

    public static function path(string $token): ?string
    {
        if (!preg_match('/^[A-Za-z0-9]{24}$/', $token)) {
            return null;
        }
        foreach (['csv', 'xlsx'] as $ext) {
            $p = BASE_PATH . '/storage/imports/' . $token . '.' . $ext;
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    /** @return array{header: array<int,string>, rows: array<int, array<int,string>>, total: int, guess: array<int,string>} */
    public static function preview(string $file): array
    {
        [$header, $rows] = self::split(Spreadsheet::read($file, pathinfo($file, PATHINFO_EXTENSION)));
        $guess = [];
        foreach ($header as $i => $h) {
            $n = Contacts::fieldKey($h);
            $n = str_replace('_', ' ', $n);
            $guess[$i] = '';
            foreach (self::GUESS as $target => $names) {
                if (in_array($n, $names, true) && !in_array($target, $guess, true)) {
                    $guess[$i] = $target;
                    break;
                }
            }
        }
        // Email column by content if the header did not tell.
        if (!in_array('email', $guess, true)) {
            foreach ($header as $i => $_) {
                if (isset($rows[0][$i]) && valid_email(normalize_email($rows[0][$i]))) {
                    $guess[$i] = 'email';
                    break;
                }
            }
        }
        return ['header' => $header, 'rows' => array_slice($rows, 0, 5), 'total' => count($rows), 'guess' => $guess];
    }

    /**
     * Header row and data rows. Without a header (the first line already has
     * an email) the columns are called «Columna 1», «Columna 2»…
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    private static function split(array $rows): array
    {
        $first = $rows[0] ?? [];
        if ($first && !array_filter($first, static fn ($v) => valid_email(normalize_email((string) $v)))) {
            array_shift($rows);
            return [$first, $rows];
        }
        return [array_map(static fn ($i) => 'Columna ' . ($i + 1), array_keys($first)), $rows];
    }

    /**
     * @param array<int, string> $mapping column => email|first_name|last_name|language|external_id|camp:<key>|new:<label>|''
     * @return array{created:int, updated:int, skipped:int, errors: array<int,string>, import_id:int}
     */
    public static function run(int $projectId, string $file, string $filename, array $mapping, ?int $listId, bool $overwrite, string $status = 'subscribed'): array
    {
        @set_time_limit(600);
        [, $rows] = self::split(Spreadsheet::read($file, pathinfo($file, PATHINFO_EXTENSION)));
        // Resolve "new:<label>" to real custom fields.
        foreach ($mapping as $i => $target) {
            if (str_starts_with($target, 'new:')) {
                $key = Contacts::ensureField($projectId, substr($target, 4));
                $mapping[$i] = $key !== '' ? 'camp:' . $key : '';
            }
        }
        if (!in_array('email', $mapping, true)) {
            throw new \RuntimeException('Indica quina columna té el correu electrònic.');
        }
        $data = [];
        foreach ($rows as $r) {
            $data[] = self::mapRow($r, $mapping, $status);
        }
        return self::apply($projectId, $data, $listId, 'importació', $overwrite, $filename);
    }

    /** @return array<string, mixed> */
    public static function mapRow(array $r, array $mapping, string $status = 'subscribed'): array
    {
        $c = ['fields' => []];
        foreach ($mapping as $i => $target) {
            $v = (string) ($r[$i] ?? '');
            if ($target === '' || $v === '') {
                continue;
            }
            if (str_starts_with($target, 'camp:')) {
                $c['fields'][substr($target, 5)] = $v;
            } else {
                $c[$target] = $v;
            }
        }
        if ($status === 'unsubscribed') {
            $c['status'] = 'unsubscribed';
        }
        return $c;
    }

    /**
     * Upserts mapped contacts and records the import.
     *
     * @param array<int, array<string, mixed>> $contacts
     */
    public static function apply(int $projectId, array $contacts, ?int $listId, string $source, bool $overwrite, string $filename = '', ?int $connectorId = null, bool $mirror = false): array
    {
        $created = $updated = $skipped = $removed = 0;
        $errors = [];
        $seen = [];
        foreach ($contacts as $n => $c) {
            $email = normalize_email((string) ($c['email'] ?? ''));
            if (isset($seen[$email])) {
                $skipped++;
                continue;
            }
            $r = Contacts::upsert($projectId, $c, $listId, $source, $overwrite);
            if ($r['result'] === 'invalid') {
                $skipped++;
                if (count($errors) < 50) {
                    $errors[] = 'Fila ' . ($n + 2) . ': correu no vàlid «' . mb_substr((string) ($c['email'] ?? ''), 0, 80) . '»';
                }
                continue;
            }
            $seen[$email] = (int) $r['id'];
            $r['result'] === 'created' ? $created++ : ($r['result'] === 'updated' ? $updated++ : null);
        }
        // Mirror sync: whoever is no longer in the source leaves the list.
        if ($mirror && $listId) {
            $keep = array_values($seen);
            $sql = "DELETE FROM list_contacts WHERE list_id = ? AND source = ?" . ($keep ? ' AND contact_id NOT IN (' . DB::in($keep) . ')' : '');
            $removed = DB::exec($sql, array_merge([$listId, $source], $keep));
        }
        $id = DB::insert('imports', [
            'project_id' => $projectId, 'list_id' => $listId, 'connector_id' => $connectorId, 'filename' => mb_substr($filename, 0, 255),
            'total' => count($contacts), 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'removed' => $removed,
            'errors' => $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null, 'user_id' => Auth::id(), 'created_at' => now_utc(),
        ]);
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'removed' => $removed, 'errors' => $errors, 'import_id' => $id];
    }
}
