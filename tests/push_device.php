<?php
/*
 * A fake browser for tests/smoke.sh: creates a device key pair, and decrypts
 * (RFC 8291) and checks the VAPID JWT of a notice the platform pushed.
 *   php tests/push_device.php new <dir>           → prints {"p256dh","auth"}
 *   php tests/push_device.php read <dir> <file>   → prints the decrypted payload
 */
declare(strict_types=1);

$b64 = static fn (string $r): string => rtrim(strtr(base64_encode($r), '+/', '-_'), '=');
$unb64 = static fn (string $s): string => (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
$raw = static function ($key): string {
    $d = openssl_pkey_get_details($key);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
};
$pem = static fn (string $r): string => "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $r), 64, "\n") . "-----END PUBLIC KEY-----\n";

[$cmd, $dir] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'new') {
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($k, $priv);
    $auth = random_bytes(16);
    file_put_contents("$dir/device.json", json_encode(['priv' => $priv, 'auth' => $b64($auth), 'p256dh' => $b64($raw($k))]));
    echo json_encode(['p256dh' => $b64($raw($k)), 'auth' => $b64($auth)]);
    exit;
}
if ($cmd === 'read') {
    $dev = json_decode((string) file_get_contents("$dir/device.json"), true);
    $req = json_decode((string) file_get_contents($argv[3]), true);
    $body = base64_decode($req['body']);
    // VAPID: "vapid t=<jwt>, k=<key>" signed by the platform's key.
    preg_match('/t=([^,\s]+),\s*k=(\S+)/', $req['auth'], $m);
    [$h, $c, $s] = explode('.', $m[1]);
    $sig = $unb64($s);
    $int = static function (string $i): string { $i = ltrim($i, "\0"); if (ord($i[0]) > 127) { $i = "\0" . $i; } return "\x02" . chr(strlen($i)) . $i; };
    $der = $int(substr($sig, 0, 32)) . $int(substr($sig, 32));
    $der = "\x30" . chr(strlen($der)) . $der;
    $vapidOk = openssl_verify("$h.$c", $der, $pem($unb64($m[2])), OPENSSL_ALGO_SHA256) === 1;
    $claims = json_decode($unb64($c), true);
    // aes128gcm
    $salt = substr($body, 0, 16);
    $idlen = ord($body[20]);
    $asPublic = substr($body, 21, $idlen);
    $cipher = substr($body, 21 + $idlen);
    $uaPublic = $unb64($dev['p256dh']);
    $shared = openssl_pkey_derive(openssl_pkey_get_public($pem($asPublic)), openssl_pkey_get_private($dev['priv']), 32);
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $unb64($dev['auth']));
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
    echo json_encode(['vapid' => $vapidOk, 'aud' => $claims['aud'] ?? '', 'encoding' => $req['encoding'] ?? '', 'payload' => $plain === false ? null : json_decode(rtrim($plain, "\x02"), true)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
