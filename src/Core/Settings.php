<?php

declare(strict_types=1);

namespace Mailing\Core;

/** Key/value settings in the database; secrets are stored encrypted. */
final class Settings
{
    private static ?array $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (DB::all('SELECT k, v, secret FROM settings') as $r) {
                    self::$cache[$r['k']] = $r['secret'] ? (Crypto::decrypt($r['v']) ?? '') : (string) $r['v'];
                }
            } catch (\Throwable) {
                // Before installation there is no table.
            }
        }
        $v = self::$cache[$key] ?? '';
        return $v === '' ? $default : $v;
    }

    public static function set(string $key, ?string $value, bool $secret = false): void
    {
        $value = (string) $value;
        DB::exec('REPLACE INTO settings (k, v, secret) VALUES (?, ?, ?)', [$key, $secret && $value !== '' ? Crypto::encrypt($value) : $value, $secret ? 1 : 0]);
        self::$cache = null;
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== '';
    }

    /** "sk-…abcd": shows a secret is set without revealing it. */
    public static function masked(string $key): string
    {
        $v = self::get($key);
        return $v === '' ? '' : mb_substr($v, 0, 3) . '…' . mb_substr($v, -4);
    }
}
