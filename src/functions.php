<?php

declare(strict_types=1);

use Mailing\Core\Csrf;
use Mailing\Core\Session;

/** Escape for HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Read an environment variable (from .env). */
function env(string $key, mixed $default = null): mixed
{
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null) {
        return $default;
    }
    return match (strtolower((string) $v)) {
        'true' => true,
        'false' => false,
        'null' => null,
        default => $v,
    };
}

/** Absolute public URL of a path inside the platform. */
function url(string $path = ''): string
{
    $base = rtrim((string) env('APP_URL', ''), '/');
    if ($base === '') {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $base . '/' . ltrim($path, '/');
}

/** Relative path (for links inside the app). */
function path(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

function redirect(string $to, int $code = 302): never
{
    header('Location: ' . $to, true, $code);
    exit;
}

function back(): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '/';
    $host = parse_url($ref, PHP_URL_HOST);
    redirect($host === null || $host === ($_SERVER['HTTP_HOST'] ?? '') ? $ref : '/');
}

function json_response(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function abort(int $code, string $message = ''): never
{
    http_response_code($code);
    if (wants_json()) {
        json_response(['ok' => false, 'message' => $message ?: 'Error ' . $code], $code);
    }
    echo Mailing\Core\View::render('errors/error', ['code' => $code, 'message' => $message], 'layouts/bare');
    exit;
}

function wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}

function csrf_field(): string
{
    return Csrf::field();
}

function flash(string $type, string $message): void
{
    Session::flash('msg', ['type' => $type, 'text' => $message]);
}

/** Old form value (after a validation error) or the default. */
function old(string $key, mixed $default = ''): mixed
{
    $old = $_SESSION['_old'] ?? [];
    return $old[$key] ?? $default;
}

/** Request input: POST, then GET, then JSON body. */
function input(string $key, mixed $default = null): mixed
{
    static $json = null;
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    if ($json === null) {
        $json = [];
        if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $json = is_array($decoded) ? $decoded : [];
        }
    }
    return $json[$key] ?? $default;
}

function str_input(string $key, int $max = 255): string
{
    $v = input($key, '');
    return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
}

function int_input(string $key, int $default = 0): int
{
    $v = input($key);
    return is_numeric($v) ? (int) $v : $default;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Local timezone of the platform (Europe/Madrid by default). */
function app_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone((string) (env('APP_TIMEZONE') ?: 'Europe/Madrid'));
}

/** UTC "Y-m-d H:i:s" → local DateTimeImmutable. */
function local_dt(?string $utc): ?DateTimeImmutable
{
    if (!$utc) {
        return null;
    }
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(app_tz());
}

/** Local date + time → UTC "Y-m-d H:i:s". */
function to_utc(string $date, string $time = '00:00'): ?string
{
    try {
        $dt = new DateTimeImmutable(trim($date . ' ' . ($time ?: '00:00')), app_tz());
    } catch (Throwable) {
        return null;
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

const MONTHS_CA = ['gener', 'febrer', 'març', 'abril', 'maig', 'juny', 'juliol', 'agost', 'setembre', 'octubre', 'novembre', 'desembre'];
const MONTHS_SHORT_CA = ['gen.', 'febr.', 'març', 'abr.', 'maig', 'juny', 'jul.', 'ag.', 'set.', 'oct.', 'nov.', 'des.'];
const DAYS_CA = ['dilluns', 'dimarts', 'dimecres', 'dijous', 'divendres', 'dissabte', 'diumenge'];

/** "12 de març de 2026, 09:30" (local time). */
function fmt_dt(?string $utc, bool $time = true): string
{
    $d = local_dt($utc);
    if (!$d) {
        return '—';
    }
    $m = MONTHS_CA[(int) $d->format('n') - 1];
    $de = preg_match('/^[aeiou]/', $m) ? "d'" : 'de ';
    return $d->format('j') . ' ' . $de . $m . ' de ' . $d->format('Y') . ($time ? ', ' . $d->format('H:i') : '');
}

function fmt_short(?string $utc): string
{
    $d = local_dt($utc);
    return $d ? $d->format('j') . ' ' . MONTHS_SHORT_CA[(int) $d->format('n') - 1] . ' ' . $d->format('Y H:i') : '—';
}

function fmt_n(int|float|null $n, int $dec = 0): string
{
    return number_format((float) $n, $dec, ',', '.');
}

function pct(int|float $part, int|float $total, int $dec = 1): string
{
    return $total > 0 ? fmt_n($part * 100 / $total, $dec) . ' %' : '—';
}

function ago(?string $utc): string
{
    if (!$utc) {
        return 'mai';
    }
    $s = time() - strtotime($utc . ' UTC');
    return match (true) {
        $s < 60 => 'ara mateix',
        $s < 3600 => 'fa ' . intdiv($s, 60) . ' min',
        $s < 86400 => 'fa ' . intdiv($s, 3600) . ' h',
        $s < 86400 * 30 => 'fa ' . intdiv($s, 86400) . ' dies',
        default => fmt_dt($utc, false),
    };
}

function valid_email(string $email): bool
{
    return mb_strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function random_token(int $len = 40): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, 61)];
    }
    return $out;
}

function app_version(): string
{
    return trim((string) @file_get_contents(BASE_PATH . '/version.txt')) ?: '0.0.0';
}

function logger(string $message, array $context = [], string $channel = 'app'): void
{
    $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . $channel . ': ' . $message
        . ($context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '') . "\n";
    @file_put_contents(BASE_PATH . '/storage/logs/' . $channel . '-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
}

/** Platform icon (Tabler "mail-share"), drawn in the current text colour. */
function brand_icon(int $size = 20): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M13 19h-8a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v6"/><path d="M3 7l9 6l9 -6"/><path d="M16 22l5 -5"/><path d="M21 21.5v-4.5h-4.5"/></svg>';
}

/** Favicon; with $pwa, also what makes the platform installable (not on the public pages). */
function favicon_tag(bool $pwa = false): string
{
    $icon = '<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg?v=' . e(app_version()) . '">';
    if (!$pwa) {
        return $icon;
    }
    return $icon
        . '<link rel="manifest" href="/manifest.json">'
        . '<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">'
        . '<meta name="theme-color" content="#ffffff">'
        . '<meta name="mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-title" content="Mailing">'
        . '<meta name="apple-mobile-web-app-status-bar-style" content="default">';
}
