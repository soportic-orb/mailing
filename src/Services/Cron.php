<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\Settings;

/**
 * Background work, every minute: send queue, scheduled campaigns, pull syncs,
 * clean-up. Run by CloudPanel's cron (`php bin/worker.php`); if the cron is
 * missing, page visits run it as a fallback (after the response is sent).
 */
final class Cron
{
    public static function run(string $via = 'cron'): array
    {
        $lock = fopen(BASE_PATH . '/storage/cache/cron.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['skipped' => 'locked'];
        }
        @set_time_limit(280);
        $out = [];
        try {
            if (Updater::inMaintenance()) {
                return ['skipped' => 'maintenance'];
            }
            $out['send'] = Sender::tick();
            $out['sync'] = Sync::runDue();
            self::cleanup();
        } catch (\Throwable $e) {
            logger('Cron: ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()], 'error');
            $out['error'] = $e->getMessage();
        } finally {
            Settings::set('cron_last_run', now_utc());
            if ($via === 'cron') {
                Settings::set('cron_last_real', now_utc());
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $out;
    }

    /** Seconds since the last real cron run (null = never). */
    public static function age(): ?int
    {
        $last = Settings::get('cron_last_real');
        return $last ? time() - strtotime($last . ' UTC') : null;
    }

    /** Fallback when there is no cron: at most once every 2 minutes, after the page is served. */
    public static function maybeInline(): void
    {
        $last = Settings::get('cron_last_run');
        if ($last && time() - strtotime($last . ' UTC') < 120) {
            return;
        }
        $age = self::age();
        if ($age !== null && $age < 300) {
            return; // the real cron works
        }
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                self::run('web');
            } catch (\Throwable) {
            }
        });
    }

    private static function cleanup(): void
    {
        foreach (glob(BASE_PATH . '/storage/imports/*') ?: [] as $f) {
            if (is_file($f) && basename($f) !== '.gitignore' && filemtime($f) < time() - 2 * 86400) {
                @unlink($f);
            }
        }
    }

    public static function line(): string
    {
        $php = PHP_BINARY && !str_contains(PHP_BINARY, 'fpm') ? PHP_BINARY : 'php';
        return '* * * * * ' . $php . ' ' . BASE_PATH . '/bin/worker.php >/dev/null 2>&1';
    }
}
