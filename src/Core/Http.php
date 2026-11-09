<?php

declare(strict_types=1);

namespace Mailing\Core;

/** Tiny cURL client returning [status, decoded JSON|null, raw body]. */
final class Http
{
    /**
     * @param array<string, string> $headers
     * @return array{status:int, json:mixed, body:string, error:string}
     */
    public static function request(string $method, string $url, mixed $body = null, array $headers = [], int $timeout = 30, ?string $basicAuth = null): array
    {
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        if (is_array($body) && !self::isMultipart($body)) {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $h[] = 'Content-Type: application/json';
        }
        $h[] = 'Accept: application/json';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_USERAGENT => 'SoporticMailing/' . app_version(),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        if ($basicAuth !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
        }
        if ($body !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $raw = is_string($raw) ? $raw : '';
        return ['status' => $status, 'json' => json_decode($raw, true), 'body' => $raw, 'error' => $err];
    }

    private static function isMultipart(array $body): bool
    {
        foreach ($body as $v) {
            if ($v instanceof \CURLFile || $v instanceof \CURLStringFile) {
                return true;
            }
        }
        return false;
    }

    /** Reads a dotted key from a decoded JSON array: get($j, 'error.message'). */
    public static function get(mixed $json, string $key, mixed $default = null): mixed
    {
        foreach (explode('.', $key) as $part) {
            if (!is_array($json) || !array_key_exists($part, $json)) {
                return $default;
            }
            $json = $json[$part];
        }
        return $json;
    }
}
