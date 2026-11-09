<?php

declare(strict_types=1);

namespace Mailing\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = str_starts_with((string) env('APP_URL', ''), 'https://') || ($_SERVER['HTTPS'] ?? '') === 'on';
        session_name('mailing_s');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => $https, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '28800');
        session_start();
        $now = time();
        if (isset($_SESSION['_last']) && $now - (int) $_SESSION['_last'] > 28800) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last'] = $now;
        // Old input is kept for one request only.
        if (isset($_SESSION['_old_next'])) {
            $_SESSION['_old'] = $_SESSION['_old_next'];
            unset($_SESSION['_old_next']);
        } else {
            unset($_SESSION['_old']);
        }
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            session_destroy();
        }
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $v = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $v;
    }

    /** Keep the submitted form for the next request (validation errors). */
    public static function keepInput(): void
    {
        $data = $_POST;
        unset($data['_csrf'], $data['password'], $data['password2']);
        $_SESSION['_old_next'] = $data;
    }
}
