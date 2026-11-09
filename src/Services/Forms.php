<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\Crypto;
use Mailing\Core\DB;
use Mailing\Editor\Brand;
use Mailing\Editor\Document;
use Mailing\Mailjet\Client;

/**
 * Public signup pages (/f/{slug}): a form that subscribes people to one or
 * more lists of a project. Optional double opt-in (confirmation email).
 * Signing up through a form is an explicit, recorded consent, so it also
 * re-subscribes someone who had unsubscribed (but not a bounced address or
 * one that complained, unless they confirm by email).
 */
final class Forms
{
    /** Fields a form can ask for besides the email (custom fields are "camp:<key>"). */
    public const BASE_FIELDS = ['first_name', 'last_name', 'language'];

    /** Minimum seconds between showing the form and sending it (bots are faster). */
    public const MIN_SECONDS = 2;
    public const MAX_PER_IP_HOUR = 10;

    public const T = [
        'ca' => ['email' => 'Correu electrònic', 'first_name' => 'Nom', 'last_name' => 'Cognoms', 'language' => 'Idioma', 'button' => 'Subscriu-me', 'consent' => 'Accepto rebre correus de %s. Em puc donar de baixa en qualsevol moment.', 'privacy' => 'Política de privacitat', 'lists' => 'Què vols rebre?', 'required' => 'obligatori',
            'ok' => 'Gràcies! T\'has subscrit correctament.', 'check' => 'Gairebé fet! T\'hem enviat un correu a %s: obre l\'enllaç per confirmar la subscripció.', 'confirmed' => 'Subscripció confirmada. Gràcies!', 'expired' => 'Aquest enllaç de confirmació no és vàlid o ja s\'ha fet servir.',
            'err_email' => 'Escriu un correu electrònic vàlid.', 'err_req' => 'Omple el camp «%s».', 'err_consent' => 'Cal que acceptis rebre els correus.', 'err_lists' => 'Tria almenys una opció.', 'err_bot' => 'No s\'ha pogut enviar. Torna a carregar la pàgina i prova-ho de nou.', 'err_many' => 'Massa intents des d\'aquesta connexió. Prova-ho més tard.', 'closed' => 'Aquest formulari ja no accepta inscripcions.',
            'mail_subject' => 'Confirma la teva subscripció a %s', 'mail_body' => 'Hola! Has demanat rebre els correus de %s. Per confirmar-ho, clica el botó:', 'mail_button' => 'Confirma la subscripció', 'mail_ignore' => 'Si no ho has demanat tu, ignora aquest correu: no rebràs res més.'],
        'es' => ['email' => 'Correo electrónico', 'first_name' => 'Nombre', 'last_name' => 'Apellidos', 'language' => 'Idioma', 'button' => 'Suscribirme', 'consent' => 'Acepto recibir correos de %s. Puedo darme de baja en cualquier momento.', 'privacy' => 'Política de privacidad', 'lists' => '¿Qué quieres recibir?', 'required' => 'obligatorio',
            'ok' => '¡Gracias! Te has suscrito correctamente.', 'check' => '¡Casi listo! Te hemos enviado un correo a %s: abre el enlace para confirmar la suscripción.', 'confirmed' => 'Suscripción confirmada. ¡Gracias!', 'expired' => 'Este enlace de confirmación no es válido o ya se ha utilizado.',
            'err_email' => 'Escribe un correo electrónico válido.', 'err_req' => 'Rellena el campo «%s».', 'err_consent' => 'Debes aceptar recibir los correos.', 'err_lists' => 'Elige al menos una opción.', 'err_bot' => 'No se ha podido enviar. Recarga la página y vuelve a intentarlo.', 'err_many' => 'Demasiados intentos desde esta conexión. Inténtalo más tarde.', 'closed' => 'Este formulario ya no acepta inscripciones.',
            'mail_subject' => 'Confirma tu suscripción a %s', 'mail_body' => '¡Hola! Has pedido recibir los correos de %s. Para confirmarlo, pulsa el botón:', 'mail_button' => 'Confirmar la suscripción', 'mail_ignore' => 'Si no lo has pedido tú, ignora este correo: no recibirás nada más.'],
        'en' => ['email' => 'Email', 'first_name' => 'First name', 'last_name' => 'Last name', 'language' => 'Language', 'button' => 'Subscribe', 'consent' => 'I agree to receive emails from %s. I can unsubscribe at any time.', 'privacy' => 'Privacy policy', 'lists' => 'What would you like to receive?', 'required' => 'required',
            'ok' => 'Thank you! You are now subscribed.', 'check' => 'Almost done! We have sent an email to %s: open the link to confirm your subscription.', 'confirmed' => 'Subscription confirmed. Thank you!', 'expired' => 'This confirmation link is not valid or has already been used.',
            'err_email' => 'Enter a valid email address.', 'err_req' => 'Fill in «%s».', 'err_consent' => 'Please agree to receive the emails.', 'err_lists' => 'Choose at least one option.', 'err_bot' => 'It could not be sent. Reload the page and try again.', 'err_many' => 'Too many attempts from this connection. Try again later.', 'closed' => 'This form no longer accepts signups.',
            'mail_subject' => 'Confirm your subscription to %s', 'mail_body' => 'Hello! You asked to receive emails from %s. To confirm, click the button:', 'mail_button' => 'Confirm subscription', 'mail_ignore' => 'If it was not you, ignore this email: you will not receive anything else.'],
        'fr' => ['email' => 'E-mail', 'first_name' => 'Prénom', 'last_name' => 'Nom', 'language' => 'Langue', 'button' => 'M\'abonner', 'consent' => 'J\'accepte de recevoir des e-mails de %s. Je peux me désabonner à tout moment.', 'privacy' => 'Politique de confidentialité', 'lists' => 'Que souhaitez-vous recevoir ?', 'required' => 'obligatoire',
            'ok' => 'Merci ! Votre abonnement est enregistré.', 'check' => 'Presque terminé ! Nous avons envoyé un e-mail à %s : ouvrez le lien pour confirmer votre abonnement.', 'confirmed' => 'Abonnement confirmé. Merci !', 'expired' => 'Ce lien de confirmation n\'est pas valide ou a déjà été utilisé.',
            'err_email' => 'Saisissez une adresse e-mail valide.', 'err_req' => 'Remplissez « %s ».', 'err_consent' => 'Vous devez accepter de recevoir les e-mails.', 'err_lists' => 'Choisissez au moins une option.', 'err_bot' => 'L\'envoi a échoué. Rechargez la page et réessayez.', 'err_many' => 'Trop de tentatives depuis cette connexion. Réessayez plus tard.', 'closed' => 'Ce formulaire n\'accepte plus d\'inscriptions.',
            'mail_subject' => 'Confirmez votre abonnement à %s', 'mail_body' => 'Bonjour ! Vous avez demandé à recevoir les e-mails de %s. Pour confirmer, cliquez sur le bouton :', 'mail_button' => 'Confirmer l\'abonnement', 'mail_ignore' => 'Si ce n\'était pas vous, ignorez cet e-mail : vous ne recevrez rien d\'autre.'],
    ];

    public static function t(array $form, string $key): string
    {
        return (self::T[$form['language']] ?? self::T['ca'])[$key];
    }

    /** @return array<int, int> */
    public static function listIds(array $form): array
    {
        return array_values(array_filter(array_map('intval', json_decode((string) $form['list_ids'], true) ?: [])));
    }

    /** @return array<int, array{key: string, required: bool}> */
    public static function fields(array $form): array
    {
        $out = [];
        foreach (json_decode((string) $form['fields'], true) ?: [] as $f) {
            $key = (string) ($f['key'] ?? '');
            if (in_array($key, self::BASE_FIELDS, true) || preg_match('/^camp:[a-z0-9_]{1,40}$/', $key)) {
                $out[] = ['key' => $key, 'required' => !empty($f['required'])];
            }
        }
        return $out;
    }

    /** Field label for the public page. */
    public static function label(array $form, string $key, array $custom): string
    {
        if (str_starts_with($key, 'camp:')) {
            return (string) ($custom[substr($key, 5)] ?? substr($key, 5));
        }
        return self::t($form, $key);
    }

    public static function slug(string $title, int $exceptId = 0): string
    {
        $base = str_replace('_', '-', Contacts::fieldKey($title)) ?: 'inscripcio';
        $slug = substr($base, 0, 60);
        $n = 2;
        while (DB::value('SELECT id FROM forms WHERE slug = ? AND id <> ?', [$slug, $exceptId])) {
            $slug = substr($base, 0, 55) . '-' . $n++;
        }
        return $slug;
    }

    /** Signed "form shown at" stamp (minimum filling time, no session needed). */
    public static function stamp(int $formId): string
    {
        $t = (string) time();
        return $t . '.' . Crypto::sign('form' . $formId . '.' . $t, 16);
    }

    public static function stampAge(int $formId, string $stamp): ?int
    {
        [$t, $sig] = array_pad(explode('.', $stamp, 2), 2, '');
        if (!ctype_digit($t) || !hash_equals(Crypto::sign('form' . $formId . '.' . $t, 16), $sig)) {
            return null;
        }
        return time() - (int) $t;
    }

    public static function ipHash(): string
    {
        return hash('sha256', 'ip|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . Crypto::sign('ip-salt', 16));
    }

    /**
     * Validates a submission and subscribes (or sends the confirmation email).
     *
     * @return array{ok: bool, message: string, pending?: bool}
     */
    public static function submit(array $form, array $project, array $in): array
    {
        if (!$form['active']) {
            return ['ok' => false, 'message' => self::t($form, 'closed')];
        }
        // Bots: hidden field filled in, or sent too fast / with a forged stamp.
        $age = self::stampAge((int) $form['id'], (string) ($in['_t'] ?? ''));
        if (trim((string) ($in['website'] ?? '')) !== '' || $age === null || $age < self::MIN_SECONDS || $age > 86400) {
            return ['ok' => false, 'message' => self::t($form, 'err_bot')];
        }
        $ip = self::ipHash();
        if ((int) DB::value('SELECT COUNT(*) FROM form_signups WHERE ip_hash = ? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)', [$ip]) >= self::MAX_PER_IP_HOUR) {
            return ['ok' => false, 'message' => self::t($form, 'err_many')];
        }
        $email = normalize_email((string) ($in['email'] ?? ''));
        if (!valid_email($email)) {
            return ['ok' => false, 'message' => self::t($form, 'err_email')];
        }
        $custom = array_column(Contacts::fields((int) $project['id']), 'label', 'key');
        $data = ['email' => $email, 'fields' => []];
        foreach (self::fields($form) as $f) {
            $v = mb_substr(trim((string) ($in[str_replace(':', '_', $f['key'])] ?? '')), 0, 500);
            if ($f['required'] && $v === '') {
                return ['ok' => false, 'message' => sprintf(self::t($form, 'err_req'), self::label($form, $f['key'], $custom))];
            }
            if (str_starts_with($f['key'], 'camp:')) {
                $data['fields'][substr($f['key'], 5)] = $v;
            } else {
                $data[$f['key']] = $v;
            }
        }
        if (empty($data['language'])) {
            $data['language'] = $form['language'];
        }
        $allowed = self::listIds($form);
        $lists = $allowed;
        if ($form['choose_lists'] && count($allowed) > 1) {
            $lists = array_values(array_intersect($allowed, array_map('intval', (array) ($in['lists'] ?? []))));
            if (!$lists) {
                return ['ok' => false, 'message' => self::t($form, 'err_lists')];
            }
        }
        $data['lists'] = $lists;
        if (empty($in['consent'])) {
            return ['ok' => false, 'message' => self::t($form, 'err_consent')];
        }
        $data['consent'] = sprintf(self::t($form, 'consent'), $project['name']) . ($form['consent_text'] ? ' ' . $form['consent_text'] : '');
        $data['ip'] = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $token = random_token(40);
        $sid = DB::insert('form_signups', ['form_id' => $form['id'], 'email' => $email, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'token' => $token, 'ip_hash' => $ip, 'created_at' => now_utc()]);

        if ($form['double_optin']) {
            try {
                self::sendConfirmation($form, $project, $email, $token);
            } catch (\Throwable $e) {
                logger('Form ' . $form['id'] . ' confirmation: ' . $e->getMessage(), [], 'forms');
                // Without a confirmation email nobody could finish: subscribe directly, the consent is recorded anyway.
                self::confirm(DB::one('SELECT * FROM form_signups WHERE id = ?', [$sid]), $form, false);
                return ['ok' => true, 'message' => $form['success_text'] ?: self::t($form, 'ok')];
            }
            return ['ok' => true, 'pending' => true, 'message' => sprintf(self::t($form, 'check'), $email)];
        }
        self::confirm(DB::one('SELECT * FROM form_signups WHERE id = ?', [$sid]), $form, false);
        return ['ok' => true, 'message' => $form['success_text'] ?: self::t($form, 'ok')];
    }

    /** Subscribes the person of a signup. $emailConfirmed: they clicked the email link. */
    public static function confirm(array $signup, array $form, bool $emailConfirmed): ?int
    {
        if ($signup['confirmed_at']) {
            return $signup['contact_id'] ? (int) $signup['contact_id'] : null;
        }
        $data = json_decode((string) $signup['data'], true) ?: [];
        $pid = (int) $form['project_id'];
        $r = Contacts::upsert($pid, $data, null, 'formulari:' . $form['slug']);
        if (!$r['id']) {
            return null;
        }
        $cid = (int) $r['id'];
        $contact = DB::one('SELECT status FROM contacts WHERE id = ?', [$cid]);
        // A new explicit consent re-subscribes. Bounced / complained addresses only when they confirmed by email.
        $resub = $contact['status'] === 'unsubscribed' || ($emailConfirmed && in_array($contact['status'], ['bounced', 'complained', 'blocked'], true));
        if ($resub) {
            DB::update('contacts', ['status' => 'subscribed', 'unsubscribed_at' => null, 'updated_at' => now_utc()], 'id = ?', [$cid]);
        }
        DB::update('contacts', ['consent_at' => now_utc()], 'id = ?', [$cid]);
        $valid = $data['lists'] ? DB::column('SELECT id FROM lists WHERE project_id = ? AND id IN (' . DB::in($data['lists']) . ')', array_merge([$pid], $data['lists'])) : [];
        foreach ($valid as $lid) {
            DB::exec("INSERT INTO list_contacts (list_id, contact_id, status, source, added_at) VALUES (?, ?, 'subscribed', ?, ?)
                ON DUPLICATE KEY UPDATE status = 'subscribed'", [$lid, $cid, 'formulari:' . $form['slug'], now_utc()]);
        }
        DB::update('form_signups', ['confirmed_at' => now_utc(), 'contact_id' => $cid], 'id = ?', [$signup['id']]);
        // Proof of consent (GDPR): what was accepted, where, when and from which IP.
        DB::insert('events', ['contact_id' => $cid, 'type' => 'consent', 'created_at' => now_utc(),
            'detail' => mb_substr('Formulari «' . $form['title'] . '»' . ($emailConfirmed ? ' (confirmat per correu)' : '') . ' · IP ' . ($data['ip'] ?? '') . ' · ' . ($data['consent'] ?? ''), 0, 500)]);
        return $cid;
    }

    public static function sendConfirmation(array $form, array $project, string $email, string $token): void
    {
        $sender = $project['default_sender_id'] ? DB::one('SELECT * FROM senders WHERE id = ?', [$project['default_sender_id']]) : null;
        if (!$sender) {
            throw new \RuntimeException('El projecte no té remitent per defecte.');
        }
        $client = Client::forProject($project);
        if (!$client->configured()) {
            throw new \RuntimeException('Mailjet no està configurat.');
        }
        $link = url('f/' . $form['slug'] . '/confirma/' . $token);
        $name = (string) $project['name'];
        $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string) $project['color']) ? $project['color'] : '#ac1966';
        $logo = Brand::logoUrl($project);
        $html = '<!DOCTYPE html><html lang="' . $h($form['language']) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
            . '<body style="margin:0;padding:24px 12px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2430;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;"><tr><td style="padding:28px 28px 30px;">'
            . ($logo !== '' ? '<img src="' . $h($logo) . '" alt="' . $h($name) . '" style="max-height:48px;max-width:200px;margin:0 0 20px;">' : '<p style="font-size:18px;font-weight:bold;margin:0 0 18px;">' . $h($name) . '</p>')
            . '<p style="font-size:16px;line-height:24px;margin:0 0 22px;">' . $h(sprintf(self::t($form, 'mail_body'), $name)) . '</p>'
            . '<p style="margin:0 0 22px;"><a href="' . $h($link) . '" style="display:inline-block;background:' . $color . ';color:#ffffff;font-weight:bold;font-size:15px;line-height:44px;padding:0 24px;border-radius:8px;text-decoration:none;">' . $h(self::t($form, 'mail_button')) . '</a></p>'
            . '<p style="font-size:12px;line-height:18px;color:#8a909c;margin:0;">' . $h(self::t($form, 'mail_ignore')) . '</p>'
            . '</td></tr></table></td></tr></table></body></html>';
        $msg = [
            'From' => ['Email' => $sender['email'], 'Name' => $sender['name'] ?: $name],
            'To' => [['Email' => $email]],
            'Subject' => sprintf(self::t($form, 'mail_subject'), $name),
            'TextPart' => sprintf(self::t($form, 'mail_body'), $name) . "\n\n" . $link . "\n\n" . self::t($form, 'mail_ignore'),
            'HTMLPart' => $html,
            'CustomID' => 'form-' . $form['id'],
            'TrackClicks' => 'disabled',
        ];
        $r = $client->send([$msg]);
        if (!$r[0]['ok']) {
            throw new \RuntimeException((string) $r[0]['error']);
        }
    }

    /** Pending confirmations older than 7 days are forgotten. */
    public static function cleanup(): void
    {
        DB::exec('DELETE FROM form_signups WHERE confirmed_at IS NULL AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)');
    }

    /** Lists that unsubscribing from a message takes the contact out of: the campaign's lists the contact is in. */
    public static function messageLists(array $message): array
    {
        if (!$message['contact_id']) {
            return [];
        }
        $c = DB::one('SELECT audience, project_id FROM campaigns WHERE id = ?', [$message['campaign_id']]);
        $ids = Audience::clean($c['audience'] ?? '')['lists'];
        if (!$ids) {
            return [];
        }
        return DB::all("SELECT l.id, l.name FROM lists l JOIN list_contacts lc ON lc.list_id = l.id
            WHERE l.project_id = ? AND lc.contact_id = ? AND lc.status = 'subscribed' AND l.id IN (" . DB::in($ids) . ') ORDER BY l.name',
            array_merge([(int) $c['project_id'], (int) $message['contact_id']], $ids));
    }

    /** Language label for the public form. */
    public static function languages(): array
    {
        return Document::LANGS;
    }
}
