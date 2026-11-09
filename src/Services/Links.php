<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\Crypto;

/** Signed public links of a sent message: unsubscribe (/u/…) and view online (/v/…). */
final class Links
{
    public static function token(int $messageId): string
    {
        return base_convert((string) $messageId, 10, 36) . '.' . Crypto::sign('m' . $messageId, 12);
    }

    public static function messageId(string $token): ?int
    {
        if (!preg_match('/^([0-9a-z]{1,10})\.([A-Za-z0-9_-]{12})$/', $token, $m)) {
            return null;
        }
        $id = (int) base_convert($m[1], 36, 10);
        return hash_equals(Crypto::sign('m' . $id, 12), $m[2]) ? $id : null;
    }

    public static function unsubscribe(int $messageId): string
    {
        return url('u/' . self::token($messageId));
    }

    public static function view(int $messageId): string
    {
        return url('v/' . self::token($messageId));
    }
}
