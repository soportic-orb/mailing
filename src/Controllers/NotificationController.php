<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\Auth;
use Mailing\Core\DB;
use Mailing\Services\Notify;
use Mailing\Services\WebPush;

/** The bell (notices), the devices subscribed to push and each user's choices. */
final class NotificationController extends Controller
{
    public function index(): never
    {
        $uid = (int) Auth::id();
        $page = $this->paginate('SELECT COUNT(*) FROM notifications WHERE user_id = ?',
            'SELECT n.*, p.name AS project FROM notifications n LEFT JOIN projects p ON p.id = n.project_id WHERE n.user_id = ? ORDER BY n.id DESC', [$uid], 40);
        $this->view('notifications/index', ['title' => 'Avisos', 'page' => $page]);
    }

    /** Opens a notice: marks it read and goes to its page. */
    public function open(string $id): never
    {
        $n = DB::one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [(int) $id, Auth::id()]);
        if (!$n) {
            redirect('/avisos');
        }
        if (!$n['read_at']) {
            DB::update('notifications', ['read_at' => now_utc()], 'id = ?', [$n['id']]);
        }
        $url = (string) $n['url'];
        redirect(str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : '/avisos');
    }

    public function readAll(): never
    {
        DB::exec('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL', [Auth::id()]);
        $this->done('Tots els avisos marcats com a llegits.', '/avisos');
    }

    /** Saves the push subscription of this device (JSON from PushManager). */
    public function subscribe(): never
    {
        if (!Notify::enabled()) {
            json_response(['ok' => false, 'message' => 'Els avisos push no estan activats en aquesta plataforma.'], 422);
        }
        $endpoint = (string) input('endpoint', '');
        $keys = (array) input('keys', []);
        $p256dh = (string) ($keys['p256dh'] ?? '');
        $auth = (string) ($keys['auth'] ?? '');
        if (strlen($endpoint) > 1000 || !Notify::validEndpoint($endpoint) || strlen(WebPush::unb64($p256dh)) !== 65 || strlen(WebPush::unb64($auth)) !== 16) {
            json_response(['ok' => false, 'message' => 'La subscripció del dispositiu no és vàlida.'], 422);
        }
        $hash = hash('sha256', $endpoint);
        $device = str_input('device', 120);
        $row = ['user_id' => Auth::id(), 'endpoint' => $endpoint, 'endpoint_hash' => $hash, 'p256dh' => $p256dh, 'auth' => $auth, 'device' => $device, 'failures' => 0];
        if (DB::value('SELECT id FROM push_subscriptions WHERE endpoint_hash = ?', [$hash])) {
            DB::update('push_subscriptions', $row, 'endpoint_hash = ?', [$hash]);
        } else {
            DB::insert('push_subscriptions', $row + ['created_at' => now_utc()]);
        }
        json_response(['ok' => true, 'message' => 'Avisos activats en aquest dispositiu.']);
    }

    public function unsubscribe(): never
    {
        DB::exec('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?', [hash('sha256', (string) input('endpoint', '')), Auth::id()]);
        json_response(['ok' => true, 'message' => 'Avisos desactivats en aquest dispositiu.']);
    }

    public function prefs(): never
    {
        Notify::savePrefs((int) Auth::id(), array_map('strval', (array) input('kinds', [])));
        $this->done('Preferències d\'avisos desades.', '/perfil#avisos');
    }

    /** A test notice to every device of the user, sent now. */
    public function test(): never
    {
        if (!Notify::enabled()) {
            $this->fail('Els avisos push no estan activats en aquesta plataforma.');
        }
        DB::insert('notifications', ['user_id' => Auth::id(), 'kind' => 'system', 'title' => 'Avís de prova', 'body' => 'Si veus aquest missatge, els avisos funcionen en aquest dispositiu.',
            'url' => '/avisos', 'tag' => 'test-' . Auth::id(), 'created_at' => now_utc()]);
        $r = Notify::flush(20);
        $devices = (int) DB::value('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [Auth::id()]);
        $this->done($devices ? 'Avís de prova enviat a ' . $r['pushed'] . ' de ' . $devices . ' dispositius.' : 'Avís creat, però no tens cap dispositiu amb els avisos activats.', '/perfil#avisos');
    }
}
