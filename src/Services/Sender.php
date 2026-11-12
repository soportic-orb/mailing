<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\DB;
use Mailing\Core\Settings;
use Mailing\Editor\Document;
use Mailing\Editor\MergeTags;
use Mailing\Mailjet\Client;

/**
 * The send queue (run by the cron worker every minute):
 *  1. scheduled campaigns whose time has come → "sending": one message row per recipient;
 *  2. sending campaigns → queued messages go to Mailjet in batches of 50,
 *     at most `send_rate` per minute; when none is left the campaign is "sent".
 */
final class Sender
{
    public const DEFAULT_RATE = 600;

    public static function tick(): array
    {
        $out = ['started' => 0, 'sent' => 0, 'failed' => 0, 'finished' => 0];
        foreach (DB::all("SELECT * FROM campaigns WHERE status = 'scheduled' AND scheduled_at <= UTC_TIMESTAMP() ORDER BY scheduled_at LIMIT 5") as $c) {
            if (self::start($c)) {
                $out['started']++;
            }
        }
        $budget = max(50, (int) Settings::get('send_rate', (string) self::DEFAULT_RATE));
        foreach (DB::all("SELECT * FROM campaigns WHERE status = 'sending' ORDER BY started_at") as $c) {
            if ($budget <= 0) {
                break;
            }
            $r = self::process($c, $budget);
            $budget -= $r['sent'] + $r['failed'];
            $out['sent'] += $r['sent'];
            $out['failed'] += $r['failed'];
            $out['finished'] += $r['finished'] ? 1 : 0;
        }
        // Emails of active automations with someone waiting for them.
        foreach (DB::all("SELECT c.* FROM campaigns c JOIN automations a ON a.id = c.automation_id
            WHERE c.status = 'auto' AND a.status = 'active' AND EXISTS (SELECT 1 FROM messages m WHERE m.campaign_id = c.id AND m.status = 'queued') ORDER BY c.id") as $c) {
            if ($budget <= 0) {
                break;
            }
            $r = self::process($c, $budget);
            $budget -= $r['sent'] + $r['failed'];
            $out['sent'] += $r['sent'];
            $out['failed'] += $r['failed'];
        }
        return $out;
    }

    /** Freezes the audience into message rows and switches to "sending". */
    public static function start(array $c): bool
    {
        // Only one worker takes it.
        if (DB::exec("UPDATE campaigns SET status = 'sending', started_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'scheduled'", [$c['id']]) !== 1) {
            return false;
        }
        try {
            $doc = Document::decode($c['doc']);
            // Rendered now, with the project's current footer and branding; kept for "view in browser".
            $project = DB::one('SELECT * FROM projects WHERE id = ?', [$c['project_id']]);
            DB::update('campaigns', ['html' => json_encode(Document::render($doc, $project), JSON_UNESCAPED_UNICODE)], 'id = ?', [$c['id']]);
            $audience = Audience::clean($c['audience']);
            $forced = Document::forcedLang($doc, $audience);
            $contacts = Audience::contacts($audience, (int) $c['project_id']);
            $ins = DB::pdo()->prepare('INSERT IGNORE INTO messages (campaign_id, contact_id, email, language, status) VALUES (?, ?, ?, ?, ?)');
            // Plus the addresses typed by hand (each once: the unique key skips the ones already chosen).
            foreach (array_merge($contacts, Audience::typedContacts($audience, (int) $c['project_id'], 'campanya:' . $c['id'])) as $ct) {
                $ins->execute([$c['id'], $ct['id'], $ct['email'], Document::langFor($doc, $ct['language'] ?: null, $forced), 'queued']);
            }
            $n = (int) DB::value('SELECT COUNT(*) FROM messages WHERE campaign_id = ?', [$c['id']]);
            DB::update('campaigns', ['recipients' => $n], 'id = ?', [$c['id']]);
            if ($n === 0) {
                DB::update('campaigns', ['status' => 'failed', 'error' => 'No hi havia cap destinatari en el moment de l\'enviament.', 'finished_at' => now_utc()], 'id = ?', [$c['id']]);
                Notify::campaignFailed($c, 'no hi havia cap destinatari en el moment de l\'enviament.');
            } else {
                Notify::campaignStarted($c, $n);
            }
            Activity::log('campaign.started', $c['name'] . ' · ' . $n . ' destinataris', (int) $c['project_id']);
            return true;
        } catch (\Throwable $e) {
            DB::update('campaigns', ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)], 'id = ?', [$c['id']]);
            logger('Campaign ' . $c['id'] . ' start: ' . $e->getMessage(), [], 'send');
            Notify::campaignFailed($c, 'no s\'ha pogut començar l\'enviament (' . mb_substr($e->getMessage(), 0, 150) . ').');
            return false;
        }
    }

    /** @return array{sent:int, failed:int, finished:bool} */
    public static function process(array $c, int $limit): array
    {
        $project = DB::one('SELECT * FROM projects WHERE id = ?', [$c['project_id']]);
        $sender = $c['sender_id'] ? DB::one('SELECT * FROM senders WHERE id = ?', [$c['sender_id']]) : null;
        $client = Client::forProject($project);
        $sent = $failed = 0;
        // Automation emails stay "auto": their messages wait until the problem is solved.
        $auto = $c['status'] === 'auto';
        if (!$project || !$sender || !$client->configured()) {
            $error = !$sender ? 'La campanya no té remitent.' : 'Mailjet no està configurat.';
            DB::update('campaigns', $auto ? ['error' => $error] : ['status' => 'paused', 'error' => $error], 'id = ?', [$c['id']]);
            $auto ? Notify::automationEmailFailed($c, $error) : Notify::campaignFailed($c, 's\'ha pausat: ' . lcfirst($error));
            return ['sent' => 0, 'failed' => 0, 'finished' => false];
        }
        $doc = Document::decode($c['doc']);
        if ($auto) {
            // Always the current version (kept for "view in browser").
            $html = Document::render($doc, $project);
            DB::update('campaigns', ['html' => json_encode($html, JSON_UNESCAPED_UNICODE), 'error' => null], 'id = ?', [$c['id']]);
        } else {
            $html = json_decode((string) DB::value('SELECT html FROM campaigns WHERE id = ?', [$c['id']]), true) ?: Document::render($doc, $project);
        }
        $texts = [];
        foreach (array_keys($doc['langs']) as $l) {
            $texts[$l] = Document::text($doc, $l, $project);
        }

        while ($limit > 0) {
            $batch = DB::all("SELECT m.*, ct.first_name, ct.last_name, ct.fields, ct.status AS contact_status FROM messages m LEFT JOIN contacts ct ON ct.id = m.contact_id
                WHERE m.campaign_id = ? AND m.status = 'queued' ORDER BY m.id LIMIT " . min(Client::BATCH, $limit), [$c['id']]);
            if (!$batch) {
                break;
            }
            // Someone who unsubscribed after the campaign started is skipped.
            $payloads = $ids = [];
            foreach ($batch as $m) {
                if ($m['contact_id'] && $m['contact_status'] !== 'subscribed') {
                    DB::update('messages', ['status' => 'skipped', 'error' => 'Contacte ' . ($m['contact_status'] ?: 'eliminat') . ' abans de l\'enviament'], 'id = ?', [$m['id']]);
                    continue;
                }
                $lang = isset($doc['langs'][$m['language']]) ? $m['language'] : $doc['primary'];
                $vars = MergeTags::vars($m, ['unsubscribe_url' => Links::unsubscribe((int) $m['id']), 'view_url' => Links::view((int) $m['id'])]);
                $payloads[] = self::payload($sender, $c, [
                    'email' => $m['email'], 'name' => trim($m['first_name'] . ' ' . $m['last_name']),
                    'subject' => MergeTags::apply(Document::subject($doc, $lang), $vars, false),
                    'html' => MergeTags::apply($html[$lang] ?? reset($html), $vars),
                    'text' => MergeTags::apply($texts[$lang] ?? '', $vars, false),
                    'custom_id' => 'm' . $m['id'], 'unsubscribe' => $vars['unsubscribe_url'],
                ]);
                $ids[] = (int) $m['id'];
            }
            if (!$payloads) {
                continue;
            }
            try {
                $results = $client->send($payloads);
            } catch (\Throwable $e) {
                // Whole batch refused (network, credentials): retry later, pause after 3 attempts.
                DB::exec('UPDATE messages SET attempts = attempts + 1, error = ? WHERE id IN (' . DB::in($ids) . ')', array_merge([mb_substr($e->getMessage(), 0, 500)], $ids));
                $max = (int) DB::value('SELECT MAX(attempts) FROM messages WHERE id IN (' . DB::in($ids) . ')', $ids);
                if ($max >= 3 && $auto) {
                    DB::exec("UPDATE messages SET status = 'failed' WHERE attempts >= 3 AND status = 'queued' AND id IN (" . DB::in($ids) . ')', $ids);
                    DB::update('campaigns', ['error' => 'Enviament fallit: ' . mb_substr($e->getMessage(), 0, 900)], 'id = ?', [$c['id']]);
                    Notify::automationEmailFailed($c, 'Mailjet no l\'accepta (' . mb_substr($e->getMessage(), 0, 150) . ').');
                } elseif ($max >= 3) {
                    DB::update('campaigns', ['status' => 'paused', 'error' => 'Enviament aturat: ' . mb_substr($e->getMessage(), 0, 900)], 'id = ?', [$c['id']]);
                    Notify::campaignFailed($c, 's\'ha pausat després de 3 intents: ' . mb_substr($e->getMessage(), 0, 150));
                }
                logger('Campaign ' . $c['id'] . ' batch: ' . $e->getMessage(), [], 'send');
                return ['sent' => $sent, 'failed' => $failed, 'finished' => false];
            }
            foreach ($results as $i => $r) {
                if ($r['ok']) {
                    DB::update('messages', ['status' => 'sent', 'mailjet_id' => $r['id'], 'sent_at' => now_utc(), 'error' => null], 'id = ?', [$ids[$i]]);
                    $sent++;
                } else {
                    DB::update('messages', ['status' => 'failed', 'error' => mb_substr((string) $r['error'], 0, 500)], 'id = ?', [$ids[$i]]);
                    $failed++;
                }
            }
            $limit -= count($payloads);
        }

        if ($auto) {
            return ['sent' => $sent, 'failed' => $failed, 'finished' => false];
        }
        $left = (int) DB::value("SELECT COUNT(*) FROM messages WHERE campaign_id = ? AND status = 'queued'", [$c['id']]);
        if ($left === 0) {
            $ok = (int) DB::value("SELECT COUNT(*) FROM messages WHERE campaign_id = ? AND status = 'sent'", [$c['id']]);
            DB::exec("UPDATE campaigns SET status = ?, finished_at = UTC_TIMESTAMP(), error = ? WHERE id = ? AND status = 'sending'",
                [$ok > 0 ? 'sent' : 'failed', $ok > 0 ? null : 'Mailjet no ha acceptat cap missatge.', $c['id']]);
            Activity::log('campaign.finished', $c['name'] . ' · ' . $ok . ' enviats', (int) $c['project_id']);
            $ok > 0 ? Notify::campaignSent($c) : Notify::campaignFailed($c, 'Mailjet no ha acceptat cap missatge.');
            return ['sent' => $sent, 'failed' => $failed, 'finished' => true];
        }
        return ['sent' => $sent, 'failed' => $failed, 'finished' => false];
    }

    /** One Mailjet v3.1 message. */
    public static function payload(array $sender, array $c, array $m): array
    {
        $reply = trim((string) ($c['reply_to'] ?: $sender['reply_to']));
        $p = [
            'From' => ['Email' => $sender['email'], 'Name' => $sender['name']],
            'To' => [array_filter(['Email' => $m['email'], 'Name' => $m['name'] ?: null])],
            'Subject' => $m['subject'],
            'TextPart' => $m['text'],
            'HTMLPart' => $m['html'],
            'CustomID' => $m['custom_id'],
            'CustomCampaign' => 'mlg-' . $c['project_id'] . '-' . $c['id'],
            'DeduplicateCampaign' => false,
            'TrackOpens' => 'enabled',
            'TrackClicks' => 'enabled',
            'Headers' => [
                'List-Unsubscribe' => '<' . $m['unsubscribe'] . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        ];
        if ($reply !== '' && valid_email($reply)) {
            $p['ReplyTo'] = ['Email' => $reply];
        }
        return $p;
    }
}
