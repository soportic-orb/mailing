<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\DB;
use Mailing\Editor\Document;

/**
 * Who receives a campaign: {"all": bool, "lists": [ids], "segments": [ids], "exclude_lists": [ids],
 * "emails": [addresses typed by hand]}. Always only "subscribed" contacts of the campaign's
 * project, each once; a typed address that is not a contact yet becomes one when sending.
 */
final class Audience
{
    public static function clean(mixed $a): array
    {
        $a = is_array($a) ? $a : (json_decode((string) $a, true) ?: []);
        $ids = static fn ($v): array => array_values(array_unique(array_filter(array_map('intval', (array) $v))));
        // "lang" forces the language version every recipient gets ('' = each contact's own).
        $lang = is_string($a['lang'] ?? null) && isset(Document::LANGS[$a['lang']]) ? $a['lang'] : '';
        return ['all' => !empty($a['all']), 'lists' => $ids($a['lists'] ?? []), 'segments' => $ids($a['segments'] ?? []), 'exclude_lists' => $ids($a['exclude_lists'] ?? []), 'lang' => $lang,
            'emails' => array_slice(self::emails($a['emails'] ?? [])['valid'], 0, self::MAX_EMAILS)];
    }

    public const MAX_EMAILS = 1000;

    /**
     * Addresses typed by hand (one per line, or separated by commas, semicolons or spaces).
     *
     * @return array{valid: string[], invalid: string[]}
     */
    public static function emails(mixed $raw): array
    {
        $parts = is_array($raw) ? $raw : (preg_split('/[\s,;]+/', (string) $raw) ?: []);
        $valid = $invalid = [];
        foreach ($parts as $p) {
            $p = trim((string) $p, " \t\n\r<>\"'");
            if ($p === '') {
                continue;
            }
            $e = normalize_email($p);
            if (valid_email($e)) {
                $valid[$e] = true;
            } else {
                $invalid[] = mb_substr($p, 0, 80);
            }
        }
        return ['valid' => array_keys($valid), 'invalid' => $invalid];
    }

    public static function isEmpty(array $a): bool
    {
        return !$a['all'] && !$a['lists'] && !$a['segments'] && empty($a['emails']);
    }

    /** @return array{0: string, 1: array}|null WHERE on contacts c */
    public static function where(array $a, int $projectId): ?array
    {
        $a = self::clean($a);
        if (self::isEmpty($a)) {
            return null;
        }
        $sql = "c.project_id = ? AND c.status = 'subscribed'";
        $params = [$projectId];
        if (!$a['all']) {
            $or = [];
            if ($a['lists']) {
                $lists = DB::column('SELECT id FROM lists WHERE project_id = ? AND id IN (' . DB::in($a['lists']) . ')', array_merge([$projectId], $a['lists']));
                if ($lists) {
                    $or[] = "c.id IN (SELECT contact_id FROM list_contacts WHERE status = 'subscribed' AND list_id IN (" . DB::in($lists) . '))';
                    array_push($params, ...$lists);
                }
            }
            foreach ($a['segments'] as $sid) {
                $seg = DB::one('SELECT rules FROM segments WHERE id = ? AND project_id = ?', [$sid, $projectId]);
                $c = $seg ? Segments::compile(json_decode((string) $seg['rules'], true) ?: [], $projectId) : null;
                if ($c) {
                    $or[] = '(' . $c[0] . ')';
                    array_push($params, ...$c[1]);
                }
            }
            if (!$or) {
                return null;
            }
            $sql .= ' AND (' . implode(' OR ', $or) . ')';
        }
        if ($a['exclude_lists']) {
            $sql .= " AND c.id NOT IN (SELECT contact_id FROM list_contacts WHERE status = 'subscribed' AND list_id IN (" . DB::in($a['exclude_lists']) . '))';
            array_push($params, ...$a['exclude_lists']);
        }
        return [$sql, $params];
    }

    public static function count(array $a, int $projectId): int
    {
        $a = self::clean($a);
        $w = self::where($a, $projectId);
        $n = $w ? (int) DB::value('SELECT COUNT(*) FROM contacts c WHERE ' . $w[0], $w[1]) : 0;
        return $n + self::extraCount($a, $projectId, $w);
    }

    /** Typed addresses that add a recipient: not a contact yet, or subscribed and not already chosen. */
    private static function extraCount(array $a, int $projectId, ?array $w): int
    {
        if (!$a['emails']) {
            return 0;
        }
        $in = DB::in($a['emails']);
        $rows = DB::all("SELECT id, email, status FROM contacts WHERE project_id = ? AND email IN ($in)", array_merge([$projectId], $a['emails']));
        $known = array_column($rows, null, 'email');
        $subscribed = array_map('intval', array_column(array_filter($rows, static fn ($r) => $r['status'] === 'subscribed'), 'id'));
        $chosen = $w && $subscribed
            ? array_map('intval', DB::column('SELECT c.id FROM contacts c WHERE (' . $w[0] . ') AND c.id IN (' . DB::in($subscribed) . ')', array_merge($w[1], $subscribed)))
            : [];
        $n = count(array_diff($a['emails'], array_keys($known)));
        return $n + count(array_diff($subscribed, $chosen));
    }

    /**
     * The contacts of the typed addresses that can receive the campaign. Addresses that are not
     * a contact yet become one (so an unsubscribe or a bounce is remembered); someone who
     * unsubscribed, bounced or complained is left out.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function typedContacts(array $a, int $projectId, string $source): array
    {
        $a = self::clean($a);
        if (!$a['emails']) {
            return [];
        }
        $in = DB::in($a['emails']);
        $known = array_column(DB::all("SELECT email FROM contacts WHERE project_id = ? AND email IN ($in)", array_merge([$projectId], $a['emails'])), 'email');
        foreach (array_diff($a['emails'], $known) as $email) {
            Contacts::upsert($projectId, ['email' => $email], null, $source, false);
        }
        return DB::all("SELECT * FROM contacts WHERE project_id = ? AND status = 'subscribed' AND email IN ($in) ORDER BY id", array_merge([$projectId], $a['emails']));
    }

    /** @return array<int, array<string, mixed>> */
    public static function contacts(array $a, int $projectId): array
    {
        $w = self::where($a, $projectId);
        return $w ? DB::all('SELECT c.* FROM contacts c WHERE ' . $w[0] . ' ORDER BY c.id', $w[1]) : [];
    }

    public static function describe(array $a, int $projectId): string
    {
        $a = self::clean($a);
        if ($a['all']) {
            $out = ['Tots els contactes subscrits'];
        } else {
            $out = [];
            if ($a['lists']) {
                $out[] = 'Llistes: ' . implode(', ', DB::column('SELECT name FROM lists WHERE project_id = ? AND id IN (' . DB::in($a['lists']) . ')', array_merge([$projectId], $a['lists'])));
            }
            if ($a['segments']) {
                $out[] = 'Segments: ' . implode(', ', DB::column('SELECT name FROM segments WHERE project_id = ? AND id IN (' . DB::in($a['segments']) . ')', array_merge([$projectId], $a['segments'])));
            }
        }
        if ($a['emails']) {
            $out[] = count($a['emails']) === 1 ? 'Adreça escrita: ' . $a['emails'][0] : count($a['emails']) . ' adreces escrites a mà';
        }
        if ($a['exclude_lists']) {
            $out[] = 'Excepte: ' . implode(', ', DB::column('SELECT name FROM lists WHERE project_id = ? AND id IN (' . DB::in($a['exclude_lists']) . ')', array_merge([$projectId], $a['exclude_lists'])));
        }
        return $out ? implode(' · ', $out) : 'Sense destinataris';
    }
}
