<?php

declare(strict_types=1);

namespace Mailing\Core;

/**
 * AES-256-GCM for secrets stored in the database (API keys, remote DB
 * passwords). Key: APP_KEY in .env ("base64:..." 32 bytes, made by the installer).
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    private static function key(): string
    {
        $k = (string) env('APP_KEY', '');
        if (str_starts_with($k, 'base64:')) {
            $d = base64_decode(substr($k, 7), true);
            if ($d !== false && strlen($d) === 32) {
                return $d;
            }
        }
        if ($k === '') {
            throw new \RuntimeException('APP_KEY no és definit al fitxer .env.');
        }
        return hash('sha256', $k, true);
    }

    public static function newKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new \RuntimeException('No s\'ha pogut xifrar.');
        }
        return base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(?string $payload): ?string
    {
        $raw = base64_decode((string) $payload, true);
        if ($raw === false || strlen($raw) <= 28) {
            return null;
        }
        $pt = openssl_decrypt(substr($raw, 28), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $pt === false ? null : $pt;
    }

    /** Keyed signature for public links (unsubscribe, view online). */
    public static function sign(string $data, int $len = 16): string
    {
        return substr(rtrim(strtr(base64_encode(hash_hmac('sha256', $data, self::key(), true)), '+/', '-_'), '='), 0, $len);
    }
}
