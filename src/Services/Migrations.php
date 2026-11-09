<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\DB;

/**
 * Runs the SQL files of database/migrations not yet recorded in
 * schema_migrations, in name order. Used by the installer, the OTA update
 * and `php bin/console.php migrate`. Migrations are additive and idempotent.
 */
final class Migrations
{
    /** @return array{applied: array<int,string>, pending: array<int,string>} */
    public static function status(): array
    {
        $applied = self::applied();
        $pending = [];
        foreach (self::files() as $f) {
            if (!isset($applied[basename($f)])) {
                $pending[] = basename($f);
            }
        }
        return ['applied' => array_keys($applied), 'pending' => $pending];
    }

    /** @return array{ok: bool, ran: array<int,string>, error?: string, failed?: string} */
    public static function run(?\PDO $pdo = null): array
    {
        $pdo ??= DB::pdo();
        $ran = [];
        $current = '';
        try {
            self::ensureTable($pdo);
            $applied = self::applied($pdo);
            $ins = $pdo->prepare('INSERT IGNORE INTO schema_migrations (file, applied_at) VALUES (?, UTC_TIMESTAMP())');
            foreach (self::files() as $file) {
                $current = basename($file);
                if (isset($applied[$current])) {
                    continue;
                }
                foreach (self::statements((string) file_get_contents($file)) as $sql) {
                    $pdo->exec($sql);
                }
                $ins->execute([$current]);
                $ran[] = $current;
            }
            return ['ok' => true, 'ran' => $ran];
        } catch (\Throwable $e) {
            return ['ok' => false, 'ran' => $ran, 'error' => $e->getMessage(), 'failed' => $current];
        }
    }

    /** Splits a SQL file into statements (on ";" at the end of a line, outside comments). */
    public static function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $out = [];
        foreach (preg_split('/;\s*(\r?\n|$)/', (string) $sql) as $s) {
            if (trim($s) !== '') {
                $out[] = trim($s);
            }
        }
        return $out;
    }

    /** @return array<int, string> */
    private static function files(): array
    {
        $files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
        sort($files);
        return $files;
    }

    /** @return array<string, true> */
    private static function applied(?\PDO $pdo = null): array
    {
        try {
            $pdo ??= DB::pdo();
            self::ensureTable($pdo);
            $rows = $pdo->query('SELECT file FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return [];
        }
        return array_fill_keys(array_map('strval', $rows), true);
    }

    private static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (file VARCHAR(190) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}
