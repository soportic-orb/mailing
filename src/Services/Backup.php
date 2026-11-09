<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\DB;

/** Gzipped SQL dump of the database, made in PHP (no mysqldump needed on shared hosting). */
final class Backup
{
    public const DIR = '/storage/backups';

    public static function create(string $reason = 'manual'): string
    {
        @set_time_limit(600);
        $dir = BASE_PATH . self::DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/db-' . gmdate('Ymd-His') . '-' . preg_replace('/[^a-z]/', '', $reason) . '.sql.gz';
        $gz = gzopen($file, 'wb6');
        if (!$gz) {
            throw new \RuntimeException('No es pot escriure la còpia de seguretat a storage/backups.');
        }
        $pdo = DB::pdo();
        gzwrite($gz, "-- Plataforma de Mailing " . app_version() . " · " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(\PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n{$create};\n");
            $st = $pdo->query('SELECT * FROM `' . $table . '`', \PDO::FETCH_NUM);
            $batch = [];
            while ($row = $st->fetch()) {
                $batch[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                if (count($batch) >= 200) {
                    gzwrite($gz, 'INSERT INTO `' . $table . '` VALUES ' . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, 'INSERT INTO `' . $table . '` VALUES ' . implode(",\n", $batch) . ";\n");
            }
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
        self::prune(10);
        return basename($file);
    }

    /** @return array<int, array{name:string, size:int, time:int}> */
    public static function list(): array
    {
        $out = [];
        foreach (glob(BASE_PATH . self::DIR . '/*.sql.gz') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'time' => (int) filemtime($f)];
        }
        usort($out, static fn ($a, $b) => $b['time'] <=> $a['time']);
        return $out;
    }

    public static function prune(int $keep): void
    {
        foreach (array_slice(self::list(), $keep) as $b) {
            @unlink(BASE_PATH . self::DIR . '/' . $b['name']);
        }
    }
}
