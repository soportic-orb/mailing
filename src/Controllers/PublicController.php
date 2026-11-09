<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\DB;
use Mailing\Core\View;
use Mailing\Editor\Brand;
use Mailing\Editor\MergeTags;
use Mailing\Services\Forms;
use Mailing\Services\Links;
use Mailing\Services\Webhooks;

/** Pages reached from the emails: images, unsubscribe (with one-click) and "view in browser". */
final class PublicController
{
    private const T = [
        'ca' => ['title' => 'Donar-se de baixa', 'ask' => 'Vols deixar de rebre correus de %s a %s?', 'btn' => 'Sí, dona\'m de baixa', 'done' => 'Fet. Ja no rebràs més correus de %s a %s.', 'already' => 'Aquesta adreça ja estava donada de baixa.', 'test' => 'Aquest és l\'enllaç de baixa d\'un correu de prova: no fa res.', 'bad' => 'Aquest enllaç no és vàlid o ha caducat.', 'undo' => 'T\'has equivocat? Escriu-nos i et tornarem a subscriure.', 'ask_list' => 'Vols deixar de rebre els correus de la llista %s a %s?', 'btn_list' => 'Dona\'m de baixa d\'aquesta llista', 'btn_all' => 'Dona\'m de baixa de tots els correus de %s', 'done_list' => 'Fet. Ja no rebràs els correus de la llista %s. Si vols deixar de rebre-ho tot de %s:'],
        'es' => ['title' => 'Darse de baja', 'ask' => '¿Quieres dejar de recibir correos de %s en %s?', 'btn' => 'Sí, darme de baja', 'done' => 'Hecho. Ya no recibirás más correos de %s en %s.', 'already' => 'Esta dirección ya estaba dada de baja.', 'test' => 'Este es el enlace de baja de un correo de prueba: no hace nada.', 'bad' => 'Este enlace no es válido o ha caducado.', 'undo' => '¿Te has equivocado? Escríbenos y te volveremos a suscribir.', 'ask_list' => '¿Quieres dejar de recibir los correos de la lista %s en %s?', 'btn_list' => 'Darme de baja de esta lista', 'btn_all' => 'Darme de baja de todos los correos de %s', 'done_list' => 'Hecho. Ya no recibirás los correos de la lista %s. Si quieres dejar de recibir todo de %s:'],
        'en' => ['title' => 'Unsubscribe', 'ask' => 'Do you want to stop receiving emails from %s at %s?', 'btn' => 'Yes, unsubscribe me', 'done' => 'Done. You will no longer receive emails from %s at %s.', 'already' => 'This address was already unsubscribed.', 'test' => 'This is the unsubscribe link of a test email: it does nothing.', 'bad' => 'This link is not valid or has expired.', 'undo' => 'Made a mistake? Write to us and we will subscribe you again.', 'ask_list' => 'Do you want to stop receiving the emails of the list %s at %s?', 'btn_list' => 'Unsubscribe me from this list', 'btn_all' => 'Unsubscribe me from all emails of %s', 'done_list' => 'Done. You will no longer receive the emails of the list %s. To stop receiving everything from %s:'],
        'fr' => ['title' => 'Se désabonner', 'ask' => 'Voulez-vous ne plus recevoir les e-mails de %s à %s ?', 'btn' => 'Oui, me désabonner', 'done' => 'C\'est fait. Vous ne recevrez plus d\'e-mails de %s à %s.', 'already' => 'Cette adresse était déjà désabonnée.', 'test' => 'Ceci est le lien de désabonnement d\'un e-mail de test : il ne fait rien.', 'bad' => 'Ce lien n\'est pas valide ou a expiré.', 'undo' => 'Une erreur ? Écrivez-nous et nous vous réabonnerons.', 'ask_list' => 'Voulez-vous ne plus recevoir les e-mails de la liste %s à %s ?', 'btn_list' => 'Me désabonner de cette liste', 'btn_all' => 'Me désabonner de tous les e-mails de %s', 'done_list' => 'C\'est fait. Vous ne recevrez plus les e-mails de la liste %s. Pour ne plus rien recevoir de %s :'],
    ];

    public function image(string $token): never
    {
        $img = strlen($token) === 40 ? DB::one('SELECT path, mime FROM images WHERE token = ?', [$token]) : null;
        $file = $img ? BASE_PATH . '/storage/images/' . $img['path'] : '';
        if (!$img || !is_file($file)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . $img['mime']);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    /** @return array{0: ?array, 1: ?array, 2: string} message, project, language */
    private function context(string $token): array
    {
        $id = Links::messageId($token);
        $m = $id ? DB::one('SELECT m.*, c.project_id, c.doc FROM messages m JOIN campaigns c ON c.id = m.campaign_id WHERE m.id = ?', [$id]) : null;
        $p = $m ? DB::one('SELECT * FROM projects WHERE id = ?', [$m['project_id']]) : null;
        $lang = $m && isset(self::T[$m['language']]) ? $m['language'] : (string) ($p['default_language'] ?? 'ca');
        return [$m, $p, isset(self::T[$lang]) ? $lang : 'ca'];
    }

    private function page(?array $p, string $lang, string $html): never
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        $logo = Brand::logoUrl($p);
        echo View::render('public/page', ['logo' => $logo, 'name' => (string) ($p['name'] ?? ''), 'html' => $html, 'lang' => $lang,
            'accent' => $p['color'] ?? '#ac1966', 'title' => self::T[$lang]['title']], 'layouts/bare');
        exit;
    }

    public function unsubscribe(string $token): never
    {
        [$m, $p, $lang] = $this->context($token);
        $t = self::T[$lang];
        if ($token === 'prova') {
            $this->page(null, 'ca', '<p>' . e(self::T['ca']['test']) . '</p>');
        }
        if (!$m) {
            http_response_code(404);
            $this->page(null, $lang, '<p>' . e($t['bad']) . '</p>');
        }
        $contact = $m['contact_id'] ? DB::one('SELECT status FROM contacts WHERE id = ?', [$m['contact_id']]) : null;
        if ($contact && $contact['status'] === 'unsubscribed') {
            $this->page($p, $lang, '<h1>' . e($t['title']) . '</h1><p>' . e($t['already']) . '</p>');
        }
        $lists = Forms::messageLists($m);
        $allBtn = '<form method="post"><input type="hidden" name="scope" value="all"><button class="btn ' . ($lists ? 'secondary' : '') . '" type="submit" style="width:100%">' . e($lists ? sprintf($t['btn_all'], $p['name']) : $t['btn']) . '</button></form>';
        if ($lists) {
            $names = implode(', ', array_map(static fn ($l) => '«' . $l['name'] . '»', $lists));
            $this->page($p, $lang, '<h1>' . e($t['title']) . '</h1><p style="line-height:1.55">' . e(sprintf($t['ask_list'], $names, $m['email'])) . '</p>'
                . '<form method="post" style="margin-bottom:10px"><input type="hidden" name="scope" value="lists"><button class="btn" type="submit" style="width:100%">' . e($t['btn_list']) . '</button></form>' . $allBtn);
        }
        $this->page($p, $lang, '<h1>' . e($t['title']) . '</h1><p style="line-height:1.55">' . e(sprintf($t['ask'], $p['name'], $m['email'])) . '</p>' . $allBtn);
    }

    /** Confirmation form and RFC 8058 one-click (List-Unsubscribe-Post) from the mail client. */
    public function unsubscribePost(string $token): never
    {
        [$m, $p, $lang] = $this->context($token);
        $t = self::T[$lang];
        if (!$m) {
            http_response_code($token === 'prova' ? 200 : 404);
            $this->page(null, $lang, '<p>' . e($token === 'prova' ? $t['test'] : $t['bad']) . '</p>');
        }
        $oneClick = isset($_POST['List-Unsubscribe']);
        // From the page: the button chosen. One click from the mail client: the lists of this email (or everything if none).
        $lists = ($oneClick || ($_POST['scope'] ?? '') === 'lists') ? Forms::messageLists($m) : [];
        Webhooks::unsubscribe($m, $oneClick ? 'un sol clic (client de correu)' : 'enllaç del correu', array_column($lists, 'id'));
        if ($oneClick) {
            http_response_code(200);
            echo 'OK';
            exit;
        }
        if ($lists) {
            $names = implode(', ', array_map(static fn ($l) => '«' . $l['name'] . '»', $lists));
            $this->page($p, $lang, '<h1>' . e($t['title']) . '</h1><p style="line-height:1.55">' . e(sprintf($t['done_list'], $names, $p['name'])) . '</p>'
                . '<form method="post"><input type="hidden" name="scope" value="all"><button class="btn secondary" type="submit" style="width:100%">' . e(sprintf($t['btn_all'], $p['name'])) . '</button></form>');
        }
        $this->page($p, $lang, '<h1>' . e($t['title']) . '</h1><p style="line-height:1.55">' . e(sprintf($t['done'], $p['name'], $m['email'])) . '</p><p class="muted small">' . e($t['undo']) . '</p>');
    }

    public function view(string $token): never
    {
        [$m] = $this->context($token);
        if (!$m) {
            http_response_code(404);
            $this->page(null, 'ca', '<p>' . e(self::T['ca']['bad']) . '</p>');
        }
        $c = DB::one('SELECT html FROM campaigns WHERE id = ?', [$m['campaign_id']]);
        $html = json_decode((string) ($c['html'] ?? ''), true) ?: [];
        $contact = $m['contact_id'] ? (DB::one('SELECT * FROM contacts WHERE id = ?', [$m['contact_id']]) ?? []) : ['email' => $m['email']];
        $vars = MergeTags::vars($contact, ['unsubscribe_url' => Links::unsubscribe((int) $m['id']), 'view_url' => Links::view((int) $m['id'])]);
        header('X-Robots-Tag: noindex');
        CampaignController::htmlResponse(MergeTags::apply((string) ($html[$m['language']] ?? reset($html) ?: ''), $vars));
    }
}
