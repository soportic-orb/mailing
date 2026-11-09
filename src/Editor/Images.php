<?php

declare(strict_types=1);

namespace Mailing\Editor;

use Mailing\Core\DB;

/**
 * Images for emails, stored under storage/images and served publicly by a
 * secret token (/img/{token}). Checked to be real images, reduced to 1200 px
 * wide (twice the 600 px email, for retina) and WebP converted (Outlook does
 * not show it). Ported from the Tauler (MailingImages).
 */
final class Images
{
    public const MAX_KB = 8192;
    public const MAX_WIDTH = 1200;

    public static function publicUrl(string $src, string $logo = ''): ?string
    {
        if ($src === 'brand:logo') {
            return $logo !== '' ? $logo : null;
        }
        if (str_starts_with($src, 'img:')) {
            return url('img/' . substr($src, 4));
        }
        return preg_match('#^https://#', $src) ? $src : null;
    }

    /**
     * @return array{token: string, width: int, height: int, mime: string}
     */
    public static function storeBytes(string $data, string $name, ?int $projectId, ?int $userId): array
    {
        if ($data === '' || strlen($data) > self::MAX_KB * 1024) {
            throw new \RuntimeException('«' . $name . '» ocupa massa (màxim ' . intdiv(self::MAX_KB, 1024) . ' MB).');
        }
        $info = @getimagesizefromstring($data);
        $types = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_GIF => 'image/gif', IMAGETYPE_WEBP => 'image/webp'];
        if (!$info || !isset($types[$info[2]])) {
            throw new \RuntimeException('«' . $name . '» no és una imatge JPG, PNG, GIF o WebP.');
        }
        [$width, $height] = $info;
        $mime = $types[$info[2]];
        if ($mime !== 'image/gif' && ($width > self::MAX_WIDTH || $mime === 'image/webp') && function_exists('imagecreatefromstring')) {
            $converted = self::resize($data, $width, $height, $mime);
            if ($converted) {
                [$data, $mime, $width, $height] = $converted;
            }
        }
        if ($mime === 'image/webp') {
            throw new \RuntimeException('Les imatges WebP no es veuen a Outlook: desa-la com a JPG o PNG.');
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'][$mime];
        $dir = gmdate('Y/m');
        $abs = BASE_PATH . '/storage/images/' . $dir;
        if (!is_dir($abs) && !mkdir($abs, 0775, true) && !is_dir($abs)) {
            throw new \RuntimeException('No es pot escriure a storage/images.');
        }
        $file = $dir . '/' . random_token(32) . '.' . $ext;
        file_put_contents(BASE_PATH . '/storage/images/' . $file, $data);
        $token = random_token(40);
        DB::insert('images', [
            'token' => $token, 'project_id' => $projectId, 'path' => $file, 'mime' => $mime,
            'original_name' => mb_substr($name, 0, 255), 'width' => $width, 'height' => $height,
            'size' => strlen($data), 'created_by' => $userId, 'created_at' => now_utc(),
        ]);
        return ['token' => $token, 'width' => $width, 'height' => $height, 'mime' => $mime];
    }

    /** Bytes of an uploaded image ("img:token"), e.g. as an AI reference. */
    public static function bytes(string $src): ?array
    {
        if (!preg_match('/^img:([A-Za-z0-9]{40})$/', $src, $m)) {
            return null;
        }
        $img = DB::one('SELECT * FROM images WHERE token = ?', [$m[1]]);
        $file = $img ? BASE_PATH . '/storage/images/' . $img['path'] : '';
        if (!$img || !is_file($file)) {
            return null;
        }
        return ['data' => (string) file_get_contents($file), 'name' => (string) ($img['original_name'] ?: 'imatge'), 'mime' => (string) $img['mime']];
    }

    /** @return array{0: string, 1: string, 2: int, 3: int}|null */
    public static function resize(string $data, int $width, int $height, string $mime, int $max = self::MAX_WIDTH): ?array
    {
        $src = @imagecreatefromstring($data);
        if (!$src) {
            return null;
        }
        $w = min($width, $max);
        $h = max(1, (int) round($height * $w / max(1, $width)));
        $dst = imagecreatetruecolor($w, $h);
        $alpha = $mime === 'image/png' || ($mime === 'image/webp' && self::hasAlpha($src, $width, $height));
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        } else {
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $width, $height);
        ob_start();
        $alpha ? imagepng($dst, null, 7) : imagejpeg($dst, null, 86);
        $out = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $out !== '' ? [$out, $alpha ? 'image/png' : 'image/jpeg', $w, $h] : null;
    }

    private static function hasAlpha(\GdImage $img, int $w, int $h): bool
    {
        $step = max(1, (int) floor(min($w, $h) / 20));
        for ($x = 0; $x < $w; $x += $step) {
            for ($y = 0; $y < $h; $y += $step) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Image URLs of a document, for the editor ("img:…" and the logo). */
    public static function urlsIn(array $doc, string $logo): array
    {
        $out = ['brand:logo' => $logo];
        array_walk_recursive($doc, static function ($v, $k) use (&$out): void {
            if ($k === 'src' && is_string($v) && str_starts_with($v, 'img:')) {
                $out[$v] = url('img/' . substr($v, 4));
            }
        });
        return $out;
    }
}
