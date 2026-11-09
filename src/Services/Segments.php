<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\DB;

/**
 * Dynamic segments: rules evaluated when used (always up to date).
 *
 *   {"match": "all"|"any", "conditions": [
 *      {"type":"field", "field":"first_name|last_name|email|language|created_at|camp:<key>", "op":"…", "value":"…"},
 *      {"type":"list", "op":"in|not_in", "value": <list id>},
 *      {"type":"activity", "op":"opened|not_opened|clicked|not_clicked|received|not_received", "campaign": <id or 0 = any>, "days": <0 = ever>}
 *   ]}
 */
final class Segments
{
    public const FIELD_OPS = [
        'eq' => 'és igual a', 'neq' => 'no és igual a', 'contains' => 'conté', 'not_contains' => 'no conté',
        'starts' => 'comença per', 'ends' => 'acaba en', 'empty' => 'és buit', 'not_empty' => 'no és buit',
        'before' => 'és anterior a (data)', 'after' => 'és posterior a (data)',
    ];

    public const ACTIVITY_OPS = [
        'opened' => 'ha obert', 'not_opened' => 'no ha obert', 'clicked' => 'ha clicat', 'not_clicked' => 'no ha clicat',
        'received' => 'ha rebut', 'not_received' => 'no ha rebut',
    ];

    public static function clean(mixed $rules): array
    {
        $rules = is_array($rules) ? $rules : (json_decode((string) $rules, true) ?: []);
        $out = ['match' => ($rules['match'] ?? 'all') === 'any' ? 'any' : 'all', 'conditions' => []];
        foreach (array_slice((array) ($rules['conditions'] ?? []), 0, 30) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $type = (string) ($c['type'] ?? '');
            if ($type === 'field') {
                $field = (string) ($c['field'] ?? '');
                $op = (string) ($c['op'] ?? '');
                if (!isset(self::FIELD_OPS[$op]) || !preg_match('/^(first_name|last_name|email|language|created_at|camp:[a-z0-9_]{1,40})$/', $field)) {
                    continue;
                }
                $out['conditions'][] = ['type' => 'field', 'field' => $field, 'op' => $op, 'value' => mb_substr(trim((string) ($c['value'] ?? '')), 0, 255)];
            } elseif ($type === 'list') {
                $out['conditions'][] = ['type' => 'list', 'op' => ($c['op'] ?? '') === 'not_in' ? 'not_in' : 'in', 'value' => (int) ($c['value'] ?? 0)];
            } elseif ($type === 'activity' && isset(self::ACTIVITY_OPS[$c['op'] ?? ''])) {
                $out['conditions'][] = ['type' => 'activity', 'op' => $c['op'], 'campaign' => max(0, (int) ($c['campaign'] ?? 0)), 'days' => max(0, min(3650, (int) ($c['days'] ?? 0)))];
            }
        }
        return $out;
    }

    /**
     * SQL condition on contacts `c` (with its params), or null when the
     * segment has no valid condition.
     *
     * @return array{0: string, 1: array}|null
     */
    public static function compile(array $rules, int $projectId): ?array
    {
        $rules = self::clean($rules);
        $parts = [];
        $params = [];
        foreach ($rules['conditions'] as $c) {
            [$sql, $p] = match ($c['type']) {
                'field' => self::fieldSql($c),
                'list' => self::listSql($c, $projectId),
                'activity' => self::activitySql($c, $projectId),
            };
            if ($sql !== '') {
                $parts[] = '(' . $sql . ')';
                array_push($params, ...$p);
            }
        }
        if (!$parts) {
            return null;
        }
        return [implode($rules['match'] === 'any' ? ' OR ' : ' AND ', $parts), $params];
    }

    private static function fieldSql(array $c): array
    {
        $col = match (true) {
            str_starts_with($c['field'], 'camp:') => "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.fields, '$." . substr($c['field'], 5) . "')), '')",
            default => 'c.' . $c['field'],
        };
        $v = $c['value'];
        $like = static fn (string $s): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
        return match ($c['op']) {
            'eq' => ["LOWER($col) = LOWER(?)", [$v]],
            'neq' => ["LOWER($col) <> LOWER(?)", [$v]],
            'contains' => ["$col LIKE ?", ['%' . $like($v) . '%']],
            'not_contains' => ["$col NOT LIKE ?", ['%' . $like($v) . '%']],
            'starts' => ["$col LIKE ?", [$like($v) . '%']],
            'ends' => ["$col LIKE ?", ['%' . $like($v)]],
            'empty' => ["($col IS NULL OR $col = '')", []],
            'not_empty' => ["($col IS NOT NULL AND $col <> '')", []],
            'before' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? ["$col < ?", [$v]] : ['', []],
            'after' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? ["$col > ?", [$v . ' 23:59:59']] : ['', []],
            default => ['', []],
        };
    }

    private static function listSql(array $c, int $projectId): array
    {
        $sub = "SELECT lc.contact_id FROM list_contacts lc JOIN lists l ON l.id = lc.list_id WHERE lc.list_id = ? AND l.project_id = ? AND lc.status = 'subscribed'";
        return [($c['op'] === 'not_in' ? 'c.id NOT IN (' : 'c.id IN (') . $sub . ')', [$c['value'], $projectId]];
    }

    private static function activitySql(array $c, int $projectId): array
    {
        $col = match ($c['op']) {
            'opened', 'not_opened' => 'm.opened_at',
            'clicked', 'not_clicked' => 'm.clicked_at',
            default => 'm.sent_at',
        };
        $sub = "SELECT m.contact_id FROM messages m JOIN campaigns ca ON ca.id = m.campaign_id WHERE ca.project_id = ? AND m.contact_id IS NOT NULL AND $col IS NOT NULL";
        $params = [$projectId];
        if ($c['campaign']) {
            $sub .= ' AND m.campaign_id = ?';
            $params[] = $c['campaign'];
        }
        if ($c['days']) {
            $sub .= " AND $col >= ?";
            $params[] = gmdate('Y-m-d H:i:s', time() - $c['days'] * 86400);
        }
        $negative = str_starts_with($c['op'], 'not_');
        return [($negative ? 'c.id NOT IN (' : 'c.id IN (') . $sub . ')', $params];
    }

    /** Human description of a rule set (lists of campaigns). */
    public static function describe(array $rules, array $lists = [], array $campaigns = []): string
    {
        $rules = self::clean($rules);
        $fields = Contacts::BASE_FIELDS + ['created_at' => 'Data d\'alta'];
        $out = [];
        foreach ($rules['conditions'] as $c) {
            if ($c['type'] === 'field') {
                $label = str_starts_with($c['field'], 'camp:') ? substr($c['field'], 5) : ($fields[$c['field']] ?? $c['field']);
                $out[] = $label . ' ' . self::FIELD_OPS[$c['op']] . (in_array($c['op'], ['empty', 'not_empty'], true) ? '' : ' «' . $c['value'] . '»');
            } elseif ($c['type'] === 'list') {
                $out[] = ($c['op'] === 'in' ? 'és a la llista' : 'no és a la llista') . ' «' . ($lists[$c['value']] ?? '#' . $c['value']) . '»';
            } else {
                $out[] = self::ACTIVITY_OPS[$c['op']] . ' ' . ($c['campaign'] ? '«' . ($campaigns[$c['campaign']] ?? '#' . $c['campaign']) . '»' : 'cap campanya')
                    . ($c['days'] ? ' (últims ' . $c['days'] . ' dies)' : '');
            }
        }
        return $out ? implode($rules['match'] === 'any' ? ' o ' : ' i ', $out) : 'Sense condicions';
    }

    public static function count(array $rules, int $projectId): int
    {
        $c = self::compile($rules, $projectId);
        if (!$c) {
            return 0;
        }
        return (int) DB::value("SELECT COUNT(*) FROM contacts c WHERE c.project_id = ? AND c.status = 'subscribed' AND (" . $c[0] . ')', array_merge([$projectId], $c[1]));
    }
}
