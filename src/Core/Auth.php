<?php

declare(strict_types=1);

namespace Mailing\Core;

/** Local users. Roles: admin (everything), editor (creates and sends), viewer (reads). */
final class Auth
{
    public const ROLES = ['admin' => 'Administrador/a', 'editor' => 'Editor/a', 'viewer' => 'Només consulta'];

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = (int) ($_SESSION['uid'] ?? 0);
            self::$user = $id ? DB::one('SELECT * FROM users WHERE id = ? AND active = 1', [$id]) : null;
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return self::user() ? (int) self::user()['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function canEdit(): bool
    {
        return in_array(self::user()['role'] ?? '', ['admin', 'editor'], true);
    }

    public static function attempt(string $email, string $password): bool
    {
        $u = DB::one('SELECT * FROM users WHERE email = ? AND active = 1', [normalize_email($email)]);
        if (!$u || !password_verify($password, (string) $u['password_hash'])) {
            return false;
        }
        if (password_needs_rehash((string) $u['password_hash'], PASSWORD_DEFAULT)) {
            DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
        }
        self::login($u);
        return true;
    }

    public static function login(array $u): void
    {
        Session::regenerate();
        $_SESSION['uid'] = (int) $u['id'];
        self::$loaded = false;
        DB::update('users', ['last_login_at' => now_utc()], 'id = ?', [$u['id']]);
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
    }

    /** Project ids the user can see (null = all, for administrators). */
    public static function projectIds(): ?array
    {
        if (self::isAdmin()) {
            return null;
        }
        return array_map('intval', DB::column('SELECT project_id FROM user_projects WHERE user_id = ?', [self::id()]));
    }

    public static function canSeeProject(int $projectId): bool
    {
        $ids = self::projectIds();
        return $ids === null || in_array($projectId, $ids, true);
    }
}
