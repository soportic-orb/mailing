<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\Auth;
use Mailing\Core\DB;
use Mailing\Editor\Document;
use Mailing\Editor\MergeTags;
use Mailing\Mailjet\Client;

/**
 * Campaigns: draft → scheduled → sending → sent, plus paused, cancelled and
 * failed. The content is a Document (one email per language), rendered to
 * HTML on every save; sending reads the rendered HTML.
 */
final class Campaigns
{
    public const STATUSES = [
        'draft' => 'Esborrany',
        'scheduled' => 'Programada',
        'sending' => 'Enviant',
        'paused' => 'Pausada',
        'sent' => 'Enviada',
        'cancelled' => 'Cancel·lada',
        'failed' => 'Fallida',
    ];

    /** Statuses whose content and audience can still be changed. */
    public const EDITABLE = ['draft', 'scheduled', 'paused', 'cancelled', 'failed'];

    public static function find(int $id, int $projectId): ?array
    {
        return DB::one('SELECT * FROM campaigns WHERE id = ? AND project_id = ?', [$id, $projectId]);
    }

    public static function editable(array $c): bool
    {
        return in_array($c['status'], ['draft', 'scheduled', 'cancelled', 'failed'], true);
    }

    public static function create(array $project, string $name, ?int $templateId, ?int $senderId, ?string $lang = null): int
    {
        $lang = $lang && isset(Document::LANGS[$lang]) ? $lang : ($project['default_language'] ?: 'ca');
        $doc = null;
        if ($templateId) {
            $t = DB::one('SELECT doc FROM templates WHERE id = ? AND (project_id = ? OR project_id IS NULL)', [$templateId, $project['id']]);
            if ($t) {
                $doc = Document::decode($t['doc'], $project['default_language']);
            }
        }
        if (!$doc) {
            \Mailing\Editor\Brand::apply($project);
            $doc = Document::blank($lang);
        }
        $doc = Document::withLanguage($doc, $lang);
        $now = now_utc();
        $id = DB::insert('campaigns', [
            'project_id' => $project['id'], 'name' => mb_substr($name, 0, 190), 'subject' => Document::subject($doc),
            'sender_id' => $senderId ?: ($project['default_sender_id'] ?: null), 'doc' => Document::encode($doc),
            'html' => json_encode(Document::render($doc, $project), JSON_UNESCAPED_UNICODE),
            'audience' => json_encode(Audience::clean([])), 'status' => 'draft', 'version' => 1,
            'created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        self::snapshot('campaign', $id, 1, $name, $doc);
        return $id;
    }

    /**
     * Saves the editor document. Optimistic locking: if someone saved since
     * $baseVersion, returns ['conflict' => true] unless $force.
     *
     * @return array{result: string, version: int, conflict?: bool, message?: string}
     */
    public static function saveDoc(string $type, array $row, mixed $rawDoc, int $baseVersion, bool $force, array $project): array
    {
        $table = $type === 'campaign' ? 'campaigns' : 'templates';
        if ($baseVersion && $baseVersion !== (int) $row['version'] && !$force) {
            $who = DB::value('SELECT name FROM users WHERE id = ?', [$row['updated_by']]) ?: 'algú altre';
            return ['result' => 'conflict', 'version' => (int) $row['version'], 'conflict' => true,
                'message' => 'Mentre editaves, ' . $who . ' ha desat una versió més nova (versió ' . $row['version'] . '). Si desis, la substituiràs.'];
        }
        $doc = Document::clean($rawDoc, $project['default_language'] ?? 'ca');
        $json = Document::encode($doc);
        if ($json === $row['doc']) {
            return ['result' => 'unchanged', 'version' => (int) $row['version']];
        }
        $version = (int) $row['version'] + 1;
        $set = ['doc' => $json, 'version' => $version, 'updated_by' => Auth::id(), 'updated_at' => now_utc()];
        if ($type === 'campaign') {
            $set['subject'] = Document::subject($doc);
            $set['html'] = json_encode(Document::render($doc, $project), JSON_UNESCAPED_UNICODE);
        }
        DB::update($table, $set, 'id = ?', [$row['id']]);
        self::snapshot($type, (int) $row['id'], $version, $type === 'campaign' ? Document::subject($doc) : (string) $row['name'], $doc);
        return ['result' => 'saved', 'version' => $version];
    }

    public static function snapshot(string $type, int $id, int $version, string $name, array $doc): void
    {
        DB::exec('INSERT IGNORE INTO doc_versions (owner_type, owner_id, version, name, doc, saved_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$type, $id, $version, mb_substr($name, 0, 255), Document::encode($doc), Auth::id(), now_utc()]);
        // Keep the last 60 versions.
        DB::exec('DELETE FROM doc_versions WHERE owner_type = ? AND owner_id = ? AND version <= ?', [$type, $id, $version - 60]);
    }

    public static function duplicate(array $c, array $project): int
    {
        $now = now_utc();
        $doc = Document::decode($c['doc']);
        $id = DB::insert('campaigns', [
            'project_id' => $c['project_id'], 'name' => mb_substr($c['name'] . ' (còpia)', 0, 190), 'subject' => $c['subject'],
            'sender_id' => $c['sender_id'], 'reply_to' => $c['reply_to'], 'doc' => $c['doc'],
            'html' => json_encode(Document::render($doc, $project), JSON_UNESCAPED_UNICODE), 'audience' => $c['audience'],
            'status' => 'draft', 'version' => 1, 'created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        self::snapshot('campaign', $id, 1, $c['name'], $doc);
        return $id;
    }

    /**
     * Pre-send checks. "block" ones stop the send.
     *
     * @return array<int, array{ok: bool, label: string, block: bool}>
     */
    public static function checks(array $c, array $project): array
    {
        $doc = Document::decode($c['doc']);
        $sender = $c['sender_id'] ? DB::one('SELECT * FROM senders WHERE id = ? AND project_id = ?', [$c['sender_id'], $project['id']]) : null;
        $audience = Audience::clean($c['audience']);
        $recipients = Audience::count($audience, (int) $project['id']);
        $forced = Document::forcedLang($doc, $audience);
        $out = [];
        $out[] = ['ok' => (bool) $sender, 'label' => $sender ? 'Remitent: ' . $sender['name'] . ' <' . $sender['email'] . '>' : 'Cal triar l\'adreça d\'emissió (remitent).', 'block' => true];
        if ($sender) {
            $active = strtolower((string) $sender['mailjet_status']) === 'active';
            $out[] = ['ok' => $active, 'label' => $active ? 'El remitent està validat a Mailjet.' : 'El remitent no consta com a validat a Mailjet (estat: ' . ($sender['mailjet_status'] ?: 'desconegut') . '). Sincronitza els remitents.', 'block' => false];
        }
        $out[] = ['ok' => $recipients > 0, 'label' => $recipients > 0 ? fmt_n($recipients) . ' destinataris: ' . Audience::describe(Audience::clean($c['audience']), (int) $project['id']) : 'No hi ha cap destinatari (tria llistes o segments).', 'block' => true];
        if ($forced) {
            $out[] = ['ok' => true, 'label' => 'Tothom rebrà la versió en ' . Document::LANGS[$forced] . ' (idioma forçat).', 'block' => false];
        } elseif ($audience['lang'] !== '') {
            $out[] = ['ok' => false, 'label' => 'L\'idioma triat (' . Document::LANGS[$audience['lang']] . ') no és al correu: cada contacte rebrà el seu idioma.', 'block' => false];
        }
        foreach ($doc['langs'] as $code => $l) {
            if ($forced && $code !== $forced) {
                continue;
            }
            $tag = count($doc['langs']) > 1 ? ' (' . Document::LANGS[$code] . ')' : '';
            $out[] = ['ok' => $l['subject'] !== '', 'label' => $l['subject'] !== '' ? 'Assumpte' . $tag . ': «' . $l['subject'] . '»' : 'Falta l\'assumpte' . $tag . '.', 'block' => true];
            $out[] = ['ok' => $l['preheader'] !== '', 'label' => $l['preheader'] !== '' ? 'Text de previsualització' . $tag . ' posat.' : 'No hi ha text de previsualització' . $tag . ' (recomanat).', 'block' => false];
            $blocks = $l['doc']['blocks'];
            $out[] = ['ok' => count($blocks) > 0, 'label' => count($blocks) > 0 ? count($blocks) . ' blocs de contingut' . $tag . '.' : 'El correu' . $tag . ' és buit.', 'block' => true];
            $buttons = array_filter($blocks, static fn ($b) => in_array($b['type'], ['button', 'link'], true) && $b['href'] === '');
            $out[] = ['ok' => !$buttons, 'label' => !$buttons ? 'Tots els botons i enllaços tenen adreça' . $tag . '.' : count($buttons) . ' botó/enllaç sense adreça' . $tag . '.', 'block' => false];
            $noAlt = array_filter($blocks, static fn ($b) => $b['type'] === 'image' && $b['src'] !== '' && $b['alt'] === '');
            $out[] = ['ok' => !$noAlt, 'label' => !$noAlt ? 'Les imatges tenen text alternatiu' . $tag . '.' : count($noAlt) . ' imatge(s) sense text alternatiu' . $tag . '.', 'block' => false];
        }
        if (!$project['postal_address']) {
            $out[] = ['ok' => false, 'label' => 'El projecte no té adreça postal al peu (recomanada per la normativa i pels filtres antispam).', 'block' => false];
        }
        $out[] = ['ok' => (bool) $c['test_sent_at'], 'label' => $c['test_sent_at'] ? 'Prova enviada ' . ago($c['test_sent_at']) . '.' : 'Encara no s\'ha enviat cap prova.', 'block' => false];
        $out[] = ['ok' => Client::forProject($project)->configured(), 'label' => Client::forProject($project)->configured() ? 'Mailjet configurat.' : 'Mailjet no està configurat (Configuració).', 'block' => true];
        return $out;
    }

    public static function blocking(array $checks): array
    {
        return array_values(array_filter($checks, static fn ($c) => !$c['ok'] && $c['block']));
    }

    /** Schedules (or sends now, when $utc is null). @throws \RuntimeException */
    public static function schedule(array $c, array $project, ?string $utc): void
    {
        if (!in_array($c['status'], ['draft', 'scheduled', 'cancelled', 'failed'], true)) {
            throw new \RuntimeException('Aquesta campanya ja s\'està enviant o s\'ha enviat.');
        }
        $block = self::blocking(self::checks($c, $project));
        if ($block) {
            throw new \RuntimeException('No es pot enviar: ' . $block[0]['label']);
        }
        if ($utc !== null && strtotime($utc . ' UTC') < time() - 60) {
            throw new \RuntimeException('La data d\'enviament ja ha passat.');
        }
        DB::update('campaigns', ['status' => 'scheduled', 'scheduled_at' => $utc ?? now_utc(), 'error' => null, 'updated_at' => now_utc(), 'updated_by' => Auth::id()], 'id = ?', [$c['id']]);
        Activity::log($utc ? 'campaign.scheduled' : 'campaign.send', $c['name'] . ($utc ? ' · ' . $utc . ' UTC' : ''), (int) $project['id']);
    }

    public static function unschedule(array $c): void
    {
        if ($c['status'] === 'scheduled') {
            DB::update('campaigns', ['status' => 'draft', 'updated_at' => now_utc()], 'id = ?', [$c['id']]);
            Activity::log('campaign.unscheduled', $c['name'], (int) $c['project_id']);
        }
    }

    public static function pause(array $c): void
    {
        if ($c['status'] === 'sending') {
            DB::update('campaigns', ['status' => 'paused', 'updated_at' => now_utc()], 'id = ?', [$c['id']]);
            Activity::log('campaign.paused', $c['name'], (int) $c['project_id']);
        }
    }

    public static function resume(array $c): void
    {
        if ($c['status'] === 'paused') {
            DB::update('campaigns', ['status' => 'sending', 'updated_at' => now_utc()], 'id = ?', [$c['id']]);
            Activity::log('campaign.resumed', $c['name'], (int) $c['project_id']);
        }
    }

    /** Stops a paused campaign for good: queued messages are dropped. */
    public static function cancel(array $c): void
    {
        if (in_array($c['status'], ['paused', 'scheduled'], true)) {
            DB::exec("UPDATE messages SET status = 'cancelled' WHERE campaign_id = ? AND status = 'queued'", [$c['id']]);
            $sent = (int) DB::value("SELECT COUNT(*) FROM messages WHERE campaign_id = ? AND status = 'sent'", [$c['id']]);
            DB::update('campaigns', ['status' => $sent ? 'sent' : 'cancelled', 'finished_at' => $sent ? now_utc() : null, 'updated_at' => now_utc()], 'id = ?', [$c['id']]);
            Activity::log('campaign.cancelled', $c['name'], (int) $c['project_id']);
        }
    }

    /**
     * Test email to some addresses (merge tags filled with the contact of that
     * email if it exists, otherwise sample values).
     */
    public static function sendTest(array $c, array $project, array $emails, ?string $lang = null): int
    {
        $emails = array_values(array_unique(array_filter(array_map('normalize_email', $emails), 'valid_email')));
        if (!$emails) {
            throw new \RuntimeException('Escriu almenys una adreça de correu vàlida.');
        }
        if (count($emails) > 10) {
            throw new \RuntimeException('Com a màxim 10 adreces de prova.');
        }
        $sender = $c['sender_id'] ? DB::one('SELECT * FROM senders WHERE id = ? AND project_id = ?', [$c['sender_id'], $project['id']]) : null;
        if (!$sender) {
            throw new \RuntimeException('Tria primer l\'adreça d\'emissió (remitent) de la campanya.');
        }
        $doc = Document::decode($c['doc']);
        $html = Document::render($doc, $project);
        $lang = $lang && isset($doc['langs'][$lang]) ? $lang : $doc['primary'];
        $messages = [];
        foreach ($emails as $email) {
            $contact = DB::one('SELECT * FROM contacts WHERE project_id = ? AND email = ?', [$project['id'], $email])
                ?? ['email' => $email, 'first_name' => '', 'last_name' => '', 'fields' => null];
            $vars = MergeTags::vars($contact, ['unsubscribe_url' => url('u/prova'), 'view_url' => url('u/prova')]);
            $messages[] = Sender::payload($sender, $c, [
                'email' => $email, 'name' => trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')),
                'subject' => '[PROVA] ' . MergeTags::apply(Document::subject($doc, $lang), $vars, false),
                'html' => MergeTags::apply($html[$lang] ?? reset($html), $vars),
                'text' => MergeTags::apply(Document::text($doc, $lang, $project), $vars, false),
                'custom_id' => 'test-' . $c['id'], 'unsubscribe' => url('u/prova'),
            ]);
        }
        $res = Client::forProject($project)->send($messages);
        $fail = array_filter($res, static fn ($r) => !$r['ok']);
        if ($fail) {
            throw new \RuntimeException('Mailjet no ha acceptat la prova: ' . reset($fail)['error']);
        }
        DB::update('campaigns', ['test_sent_at' => now_utc()], 'id = ?', [$c['id']]);
        return count($messages);
    }
}
