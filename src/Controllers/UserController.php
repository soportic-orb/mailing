<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\Auth;
use Mailing\Core\DB;
use Mailing\Services\Activity;

final class UserController extends Controller
{
    public function index(): never
    {
        $this->view('users/index', [
            'title' => 'Usuaris',
            'users' => DB::all("SELECT u.*, (SELECT GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') FROM user_projects up JOIN projects p ON p.id = up.project_id WHERE up.user_id = u.id) AS project_names FROM users u ORDER BY u.active DESC, u.name"),
        ]);
    }

    public function edit(?string $id = null): never
    {
        $u = $id ? DB::one('SELECT * FROM users WHERE id = ?', [(int) $id]) : null;
        if ($id && !$u) {
            abort(404);
        }
        $this->view('users/form', [
            'title' => $u ? $u['name'] : 'Usuari nou', 'u' => $u,
            'projects' => DB::all('SELECT id, name FROM projects WHERE archived = 0 ORDER BY name'),
            'mine' => $u ? array_map('intval', DB::column('SELECT project_id FROM user_projects WHERE user_id = ?', [$u['id']])) : [],
        ]);
    }

    public function save(?string $id = null): never
    {
        $u = $id ? DB::one('SELECT * FROM users WHERE id = ?', [(int) $id]) : null;
        $name = str_input('name', 120);
        $email = normalize_email(str_input('email', 190));
        $role = isset(Auth::ROLES[input('role')]) ? (string) input('role') : 'editor';
        $pass = (string) input('password', '');
        if ($name === '' || !valid_email($email)) {
            $this->fail('Cal el nom i un correu vàlid.');
        }
        if (DB::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, (int) ($u['id'] ?? 0)])) {
            $this->fail('Ja hi ha un usuari amb aquest correu.');
        }
        if ((!$u || $pass !== '') && mb_strlen($pass) < 10) {
            $this->fail('La contrasenya ha de tenir almenys 10 caràcters.');
        }
        $active = input('active') === '1' ? 1 : 0;
        if ($u && (int) $u['id'] === Auth::id() && ($role !== 'admin' || !$active)) {
            $this->fail('No et pots treure a tu mateix el rol d\'administrador ni desactivar-te.');
        }
        $row = ['name' => $name, 'email' => $email, 'role' => $role, 'active' => $active];
        if ($pass !== '') {
            $row['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
        }
        if ($u) {
            DB::update('users', $row, 'id = ?', [$u['id']]);
            $uid = (int) $u['id'];
        } else {
            $uid = DB::insert('users', $row + ['created_at' => now_utc()]);
            Activity::log('user.created', $email);
        }
        DB::exec('DELETE FROM user_projects WHERE user_id = ?', [$uid]);
        foreach ($this->ids('projects') as $pid) {
            DB::exec('INSERT IGNORE INTO user_projects (user_id, project_id) VALUES (?, ?)', [$uid, $pid]);
        }
        $this->done('Usuari desat.', '/usuaris');
    }
}
