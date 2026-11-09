<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\DB;

/**
 * Contacts of a project. A contact belongs to one project (the same email in
 * two projects is two contacts) and to any number of its lists.
 *
 * Statuses: subscribed · unsubscribed (asked to stop) · bounced (hard bounce)
 * · complained (marked as spam) · blocked (Mailjet blocked it). Only
 * "subscribed" contacts receive campaigns. Imports and syncs never
 * re-subscribe someone who unsubscribed or bounced.
 */
final class Contacts
{
    public const STATUSES = [
        'subscribed' => 'Subscrit',
        'unsubscribed' => 'De baixa',
        'bounced' => 'Rebot',
        'complained' => 'Queixa (spam)',
        'blocked' => 'Bloquejat',
    ];

    public const BASE_FIELDS = ['email' => 'Correu electrònic', 'first_name' => 'Nom', 'last_name' => 'Cognoms', 'language' => 'Idioma', 'external_id' => 'ID extern'];

    /** @return array<int, array{key:string,label:string,type:string}> */
    public static function fields(int $projectId): array
    {
        return DB::all('SELECT `key`, label, type FROM fields WHERE project_id = ? ORDER BY label', [$projectId]);
    }

    /** Creates a custom field if needed and returns its key. */
    public static function ensureField(int $projectId, string $label, string $type = 'text'): string
    {
        $key = self::fieldKey($label);
        if ($key === '') {
            return '';
        }
        DB::exec('INSERT IGNORE INTO fields (project_id, `key`, label, type, created_at) VALUES (?, ?, ?, ?, ?)', [$projectId, $key, mb_substr($label, 0, 120), $type, now_utc()]);
        return $key;
    }

    public static function fieldKey(string $label): string
    {
        $s = mb_strtolower(trim($label));
        $s = strtr($s, ['à' => 'a', 'á' => 'a', 'è' => 'e', 'é' => 'e', 'í' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', 'l·l' => 'll', '·' => '']);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        return substr(trim((string) $s, '_'), 0, 40);
    }

    public static function normalizeLang(mixed $v): string
    {
        $v = strtolower(substr(trim((string) $v), 0, 2));
        $map = ['ca' => 'ca', 'es' => 'es', 'en' => 'en', 'fr' => 'fr', 'ct' => 'ca', 'sp' => 'es', 'ga' => 'ca'];
        return $map[$v] ?? '';
    }

    /**
     * Creates or updates a contact and (optionally) adds it to a list.
     *
     * @param array{email:string, first_name?:string, last_name?:string, language?:string, external_id?:string, fields?:array<string,mixed>, status?:string} $data
     * @param bool $overwrite update existing names/fields (otherwise only fills blanks)
     * @return array{result: 'created'|'updated'|'unchanged'|'invalid', id: ?int}
     */
    public static function upsert(int $projectId, array $data, ?int $listId = null, string $source = 'manual', bool $overwrite = true): array
    {
        $email = normalize_email((string) ($data['email'] ?? ''));
        if (!valid_email($email)) {
            return ['result' => 'invalid', 'id' => null];
        }
        $now = now_utc();
        $first = mb_substr(trim((string) ($data['first_name'] ?? '')), 0, 120);
        $last = mb_substr(trim((string) ($data['last_name'] ?? '')), 0, 190);
        $lang = self::normalizeLang($data['language'] ?? '');
        $ext = mb_substr(trim((string) ($data['external_id'] ?? '')), 0, 120);
        $fields = [];
        foreach ((array) ($data['fields'] ?? []) as $k => $v) {
            $k = self::fieldKey((string) $k);
            if ($k !== '' && (is_scalar($v) || $v === null)) {
                $fields[$k] = mb_substr(trim((string) $v), 0, 1000);
            }
        }

        $row = DB::one('SELECT * FROM contacts WHERE project_id = ? AND email = ?', [$projectId, $email]);
        if (!$row && $ext !== '') {
            // Same person, new email (syncs): matched by the external id.
            $row = DB::one('SELECT * FROM contacts WHERE project_id = ? AND external_id = ?', [$projectId, $ext]);
            if ($row && DB::value('SELECT id FROM contacts WHERE project_id = ? AND email = ?', [$projectId, $email])) {
                $row = null;
            }
        }

        if (!$row) {
            $status = isset($data['status']) && isset(self::STATUSES[$data['status']]) ? $data['status'] : 'subscribed';
            $id = DB::insert('contacts', [
                'project_id' => $projectId, 'email' => $email, 'first_name' => $first, 'last_name' => $last,
                'language' => $lang, 'status' => $status, 'fields' => $fields ? json_encode($fields, JSON_UNESCAPED_UNICODE) : null,
                'source' => mb_substr($source, 0, 60), 'external_id' => $ext ?: null, 'consent_at' => $now,
                'unsubscribed_at' => $status === 'unsubscribed' ? $now : null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $result = 'created';
        } else {
            $id = (int) $row['id'];
            $old = json_decode((string) $row['fields'], true) ?: [];
            $set = [];
            $pick = static fn (string $new, string $cur): string => $new !== '' && ($overwrite || $cur === '') ? $new : $cur;
            foreach (['first_name' => $first, 'last_name' => $last, 'language' => $lang] as $col => $val) {
                $v = $pick($val, (string) $row[$col]);
                if ($v !== (string) $row[$col]) {
                    $set[$col] = $v;
                }
            }
            if ($row['email'] !== $email) {
                $set['email'] = $email;
            }
            if ($ext !== '' && $ext !== (string) $row['external_id']) {
                $set['external_id'] = $ext;
            }
            $merged = $old;
            foreach ($fields as $k => $v) {
                if ($v !== '' && ($overwrite || ($old[$k] ?? '') === '')) {
                    $merged[$k] = $v;
                }
            }
            if ($merged != $old) {
                $set['fields'] = json_encode($merged, JSON_UNESCAPED_UNICODE);
            }
            // An explicit status change (manual edit / API) is applied; imports never re-subscribe.
            if (isset($data['status']) && isset(self::STATUSES[$data['status']]) && $data['status'] !== $row['status']) {
                $set['status'] = $data['status'];
                $set['unsubscribed_at'] = $data['status'] === 'unsubscribed' ? $now : $row['unsubscribed_at'];
            }
            if ($set) {
                $set['updated_at'] = $now;
                DB::update('contacts', $set, 'id = ?', [$id]);
            }
            $result = $set ? 'updated' : 'unchanged';
        }

        if ($listId) {
            $added = DB::exec('INSERT IGNORE INTO list_contacts (list_id, contact_id, status, source, added_at) VALUES (?, ?, ?, ?, ?)', [$listId, $id, 'subscribed', mb_substr($source, 0, 60), $now]);
            if ($added && $result === 'unchanged') {
                $result = 'updated';
            }
        }
        return ['result' => $result, 'id' => $id];
    }

    /** Status change coming from an email event or the unsubscribe page. */
    public static function setStatus(int $contactId, string $status, string $reason = ''): void
    {
        $row = DB::one('SELECT id, status FROM contacts WHERE id = ?', [$contactId]);
        if (!$row || $row['status'] === $status) {
            return;
        }
        // A complaint or a hard bounce is stronger than an unsubscribe.
        $rank = ['subscribed' => 0, 'unsubscribed' => 1, 'blocked' => 2, 'bounced' => 3, 'complained' => 4];
        if ($status !== 'subscribed' && ($rank[$row['status']] ?? 0) > ($rank[$status] ?? 0)) {
            return;
        }
        DB::update('contacts', [
            'status' => $status,
            'unsubscribed_at' => $status === 'unsubscribed' ? now_utc() : null,
            'updated_at' => now_utc(),
        ], 'id = ?', [$contactId]);
        DB::insert('events', ['contact_id' => $contactId, 'type' => 'status', 'detail' => mb_substr($status . ($reason !== '' ? ': ' . $reason : ''), 0, 500), 'created_at' => now_utc()]);
    }

    public static function delete(int $projectId, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $params = array_merge([$projectId], $ids);
        $ids = DB::column('SELECT id FROM contacts WHERE project_id = ? AND id IN (' . DB::in($ids) . ')', $params);
        if (!$ids) {
            return 0;
        }
        DB::exec('DELETE FROM list_contacts WHERE contact_id IN (' . DB::in($ids) . ')', $ids);
        DB::exec('UPDATE messages SET contact_id = NULL WHERE contact_id IN (' . DB::in($ids) . ')', $ids);
        DB::exec('DELETE FROM events WHERE contact_id IN (' . DB::in($ids) . ') AND campaign_id IS NULL', $ids);
        DB::exec('UPDATE events SET contact_id = NULL WHERE contact_id IN (' . DB::in($ids) . ')', $ids);
        return DB::exec('DELETE FROM contacts WHERE id IN (' . DB::in($ids) . ')', $ids);
    }

    /** Everything stored about a contact (GDPR access request). */
    public static function export(int $contactId): array
    {
        $c = DB::one('SELECT * FROM contacts WHERE id = ?', [$contactId]) ?? [];
        $c['fields'] = json_decode((string) ($c['fields'] ?? ''), true) ?: [];
        return [
            'contact' => $c,
            'lists' => DB::all('SELECT l.name, lc.status, lc.added_at, lc.source FROM list_contacts lc JOIN lists l ON l.id = lc.list_id WHERE lc.contact_id = ?', [$contactId]),
            'messages' => DB::all('SELECT c.name AS campaign, m.status, m.sent_at, m.opened_at, m.clicked_at, m.bounced_at, m.unsub_at FROM messages m JOIN campaigns c ON c.id = m.campaign_id WHERE m.contact_id = ? ORDER BY m.id', [$contactId]),
            'events' => DB::all('SELECT type, url, detail, created_at FROM events WHERE contact_id = ? ORDER BY id', [$contactId]),
        ];
    }
}
