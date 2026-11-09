<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\Auth;
use Mailing\Core\DB;

final class Activity
{
    public static function log(string $action, string $detail = '', ?int $projectId = null): void
    {
        try {
            DB::insert('activity', [
                'user_id' => Auth::id(), 'project_id' => $projectId, 'action' => mb_substr($action, 0, 60),
                'detail' => mb_substr($detail, 0, 1000), 'created_at' => now_utc(),
            ]);
        } catch (\Throwable) {
        }
    }
}
