<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\DB;

/**
 * Mailjet event webhooks (sent, open, click, bounce, spam, blocked, unsub).
 * The message is found by CustomID ("m123") or by Mailjet's MessageID.
 * Hard bounces, complaints and unsubscribes go straight to the contact
 * (suppression), so nobody gets a second email they should not.
 */
final class Webhooks
{
    public static function handle(mixed $payload): int
    {
        $events = is_array($payload) && array_is_list($payload) ? $payload : [$payload];
        $n = 0;
        foreach ($events as $ev) {
            if (is_array($ev) && self::event($ev)) {
                $n++;
            }
        }
        return $n;
    }

    public static function event(array $ev): bool
    {
        $type = (string) ($ev['event'] ?? '');
        if (!in_array($type, ['sent', 'open', 'click', 'bounce', 'spam', 'blocked', 'unsub'], true)) {
            return false;
        }
        $m = null;
        if (preg_match('/^m(\d+)$/', (string) ($ev['CustomID'] ?? ''), $x)) {
            $m = DB::one('SELECT * FROM messages WHERE id = ?', [(int) $x[1]]);
        }
        if (!$m && !empty($ev['MessageID'])) {
            $m = DB::one('SELECT * FROM messages WHERE mailjet_id = ?', [(int) $ev['MessageID']]);
        }
        if (!$m) {
            return false; // test emails and other senders of the same Mailjet account
        }
        $at = isset($ev['time']) && is_numeric($ev['time']) ? gmdate('Y-m-d H:i:s', (int) $ev['time']) : now_utc();
        $id = (int) $m['id'];
        $contact = $m['contact_id'] ? (int) $m['contact_id'] : null;
        $url = null;
        $detail = null;

        switch ($type) {
            case 'sent':
                DB::exec('UPDATE messages SET delivered_at = COALESCE(delivered_at, ?) WHERE id = ?', [$at, $id]);
                break;
            case 'open':
                DB::exec('UPDATE messages SET opened_at = COALESCE(opened_at, ?), open_count = open_count + 1, delivered_at = COALESCE(delivered_at, ?) WHERE id = ?', [$at, $at, $id]);
                if ($contact) {
                    DB::exec('UPDATE contacts SET last_open_at = GREATEST(COALESCE(last_open_at, ?), ?) WHERE id = ?', [$at, $at, $contact]);
                }
                break;
            case 'click':
                $url = mb_substr((string) ($ev['url'] ?? ''), 0, 1000);
                DB::exec('UPDATE messages SET clicked_at = COALESCE(clicked_at, ?), click_count = click_count + 1, opened_at = COALESCE(opened_at, ?), delivered_at = COALESCE(delivered_at, ?) WHERE id = ?', [$at, $at, $at, $id]);
                if ($contact) {
                    DB::exec('UPDATE contacts SET last_click_at = ? WHERE id = ?', [$at, $contact]);
                }
                if ($url !== '' && str_contains($url, '/u/')) {
                    $url = null; // the unsubscribe link is not a "click" worth counting
                }
                break;
            case 'bounce':
                $hard = !empty($ev['hard_bounce']);
                $detail = trim(($hard ? 'dur' : 'tou') . ': ' . ($ev['error'] ?? '') . ' ' . ($ev['comment'] ?? ''));
                DB::exec('UPDATE messages SET bounced_at = COALESCE(bounced_at, ?), bounce_hard = GREATEST(bounce_hard, ?) WHERE id = ?', [$at, $hard ? 1 : 0, $id]);
                if ($hard && $contact) {
                    Contacts::setStatus($contact, 'bounced', (string) ($ev['error'] ?? ''));
                }
                break;
            case 'spam':
                DB::exec('UPDATE messages SET spam_at = COALESCE(spam_at, ?) WHERE id = ?', [$at, $id]);
                if ($contact) {
                    Contacts::setStatus($contact, 'complained', 'marcat com a correu brossa');
                }
                break;
            case 'blocked':
                $detail = trim(($ev['error_related_to'] ?? '') . ': ' . ($ev['error'] ?? ''));
                DB::exec('UPDATE messages SET blocked_at = COALESCE(blocked_at, ?) WHERE id = ?', [$at, $id]);
                if ($contact && ($ev['error'] ?? '') === 'preblocked') {
                    Contacts::setStatus($contact, 'blocked', 'Mailjet: adreça bloquejada prèviament');
                }
                break;
            case 'unsub':
                self::unsubscribe($m, 'Mailjet');
                return true;
        }
        DB::insert('events', ['message_id' => $id, 'campaign_id' => $m['campaign_id'], 'contact_id' => $contact, 'type' => $type,
            'url' => $url, 'detail' => $detail ? mb_substr($detail, 0, 500) : null, 'created_at' => $at]);
        return true;
    }

    /** Unsubscribe from a message (own link, one-click header or Mailjet event). */
    public static function unsubscribe(array $m, string $via): void
    {
        if ($m['unsub_at']) {
            return;
        }
        DB::update('messages', ['unsub_at' => now_utc()], 'id = ?', [$m['id']]);
        DB::insert('events', ['message_id' => $m['id'], 'campaign_id' => $m['campaign_id'], 'contact_id' => $m['contact_id'], 'type' => 'unsub', 'detail' => $via, 'created_at' => now_utc()]);
        if ($m['contact_id']) {
            Contacts::setStatus((int) $m['contact_id'], 'unsubscribed', $via);
        }
    }
}
