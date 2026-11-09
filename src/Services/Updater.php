<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\Http;
use Mailing\Core\Settings;

/**
 * Over-the-air updates from GitHub, the same pattern as the other Soportic
 * platforms (BeDesk modules, SysRevAI):
 *
 *   remote version.txt newer? → pre-flight checks → maintenance mode →
 *   database backup → download the branch ZIP → swap the files (keeping
 *   .env, storage/ and the install lock; the old files are kept for rollback)
 *   → migrations (failure = files rolled back) → OPcache reset → done.
 *
 * Works without git or a shell on shared hosting. The repository is public,
 * so no token is needed (one can be set for a private fork).
 */
final class Updater
{
    /** Top-level entries never replaced. */
    private const PRESERVE = ['.env', 'storage', '.git', '.htaccess.local', '.user.ini'];
    private const MIN_FREE = 100 * 1024 * 1024;

    private array $log = [];

    public static function repo(): string
    {
        return Settings::get('update_repo', 'soportic-orb/mailing');
    }

    public static function branch(): string
    {
        return Settings::get('update_branch', 'main');
    }

    private static function headers(): array
    {
        $t = Settings::get('update_token');
        return $t !== '' ? ['Authorization' => 'Bearer ' . $t] : [];
    }

    /** @return array{local: string, remote: ?string, available: bool, error: ?string} */
    public static function check(): array
    {
        $local = app_version();
        $url = (string) (env('UPDATE_RAW_BASE') ?: 'https://raw.githubusercontent.com') . '/' . self::repo() . '/' . rawurlencode(self::branch()) . '/version.txt';
        if (Settings::get('update_token') !== '') {
            $url = 'https://api.github.com/repos/' . self::repo() . '/contents/version.txt?ref=' . rawurlencode(self::branch());
        }
        $r = Http::request('GET', $url, null, self::headers() + (Settings::get('update_token') !== '' ? ['Accept' => 'application/vnd.github.raw'] : []), 15);
        if ($r['status'] !== 200) {
            return ['local' => $local, 'remote' => null, 'available' => false, 'error' => 'No s\'ha pogut consultar GitHub (' . ($r['status'] ?: $r['error']) . ').'];
        }
        $remote = trim($r['body']);
        if (!preg_match('/^\d+\.\d+\.\d+$/', $remote)) {
            return ['local' => $local, 'remote' => null, 'available' => false, 'error' => 'La versió remota no és vàlida.'];
        }
        Settings::set('update_last_check', now_utc());
        Settings::set('update_remote_version', $remote);
        return ['local' => $local, 'remote' => $remote, 'available' => version_compare($remote, $local, '>'), 'error' => null];
    }

    /** @return array<int, array{label:string, ok:bool, detail:string}> */
    public static function preflight(): array
    {
        $checks = [];
        $checks[] = ['label' => 'Extensió ZIP de PHP', 'ok' => class_exists(\ZipArchive::class), 'detail' => ''];
        $checks[] = ['label' => 'Es pot escriure a la carpeta de la plataforma', 'ok' => is_writable(BASE_PATH) && is_writable(BASE_PATH . '/src') && is_writable(BASE_PATH . '/public'), 'detail' => BASE_PATH];
        $checks[] = ['label' => 'Es pot escriure a storage/', 'ok' => is_writable(BASE_PATH . '/storage'), 'detail' => ''];
        $free = @disk_free_space(BASE_PATH);
        $checks[] = ['label' => 'Espai lliure (mínim 100 MB)', 'ok' => $free === false || $free > self::MIN_FREE, 'detail' => $free === false ? 'desconegut' : round($free / 1048576) . ' MB'];
        return $checks;
    }

    public static function inMaintenance(): bool
    {
        return is_file(BASE_PATH . '/storage/maintenance.flag');
    }

    /** @return array{ok: bool, from: string, to: string, log: string} */
    public function apply(): array
    {
        @set_time_limit(900);
        @ignore_user_abort(true);
        $from = app_version();
        $work = BASE_PATH . '/storage/updates';
        $stamp = gmdate('Ymd-His');
        $swapped = [];
        $prev = $work . '/prev-' . $stamp;
        $new = $work . '/new-' . $stamp;
        try {
            foreach (self::preflight() as $c) {
                if (!$c['ok']) {
                    throw new \RuntimeException('Comprovació prèvia fallida: ' . $c['label'] . ($c['detail'] ? ' (' . $c['detail'] . ')' : ''));
                }
            }
            $this->step('Mode manteniment activat.');
            file_put_contents(BASE_PATH . '/storage/maintenance.flag', $stamp);

            $this->step('Còpia de seguretat de la base de dades: ' . Backup::create('ota'));

            $zipFile = $work . '/update-' . $stamp . '.zip';
            $url = (string) (env('UPDATE_API_BASE') ?: 'https://api.github.com') . '/repos/' . self::repo() . '/zipball/' . rawurlencode(self::branch());
            $this->step('Descarregant ' . self::repo() . '@' . self::branch() . '…');
            $fh = fopen($zipFile, 'wb');
            $ch = curl_init($url);
            $hdr = ['User-Agent: SoporticMailing/' . $from];
            foreach (self::headers() as $k => $v) {
                $hdr[] = $k . ': ' . $v;
            }
            curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300, CURLOPT_HTTPHEADER => $hdr]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            fclose($fh);
            if ($status !== 200 || filesize($zipFile) < 1000) {
                throw new \RuntimeException('No s\'ha pogut descarregar l\'actualització (HTTP ' . $status . ').');
            }

            $zip = new \ZipArchive();
            if ($zip->open($zipFile) !== true || !$zip->extractTo($new)) {
                throw new \RuntimeException('No s\'ha pogut descomprimir l\'actualització.');
            }
            $zip->close();
            @unlink($zipFile);
            $root = glob($new . '/*', GLOB_ONLYDIR)[0] ?? '';
            if ($root === '' || !is_file($root . '/version.txt') || !is_file($root . '/src/bootstrap.php') || !is_file($root . '/public/index.php')) {
                throw new \RuntimeException('El paquet descarregat no és la plataforma de Mailing.');
            }
            $to = trim((string) file_get_contents($root . '/version.txt'));
            $this->step('Paquet correcte: versió ' . $to . '.');

            // The install lock and local config live inside config/.
            if (is_file(BASE_PATH . '/config/installed.lock')) {
                @mkdir($root . '/config', 0775, true);
                copy(BASE_PATH . '/config/installed.lock', $root . '/config/installed.lock');
            }
            mkdir($prev, 0775, true);
            foreach (scandir($root) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || in_array($entry, self::PRESERVE, true)) {
                    continue;
                }
                $target = BASE_PATH . '/' . $entry;
                if (file_exists($target)) {
                    if (!rename($target, $prev . '/' . $entry)) {
                        throw new \RuntimeException('No s\'ha pogut moure ' . $entry . '.');
                    }
                }
                $swapped[] = $entry;
                if (!rename($root . '/' . $entry, $target)) {
                    throw new \RuntimeException('No s\'ha pogut col·locar ' . $entry . '.');
                }
            }
            $this->step('Fitxers substituïts (' . count($swapped) . ' elements). Els anteriors són a storage/updates/prev-' . $stamp . '.');

            // Migrations of the new version (its files are already in place).
            $m = Migrations::run();
            if (!$m['ok']) {
                throw new \RuntimeException('Ha fallat la migració ' . ($m['failed'] ?? '') . ': ' . ($m['error'] ?? ''));
            }
            $this->step($m['ran'] ? 'Migracions aplicades: ' . implode(', ', $m['ran']) . '.' : 'No calia cap migració.');

            $this->cleanup($new, $work, $prev);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            @unlink(BASE_PATH . '/storage/maintenance.flag');
            $this->step('Actualització completada: ' . $from . ' → ' . $to . '.');
            $this->record($from, $to, true);
            return ['ok' => true, 'from' => $from, 'to' => $to, 'log' => implode("\n", $this->log)];
        } catch (\Throwable $e) {
            $this->step('ERROR: ' . $e->getMessage());
            if ($swapped) {
                foreach (array_reverse($swapped) as $entry) {
                    $target = BASE_PATH . '/' . $entry;
                    if (file_exists($prev . '/' . $entry)) {
                        self::rrmdir($target);
                        @rename($prev . '/' . $entry, $target);
                    }
                }
                $this->step('S\'han restaurat els fitxers anteriors. Si una migració ha quedat a mitges, restaura la còpia de la base de dades de storage/backups.');
            }
            if (is_dir($new)) {
                self::rrmdir($new);
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            @unlink(BASE_PATH . '/storage/maintenance.flag');
            $this->record($from, $from, false);
            return ['ok' => false, 'from' => $from, 'to' => $from, 'log' => implode("\n", $this->log)];
        }
    }

    private function step(string $line): void
    {
        $this->log[] = '[' . gmdate('H:i:s') . '] ' . $line;
    }

    private function record(string $from, string $to, bool $ok): void
    {
        $file = BASE_PATH . '/storage/updates/history.json';
        $h = json_decode((string) @file_get_contents($file), true) ?: [];
        array_unshift($h, ['at' => now_utc(), 'from' => $from, 'to' => $to, 'ok' => $ok, 'log' => implode("\n", $this->log)]);
        file_put_contents($file, json_encode(array_slice($h, 0, 20), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        logger('OTA ' . ($ok ? 'ok' : 'error') . ' ' . $from . ' → ' . $to, [], 'update');
    }

    public static function history(): array
    {
        return json_decode((string) @file_get_contents(BASE_PATH . '/storage/updates/history.json'), true) ?: [];
    }

    /** Keeps only the latest previous tree. */
    private function cleanup(string $new, string $work, string $keep): void
    {
        self::rrmdir($new);
        foreach (glob($work . '/prev-*', GLOB_ONLYDIR) ?: [] as $d) {
            if ($d !== $keep) {
                self::rrmdir($d);
            }
        }
    }

    public static function rrmdir(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                self::rrmdir($path . '/' . $e);
            }
        }
        @rmdir($path);
    }
}
