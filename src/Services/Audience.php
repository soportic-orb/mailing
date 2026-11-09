<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\DB;

/**
 * Who receives a campaign: {"all": bool, "lists": [ids], "segments": [ids], "exclude_lists": [ids]}.
 * Always only "subscribed" contacts of the campaign's project, each once.
 */
final class Audience
{
    public static function clean(mixed $a): array
    {
        $a = is_array($a) ? $a : (json_decode((string) $a, true) ?: []);
        $ids = static fn ($v): array => array_values(array_unique(array_filter(array_map('intval', (array) $v))));
        return ['all' => !empty($a['all']), 'lists' => $ids($a['lists'] ?? []), 'segments' => $ids($a['segments'] ?? []), 'exclude_lists' => $ids($a['exclude_lists'] ?? [])];
    }

    public static function isEmpty(array $a): bool
    {
        return !$a['all'] && !$a['lists'] && !$a['segments'];
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
        $w = self::where($a, $projectId);
        return $w ? (int) DB::value('SELECT COUNT(*) FROM contacts c WHERE ' . $w[0], $w[1]) : 0;
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
        if ($a['exclude_lists']) {
            $out[] = 'Excepte: ' . implode(', ', DB::column('SELECT name FROM lists WHERE project_id = ? AND id IN (' . DB::in($a['exclude_lists']) . ')', array_merge([$projectId], $a['exclude_lists'])));
        }
        return $out ? implode(' · ', $out) : 'Sense destinataris';
    }
}
