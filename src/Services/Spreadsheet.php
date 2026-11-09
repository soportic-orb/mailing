<?php

declare(strict_types=1);

namespace Mailing\Services;

/** Reads CSV (any delimiter, UTF-8 or Windows-1252) and XLSX (first sheet) into rows. */
final class Spreadsheet
{
    public const MAX_ROWS = 100000;

    /** @return array<int, array<int, string>> */
    public static function read(string $file, string $ext): array
    {
        return strtolower($ext) === 'xlsx' ? self::xlsx($file) : self::csv($file);
    }

    /** @return array<int, array<int, string>> */
    public static function csv(string $file): array
    {
        $raw = (string) file_get_contents($file);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $raw = str_replace(["\r\n", "\r"], "\n", (string) $raw);
        $first = strtok($raw, "\n") ?: '';
        $delims = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t"), '|' => substr_count($first, '|')];
        arsort($delims);
        $delim = (string) array_key_first($delims);
        if (($delims[$delim] ?? 0) === 0) {
            $delim = ',';
        }
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if ($r === [null]) {
                continue;
            }
            $rows[] = array_map(static fn ($v) => trim((string) $v), $r);
            if (count($rows) > self::MAX_ROWS) {
                break;
            }
        }
        fclose($fh);
        return $rows;
    }

    /** @return array<int, array<int, string>> */
    public static function xlsx(string $file): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('El servidor no té l\'extensió ZIP de PHP: desa el full com a CSV.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($file) !== true) {
            throw new \RuntimeException('No s\'ha pogut obrir el fitxer Excel.');
        }
        $strings = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = @simplexml_load_string($xml);
            foreach ($sx ? $sx->si : [] as $si) {
                if (isset($si->t)) {
                    $strings[] = (string) $si->t;
                } else {
                    $s = '';
                    foreach ($si->r as $r) {
                        $s .= (string) $r->t;
                    }
                    $strings[] = $s;
                }
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) {
            // First sheet with another name.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = (string) $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)) {
                    $sheet = $zip->getFromName($n);
                    break;
                }
            }
        }
        $zip->close();
        $sx = $sheet ? @simplexml_load_string($sheet) : null;
        if (!$sx) {
            throw new \RuntimeException('El fitxer Excel no té cap full llegible.');
        }
        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = self::colIndex(preg_replace('/\d+/', '', $ref));
                $t = (string) $c['t'];
                $v = (string) $c->v;
                if ($t === 's') {
                    $v = $strings[(int) $v] ?? '';
                } elseif ($t === 'inlineStr') {
                    $v = (string) $c->is->t;
                } elseif ($t === 'b') {
                    $v = $v === '1' ? 'sí' : 'no';
                }
                $cells[$col] = trim($v);
            }
            if (!$cells) {
                continue;
            }
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            $rows[] = $line;
            if (count($rows) > self::MAX_ROWS) {
                break;
            }
        }
        return $rows;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }

    /** CSV download of rows (UTF-8 with BOM so Excel opens it well, ; delimiter). */
    public static function download(string $filename, array $header, iterable $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ';', '"', '');
        // Values starting with = + - @ are prefixed so Excel does not run them as formulas.
        $safe = static function ($v): string {
            $v = is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v);
            return $v !== '' && str_contains('=+-@', $v[0]) ? "'" . $v : $v;
        };
        foreach ($rows as $r) {
            fputcsv($out, array_map($safe, $r), ';', '"', '');
        }
        fclose($out);
        exit;
    }
}
