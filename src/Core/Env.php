<?php

declare(strict_types=1);

namespace Mailing\Core;

/** Minimal .env loader (KEY=value, # comments, optional quotes). */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = self::unquote(trim($value));
            if ($key === '') {
                continue;
            }
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }

    private static function unquote(string $v): string
    {
        $n = strlen($v);
        if ($n >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[$n - 1] === $v[0]) {
            $inner = substr($v, 1, -1);
            return $v[0] === '"' ? str_replace(['\\"', '\\n'], ['"', "\n"], $inner) : $inner;
        }
        return $v;
    }

    /** Write (or rewrite) the .env file with the given values. */
    public static function write(string $path, array $values): bool
    {
        $out = "# Plataforma de Mailing — configuració (no la pugis mai al repositori)\n";
        foreach ($values as $k => $v) {
            $v = (string) $v;
            $out .= $k . '=' . (preg_match('/[\s#"\'\\\\]/', $v) || $v === '' ? '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $v) . '"' : $v) . "\n";
        }
        return file_put_contents($path, $out, LOCK_EX) !== false;
    }
}
