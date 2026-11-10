<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\Http;
use Mailing\Core\Settings;

/**
 * Web Push without libraries: VAPID (RFC 8292, ES256 JWT) and the
 * aes128gcm payload encryption of RFC 8291, with PHP's OpenSSL. The VAPID
 * key pair is created on first use; the private key is stored with Crypto.
 */
final class WebPush
{
    private const SPKI_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** Public VAPID key (base64url of the raw P-256 point) for PushManager.subscribe(). */
    public static function publicKey(): string
    {
        self::ensureKeys();
        return Settings::get('vapid_public');
    }

    public static function ensureKeys(): void
    {
        if (Settings::has('vapid_public') && Settings::has('vapid_private')) {
            return;
        }
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$key || !openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('OpenSSL no pot crear les claus dels avisos push.');
        }
        Settings::set('vapid_private', $pem, true);
        Settings::set('vapid_public', self::b64(self::rawPublic($key)));
    }

    /** Uncompressed point (65 bytes) of an EC key. */
    public static function rawPublic(\OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key);
        return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** PEM public key from a raw uncompressed P-256 point. */
    public static function pemFromRaw(string $raw): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::SPKI_P256) . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Sends one notice. Returns the push service's status: 201 ok,
     * 404/410 the subscription is gone.
     *
     * @param array{endpoint: string, p256dh: string, auth: string} $sub
     */
    public static function send(array $sub, array $payload, string $topic = '', int $ttl = 86400, string $urgency = 'normal'): int
    {
        self::ensureKeys();
        $body = self::encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::unb64($sub['p256dh']), self::unb64($sub['auth']));
        $headers = [
            'TTL' => (string) $ttl,
            'Urgency' => $urgency,
            'Content-Type' => 'application/octet-stream',
            'Content-Encoding' => 'aes128gcm',
            'Authorization' => 'vapid t=' . self::jwt($sub['endpoint']) . ', k=' . Settings::get('vapid_public'),
        ];
        if ($topic !== '') {
            $headers['Topic'] = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $topic) ?? '', 0, 32);
        }
        $r = Http::request('POST', $sub['endpoint'], $body, $headers, 15);
        return $r['status'];
    }

    /** ES256 JWT for the endpoint's origin. */
    public static function jwt(string $endpoint): string
    {
        $p = parse_url($endpoint);
        $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $sub = (string) (env('APP_URL') ?: 'https://mailing.soportic.es');
        if (!str_starts_with($sub, 'https://')) {
            $sub = 'mailto:avisos@soportic.es';
        }
        $data = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . self::b64(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private(Settings::get('vapid_private'));
        if (!$key || !openssl_sign($data, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('No s\'ha pogut signar l\'avís push.');
        }
        return $data . '.' . self::b64(self::derToRaw($der));
    }

    /** ASN.1 ECDSA signature → r||s (64 bytes). */
    public static function derToRaw(string $der): string
    {
        $pos = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = substr($der, $pos + 2, $len);
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    /** RFC 8291 aes128gcm content coding (one record). */
    public static function encrypt(string $plain, string $uaPublic, string $authSecret): string
    {
        $local = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $asPublic = self::rawPublic($local);
        $shared = openssl_pkey_derive(openssl_pkey_get_public(self::pemFromRaw($uaPublic)), $local);
        if ($shared === false) {
            throw new \RuntimeException('La clau del dispositiu no és vàlida.');
        }
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $cipher = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }
}
