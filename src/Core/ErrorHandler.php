<?php

declare(strict_types=1);

namespace Mailing\Core;

/** Logs every uncaught error and shows a friendly page (details only in debug mode). */
final class ErrorHandler
{
    public static function register(): void
    {
        $debug = (bool) env('APP_DEBUG', false);
        error_reporting(E_ALL);
        ini_set('display_errors', $debug && PHP_SAPI === 'cli' ? '1' : '0');

        set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            // Deprecations (new PHP versions on the server) are logged, never fatal.
            if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) {
                logger('Deprecated: ' . $msg, ['file' => $file . ':' . $line], 'deprecated');
                return true;
            }
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });

        set_exception_handler(static function (\Throwable $e) use ($debug): void {
            logger(get_class($e) . ': ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()], 'error');
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
                exit(1);
            }
            if (!headers_sent()) {
                http_response_code(500);
            }
            $msg = $debug ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : 'S\'ha produït un error inesperat. Ha quedat registrat.';
            if (wants_json()) {
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
                return;
            }
            try {
                echo View::render('errors/error', ['code' => 500, 'message' => $msg], 'layouts/bare');
            } catch (\Throwable) {
                echo '<h1>Error</h1><p>' . e($msg) . '</p>';
            }
        });
    }
}
