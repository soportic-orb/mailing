<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\Auth;
use Mailing\Core\DB;
use Mailing\Core\Session;

final class AuthController extends Controller
{
    public function form(): never
    {
        if (Auth::check()) {
            redirect('/');
        }
        $this->view('auth/login', ['title' => 'Inicia la sessió'], 'layouts/bare');
    }

    public function login(): never
    {
        // Slow down brute force: max 8 attempts per 10 minutes per session and IP.
        $key = 'login_' . md5(($_SERVER['REMOTE_ADDR'] ?? '') . 'x');
        $tries = array_filter((array) ($_SESSION[$key] ?? []), static fn ($t) => $t > time() - 600);
        if (count($tries) >= 8) {
            $this->fail('Massa intents. Espera uns minuts i torna-ho a provar.', '/login');
        }
        if (!Auth::attempt(str_input('email', 190), (string) input('password', ''))) {
            $tries[] = time();
            $_SESSION[$key] = $tries;
            usleep(400000);
            $this->fail('El correu o la contrasenya no són correctes.', '/login');
        }
        unset($_SESSION[$key]);
        $to = (string) ($_SESSION['_intended'] ?? '/');
        unset($_SESSION['_intended']);
        redirect(str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/');
    }

    public function logout(): never
    {
        Auth::logout();
        Session::start();
        flash('ok', 'Has tancat la sessió.');
        redirect('/login');
    }

    public function profile(): never
    {
        $this->view('auth/profile', ['title' => 'El meu perfil', 'user' => Auth::user()]);
    }

    public function saveProfile(): never
    {
        $name = str_input('name', 120);
        if ($name === '') {
            $this->fail('Escriu el teu nom.');
        }
        $set = ['name' => $name];
        $new = (string) input('password', '');
        if ($new !== '') {
            if (!password_verify((string) input('current', ''), (string) Auth::user()['password_hash'])) {
                $this->fail('La contrasenya actual no és correcta.');
            }
            if (mb_strlen($new) < 10) {
                $this->fail('La contrasenya nova ha de tenir almenys 10 caràcters.');
            }
            if ($new !== (string) input('password2', '')) {
                $this->fail('Les dues contrasenyes noves no coincideixen.');
            }
            $set['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
        }
        DB::update('users', $set, 'id = ?', [Auth::id()]);
        $this->done('Perfil desat.', '/perfil');
    }
}
