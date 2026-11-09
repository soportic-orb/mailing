<?php

declare(strict_types=1);

namespace Mailing\Core;

/** The project being worked on (chosen in the top bar, kept in the session). */
final class Context
{
    private static ?array $project = null;
    private static bool $loaded = false;

    /** @return array<int, array<string, mixed>> */
    public static function projects(): array
    {
        $ids = Auth::projectIds();
        if ($ids === null) {
            return DB::all('SELECT * FROM projects WHERE archived = 0 ORDER BY name');
        }
        if (!$ids) {
            return [];
        }
        return DB::all('SELECT * FROM projects WHERE archived = 0 AND id IN (' . DB::in($ids) . ') ORDER BY name', $ids);
    }

    public static function project(): ?array
    {
        if (self::$loaded) {
            return self::$project;
        }
        self::$loaded = true;
        $id = (int) ($_SESSION['project_id'] ?? 0);
        if ($id && Auth::canSeeProject($id)) {
            self::$project = DB::one('SELECT * FROM projects WHERE id = ? AND archived = 0', [$id]);
        }
        if (!self::$project) {
            $all = self::projects();
            self::$project = $all[0] ?? null;
            $_SESSION['project_id'] = self::$project ? (int) self::$project['id'] : null;
        }
        return self::$project;
    }

    public static function id(): int
    {
        return (int) (self::project()['id'] ?? 0);
    }

    public static function switchTo(int $id): bool
    {
        if (!Auth::canSeeProject($id) || !DB::one('SELECT id FROM projects WHERE id = ? AND archived = 0', [$id])) {
            return false;
        }
        $_SESSION['project_id'] = $id;
        self::$loaded = false;
        return true;
    }

    public static function reset(): void
    {
        self::$loaded = false;
        self::$project = null;
    }
}
