<?php

declare(strict_types=1);

namespace Mailing\Services;

use Mailing\Core\Auth;
use Mailing\Core\DB;
use Mailing\Editor\Document;
use Mailing\Mailjet\Client;

/**
 * Automations: a visual flow (ported from the Torns panel's FlowBuilder) that
 * every enrolled contact walks step by step. Unlike Torns, nothing is
 * computed in advance: each condition is evaluated when the contact reaches
 * it, so a flow can ask "did they open the previous email?".
 *
 *   {"steps": [{"id": "trigger-ab12", "type": "trigger|email|delay|condition|action", "config": {…}}],
 *    "edges": [{"from": "…", "to": "…", "branch": "yes|no"}]}
 *
 * The flow is a tree under its single trigger: every step has one parent;
 * a branched condition has a "yes" and a "no" child, any other step one child.
 *
 * Emails are campaigns with status "auto" (same editor, rendering, tracking,
 * unsubscribe and report); their messages carry the run id. The cron worker
 * calls tick() every minute: new enrolments, then every run whose time has
 * come moves on until it waits, finishes or leaves.
 */
final class Automations
{
    public const STATUSES = ['draft' => 'Esborrany', 'active' => 'Activa', 'paused' => 'Pausada'];

    public const TRIGGERS = [
        'list' => ['S\'apunta a una llista', 'Quan algú entra a la llista (formulari, importació, API, sincronització o a mà).'],
        'form' => ['S\'inscriu amb un formulari', 'Quan algú confirma l\'alta en un formulari concret.'],
        'event' => ['Esdeveniment per API', 'Quan una altra plataforma (Emergencing, SysRevAI…) envia l\'esdeveniment a /api/v1/events.'],
        'date' => ['Data d\'un camp', 'Cada dia, als contactes amb la data del camp (o X dies abans o després).'],
        'manual' => ['Manual', 'Inscrius tu els contactes d\'una llista o d\'un segment.'],
    ];

    public const UNITS = ['minutes' => 'minuts', 'hours' => 'hores', 'days' => 'dies', 'weeks' => 'setmanes'];

    private const UNIT_SECONDS = ['minutes' => 60, 'hours' => 3600, 'days' => 86400, 'weeks' => 604800];
    private const ID = '/^[a-z]{1,10}-[a-z0-9]{1,16}$/';
    private const MAX_STEPS = 100;

    // ------------------------------------------------------------------
    // Flow document
    // ------------------------------------------------------------------

    public static function blankFlow(string $trigger = 'list'): array
    {
        return ['steps' => [['id' => 'trigger-start', 'type' => 'trigger', 'config' => self::cleanConfig('trigger', ['kind' => $trigger])]], 'edges' => []];
    }

    /** Whitelists a flow and makes it a tree under its trigger. */
    public static function cleanFlow(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        $steps = [];
        $trigger = null;
        foreach (array_slice((array) ($raw['steps'] ?? []), 0, self::MAX_STEPS) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $id = (string) ($s['id'] ?? '');
            $type = (string) ($s['type'] ?? '');
            if (!preg_match(self::ID, $id) || isset($steps[$id]) || !in_array($type, ['trigger', 'email', 'delay', 'condition', 'action'], true)) {
                continue;
            }
            if ($type === 'trigger') {
                if ($trigger) {
                    continue;
                }
                $trigger = $id;
            }
            $steps[$id] = ['id' => $id, 'type' => $type, 'config' => self::cleanConfig($type, (array) ($s['config'] ?? []))];
        }
        if (!$trigger) {
            $blank = self::blankFlow();
            $trigger = $blank['steps'][0]['id'];
            $steps = [$trigger => $blank['steps'][0]] + $steps;
        }
        // Edges: one parent per step, one child (or one per branch).
        $children = [];
        $hasParent = [];
        foreach ((array) ($raw['edges'] ?? []) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $from = (string) ($e['from'] ?? '');
            $to = (string) ($e['to'] ?? '');
            if (!isset($steps[$from], $steps[$to]) || $from === $to || $to === $trigger || isset($hasParent[$to])) {
                continue;
            }
            $branched = self::isBranched($steps[$from]);
            $branch = $branched ? (($e['branch'] ?? '') === 'no' ? 'no' : 'yes') : '';
            if (isset($children[$from][$branch])) {
                continue;
            }
            $children[$from][$branch] = $to;
            $hasParent[$to] = true;
        }
        // Keep only what hangs from the trigger (this also drops cycles).
        $keep = [];
        $queue = [$trigger];
        while ($queue) {
            $id = array_shift($queue);
            if (isset($keep[$id])) {
                continue;
            }
            $keep[$id] = true;
            foreach ($children[$id] ?? [] as $to) {
                $queue[] = $to;
            }
        }
        $outSteps = [];
        $outEdges = [];
        foreach ($steps as $id => $s) {
            if (!isset($keep[$id])) {
                continue;
            }
            $outSteps[] = $s;
            foreach ($children[$id] ?? [] as $branch => $to) {
                $outEdges[] = $branch !== '' ? ['from' => $id, 'to' => $to, 'branch' => $branch] : ['from' => $id, 'to' => $to];
            }
        }
        return ['steps' => $outSteps, 'edges' => $outEdges];
    }

    public static function cleanConfig(string $type, array $c): array
    {
        $int = static fn (mixed $v, int $min, int $max, int $def): int => is_numeric($v) ? max($min, min($max, (int) $v)) : $def;
        $str = static fn (mixed $v, int $len): string => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $len);
        $time = static fn (mixed $v): string => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : '';
        $field = static fn (mixed $v): string => is_string($v) && preg_match('/^(first_name|last_name|email|language|created_at|camp:[a-z0-9_]{1,40})$/', $v) ? $v : '';
        switch ($type) {
            case 'trigger':
                $kind = isset(self::TRIGGERS[$c['kind'] ?? '']) ? (string) $c['kind'] : 'list';
                return ['kind' => $kind, 'list_id' => $int($c['list_id'] ?? 0, 0, PHP_INT_MAX, 0), 'form_id' => $int($c['form_id'] ?? 0, 0, PHP_INT_MAX, 0),
                    'event' => strtolower(preg_replace('/[^A-Za-z0-9_.:-]/', '', $str($c['event'] ?? '', 60)) ?? ''),
                    'field' => ($c['field'] ?? '') === 'created_at' ? 'created_at' : (preg_match('/^camp:[a-z0-9_]{1,40}$/', (string) ($c['field'] ?? '')) ? (string) $c['field'] : ''),
                    'offset' => $int($c['offset'] ?? 0, -365, 365, 0), 'repeat' => ($c['repeat'] ?? '') === 'yearly' ? 'yearly' : 'once',
                    'at' => $time($c['at'] ?? '') ?: '09:00'];
            case 'email':
                return ['campaign_id' => $int($c['campaign_id'] ?? 0, 0, PHP_INT_MAX, 0), 'name' => $str($c['name'] ?? '', 120),
                    'template_id' => $int($c['template_id'] ?? 0, 0, PHP_INT_MAX, 0), 'lang' => isset(Document::LANGS[$c['lang'] ?? '']) ? (string) $c['lang'] : ''];
            case 'delay':
                return ['amount' => $int($c['amount'] ?? 1, 1, 999, 1), 'unit' => isset(self::UNITS[$c['unit'] ?? '']) ? (string) $c['unit'] : 'days', 'at' => $time($c['at'] ?? '')];
            case 'condition':
                $kind = in_array($c['kind'] ?? '', ['opened', 'clicked', 'field', 'list', 'segment'], true) ? (string) $c['kind'] : 'opened';
                $op = (string) ($c['op'] ?? '');
                $op = match ($kind) {
                    'opened', 'clicked' => $op === 'no' ? 'no' : 'yes',
                    'list', 'segment' => $op === 'not_in' ? 'not_in' : 'in',
                    default => isset(Segments::FIELD_OPS[$op]) ? $op : 'eq',
                };
                return ['kind' => $kind, 'op' => $op, 'step' => is_string($c['step'] ?? null) && preg_match(self::ID, $c['step']) ? $c['step'] : '',
                    'field' => $field($c['field'] ?? ''), 'value' => $str($c['value'] ?? '', 255),
                    'list_id' => $int($c['list_id'] ?? 0, 0, PHP_INT_MAX, 0), 'segment_id' => $int($c['segment_id'] ?? 0, 0, PHP_INT_MAX, 0),
                    'branched' => !empty($c['branched'])];
            case 'action':
                $kind = in_array($c['kind'] ?? '', ['list_add', 'list_remove', 'set_field'], true) ? (string) $c['kind'] : 'list_add';
                $f = $field($c['field'] ?? '');
                return ['kind' => $kind, 'list_id' => $int($c['list_id'] ?? 0, 0, PHP_INT_MAX, 0),
                    'field' => in_array($f, ['email', 'created_at'], true) ? '' : $f, 'value' => $str($c['value'] ?? '', 1000)];
        }
        return [];
    }

    public static function isBranched(array $step): bool
    {
        return $step['type'] === 'condition' && !empty($step['config']['branched']);
    }

    public static function decode(array $a): array
    {
        return self::cleanFlow($a['flow']);
    }

    /** @return array<string, array> */
    private static function stepsById(array $flow): array
    {
        return array_column($flow['steps'], null, 'id');
    }

    /** Next step after $id ('' branch for linear steps). */
    private static function next(array $flow, string $id, string $branch = ''): ?string
    {
        foreach ($flow['edges'] as $e) {
            if ($e['from'] === $id && ($e['branch'] ?? '') === $branch) {
                return $e['to'];
            }
        }
        return null;
    }

    private static function trigger(array $flow): array
    {
        foreach ($flow['steps'] as $s) {
            if ($s['type'] === 'trigger') {
                return $s;
            }
        }
        return self::blankFlow()['steps'][0];
    }

    /** Steps that come before $id (its ancestors). */
    public static function ancestors(array $flow, string $id): array
    {
        $parent = [];
        foreach ($flow['edges'] as $e) {
            $parent[$e['to']] = $e['from'];
        }
        $out = [];
        while (isset($parent[$id])) {
            $id = $parent[$id];
            $out[] = $id;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Create, save, validate
    // ------------------------------------------------------------------

    public static function create(array $project, string $name, string $trigger = 'list', string $preset = ''): int
    {
        $now = now_utc();
        $id = DB::insert('automations', [
            'project_id' => $project['id'], 'name' => mb_substr($name, 0, 190), 'status' => 'draft', 'trigger_type' => $trigger,
            'flow' => json_encode(self::blankFlow($trigger)), 'sender_id' => $project['default_sender_id'] ?: null,
            'created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $a = DB::one('SELECT * FROM automations WHERE id = ?', [$id]);
        $flow = self::preset($preset, $project) ?? self::blankFlow($trigger);
        self::saveFlow($a, $flow, $project);
        Activity::log('automation.created', $name, (int) $project['id']);
        return $id;
    }

    /** Ready-made flows to start from. */
    public static function preset(string $preset, array $project): ?array
    {
        $lang = $project['default_language'] ?: 'ca';
        if ($preset === 'welcome') {
            return ['steps' => [
                ['id' => 'trigger-start', 'type' => 'trigger', 'config' => ['kind' => 'list']],
                ['id' => 'email-welcome', 'type' => 'email', 'config' => ['name' => 'Benvinguda', 'lang' => $lang]],
                ['id' => 'delay-3d', 'type' => 'delay', 'config' => ['amount' => 3, 'unit' => 'days', 'at' => '10:00']],
                ['id' => 'condition-open', 'type' => 'condition', 'config' => ['kind' => 'opened', 'op' => 'yes', 'step' => 'email-welcome', 'branched' => true]],
                ['id' => 'email-more', 'type' => 'email', 'config' => ['name' => 'Coneix-nos millor', 'lang' => $lang]],
                ['id' => 'email-remind', 'type' => 'email', 'config' => ['name' => 'Recordatori', 'lang' => $lang]],
            ], 'edges' => [
                ['from' => 'trigger-start', 'to' => 'email-welcome'],
                ['from' => 'email-welcome', 'to' => 'delay-3d'],
                ['from' => 'delay-3d', 'to' => 'condition-open'],
                ['from' => 'condition-open', 'to' => 'email-more', 'branch' => 'yes'],
                ['from' => 'condition-open', 'to' => 'email-remind', 'branch' => 'no'],
            ]];
        }
        if ($preset === 'birthday') {
            return ['steps' => [
                ['id' => 'trigger-start', 'type' => 'trigger', 'config' => ['kind' => 'date', 'repeat' => 'yearly', 'at' => '09:00']],
                ['id' => 'email-bday', 'type' => 'email', 'config' => ['name' => 'Per molts anys', 'lang' => $lang]],
            ], 'edges' => [['from' => 'trigger-start', 'to' => 'email-bday']]];
        }
        return null;
    }

    /**
     * Saves a flow from the editor: creates the campaigns of new emails,
     * deletes those of removed ones (if nothing was sent), and moves the
     * contacts waiting in a removed step to the step that followed it.
     *
     * @return array{flow: array, moved: int}
     */
    public static function saveFlow(array $a, mixed $raw, array $project): array
    {
        $old = self::decode($a);
        $flow = self::cleanFlow($raw);
        $mine = array_map('intval', DB::column('SELECT id FROM campaigns WHERE automation_id = ? AND project_id = ?', [$a['id'], $project['id']]));
        $used = [];
        $trigger = self::trigger($flow);
        $lists = $trigger['config']['kind'] === 'list' && $trigger['config']['list_id'] ? [$trigger['config']['list_id']] : [];
        foreach ($flow['steps'] as &$s) {
            if ($s['type'] !== 'email') {
                continue;
            }
            $cid = $s['config']['campaign_id'];
            if (!$cid || !in_array($cid, $mine, true) || isset($used[$cid])) {
                $name = $s['config']['name'] ?: 'Correu ' . (count($used) + 1);
                $cid = Campaigns::create($project, $a['name'] . ' · ' . $name, $s['config']['template_id'] ?: null, $a['sender_id'] ? (int) $a['sender_id'] : null, $s['config']['lang'] ?: null);
                DB::update('campaigns', ['status' => 'auto', 'automation_id' => $a['id']], 'id = ?', [$cid]);
                $s['config']['campaign_id'] = $cid;
            }
            $s['config']['template_id'] = 0;
            $used[$cid] = $s['config']['name'];
        }
        unset($s);
        // Campaign rows follow the automation: name, sender and (for per-list unsubscribe) the trigger list.
        foreach ($used as $cid => $name) {
            $c = DB::one('SELECT audience FROM campaigns WHERE id = ?', [$cid]);
            $aud = Audience::clean($c['audience'] ?? '');
            $aud['lists'] = $lists;
            DB::update('campaigns', ['name' => mb_substr($a['name'] . ' · ' . ($name ?: 'Correu'), 0, 190), 'sender_id' => $a['sender_id'] ?: null,
                'reply_to' => $a['reply_to'] ?: null, 'audience' => json_encode($aud)], 'id = ?', [$cid]);
        }
        foreach (array_diff($mine, array_keys($used)) as $cid) {
            if (!DB::value('SELECT COUNT(*) FROM messages WHERE campaign_id = ?', [$cid])) {
                DB::exec("DELETE FROM doc_versions WHERE owner_type = 'campaign' AND owner_id = ?", [$cid]);
                DB::exec('DELETE FROM campaigns WHERE id = ?', [$cid]);
            }
        }
        $moved = self::rehome($a, $old, $flow);
        DB::update('automations', ['flow' => json_encode($flow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'trigger_type' => $trigger['config']['kind'], 'trigger_config' => json_encode($trigger['config'], JSON_UNESCAPED_UNICODE),
            'updated_by' => Auth::id(), 'updated_at' => now_utc()], 'id = ?', [$a['id']]);
        return ['flow' => $flow, 'moved' => $moved];
    }

    /** Contacts in a step that no longer exists go to the first following step that still does. */
    private static function rehome(array $a, array $old, array $new): int
    {
        $exists = self::stepsById($new);
        $gone = array_diff(array_column($old['steps'], 'id'), array_keys($exists));
        $moved = 0;
        foreach ($gone as $id) {
            $target = null;
            $queue = [$id];
            $seen = [];
            while ($queue && !$target) {
                $cur = array_shift($queue);
                if (isset($seen[$cur])) {
                    continue;
                }
                $seen[$cur] = true;
                foreach ($old['edges'] as $e) {
                    if ($e['from'] === $cur) {
                        if (isset($exists[$e['to']])) {
                            $target = $e['to'];
                            break;
                        }
                        $queue[] = $e['to'];
                    }
                }
            }
            $params = [$a['id'], $id];
            if ($target) {
                $moved += DB::exec("UPDATE automation_runs SET step_id = ?, waiting = 0, next_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE automation_id = ? AND step_id = ? AND status = 'active'", array_merge([$target], $params));
            } else {
                $moved += DB::exec("UPDATE automation_runs SET status = 'done', exit_reason = 'El pas s''ha eliminat del flux', finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE automation_id = ? AND step_id = ? AND status = 'active'", $params);
            }
        }
        return $moved;
    }

    /**
     * Problems that stop the activation ("block") or deserve a look.
     *
     * @return array<int, array{ok: bool, label: string, block: bool, step?: string}>
     */
    public static function checks(array $a, array $project): array
    {
        $flow = self::decode($a);
        $pid = (int) $project['id'];
        $out = [];
        $t = self::trigger($flow)['config'];
        $tid = self::trigger($flow)['id'];
        $bad = static function (string $label, string $step = '', bool $block = true) use (&$out): void {
            $out[] = ['ok' => false, 'label' => $label, 'block' => $block, 'step' => $step];
        };
        match ($t['kind']) {
            'list' => $t['list_id'] && DB::value('SELECT id FROM lists WHERE id = ? AND project_id = ?', [$t['list_id'], $pid]) ?: $bad('Tria la llista del disparador.', $tid),
            'form' => $t['form_id'] && DB::value('SELECT id FROM forms WHERE id = ? AND project_id = ?', [$t['form_id'], $pid]) ?: $bad('Tria el formulari del disparador.', $tid),
            'event' => $t['event'] !== '' ?: $bad('Escriu el nom de l\'esdeveniment del disparador.', $tid),
            'date' => $t['field'] !== '' ?: $bad('Tria el camp de data del disparador.', $tid),
            default => null,
        };
        if (count($flow['steps']) < 2) {
            $bad('El flux només té el disparador: afegeix-hi almenys un correu.');
        }
        $sender = $a['sender_id'] ? DB::one('SELECT * FROM senders WHERE id = ? AND project_id = ?', [$a['sender_id'], $pid]) : null;
        if (!$sender) {
            $bad('Tria l\'adreça d\'emissió (remitent) de l\'automatització.');
        } elseif (strtolower((string) $sender['mailjet_status']) !== 'active') {
            $bad('El remitent no consta com a validat a Mailjet. Sincronitza els remitents.', '', false);
        }
        if (!Client::forProject($project)->configured()) {
            $bad('Mailjet no està configurat (Configuració).');
        }
        $byId = self::stepsById($flow);
        foreach ($flow['steps'] as $s) {
            $c = $s['config'];
            if ($s['type'] === 'email') {
                $row = $c['campaign_id'] ? DB::one('SELECT doc FROM campaigns WHERE id = ? AND automation_id = ?', [$c['campaign_id'], $a['id']]) : null;
                $label = '«' . ($c['name'] ?: 'Correu') . '»';
                if (!$row) {
                    $bad('El correu ' . $label . ' encara no s\'ha creat: desa el flux.', $s['id']);
                    continue;
                }
                $doc = Document::decode($row['doc']);
                foreach ($doc['langs'] as $code => $l) {
                    $tag = count($doc['langs']) > 1 ? ' (' . Document::LANGS[$code] . ')' : '';
                    if ($l['subject'] === '') {
                        $bad('Falta l\'assumpte del correu ' . $label . $tag . '.', $s['id']);
                    }
                    if (!$l['doc']['blocks']) {
                        $bad('El correu ' . $label . $tag . ' és buit.', $s['id']);
                    }
                    if (array_filter($l['doc']['blocks'], static fn ($b) => in_array($b['type'], ['button', 'link'], true) && $b['href'] === '')) {
                        $bad('El correu ' . $label . $tag . ' té botons o enllaços sense adreça.', $s['id'], false);
                    }
                }
            } elseif ($s['type'] === 'condition') {
                if (in_array($c['kind'], ['opened', 'clicked'], true)) {
                    if ($c['step'] === '' || ($byId[$c['step']]['type'] ?? '') !== 'email' || !in_array($c['step'], self::ancestors($flow, $s['id']), true)) {
                        $bad('La condició ha de mirar un correu que s\'enviï abans en el mateix camí.', $s['id']);
                    }
                } elseif ($c['kind'] === 'field' && $c['field'] === '') {
                    $bad('Tria el camp de la condició.', $s['id']);
                } elseif ($c['kind'] === 'list' && !$c['list_id']) {
                    $bad('Tria la llista de la condició.', $s['id']);
                } elseif ($c['kind'] === 'segment' && !$c['segment_id']) {
                    $bad('Tria el segment de la condició.', $s['id']);
                }
            } elseif ($s['type'] === 'action') {
                if (in_array($c['kind'], ['list_add', 'list_remove'], true) && !$c['list_id']) {
                    $bad('Tria la llista de l\'acció.', $s['id']);
                }
                if ($c['kind'] === 'set_field' && $c['field'] === '') {
                    $bad('Tria el camp que ha de canviar l\'acció.', $s['id']);
                }
            }
        }
        return $out;
    }

    public static function blocking(array $checks): array
    {
        return array_values(array_filter($checks, static fn ($c) => !$c['ok'] && $c['block']));
    }

    /** @throws \RuntimeException */
    public static function activate(array $a, array $project): void
    {
        $block = self::blocking(self::checks($a, $project));
        if ($block) {
            throw new \RuntimeException('No es pot activar: ' . $block[0]['label']);
        }
        DB::update('automations', ['status' => 'active', 'activated_at' => now_utc(), 'scan_at' => $a['scan_at'] ?: now_utc(),
            'updated_at' => now_utc(), 'updated_by' => Auth::id()], 'id = ?', [$a['id']]);
        Activity::log('automation.activated', $a['name'], (int) $project['id']);
    }

    public static function pause(array $a): void
    {
        if ($a['status'] === 'active') {
            DB::update('automations', ['status' => 'paused', 'updated_at' => now_utc(), 'updated_by' => Auth::id()], 'id = ?', [$a['id']]);
            Activity::log('automation.paused', $a['name'], (int) $a['project_id']);
        }
    }

    public static function delete(array $a): void
    {
        $cids = array_map('intval', DB::column('SELECT id FROM campaigns WHERE automation_id = ? AND project_id = ?', [$a['id'], $a['project_id']]));
        if ($cids) {
            $in = DB::in($cids);
            DB::exec("DELETE FROM events WHERE campaign_id IN ($in)", $cids);
            DB::exec("DELETE FROM messages WHERE campaign_id IN ($in)", $cids);
            DB::exec("DELETE FROM doc_versions WHERE owner_type = 'campaign' AND owner_id IN ($in)", $cids);
            DB::exec("DELETE FROM campaigns WHERE id IN ($in)", $cids);
        }
        DB::exec('DELETE FROM automation_log WHERE automation_id = ?', [$a['id']]);
        DB::exec('DELETE FROM automation_runs WHERE automation_id = ?', [$a['id']]);
        DB::exec('DELETE FROM automations WHERE id = ?', [$a['id']]);
        Activity::log('automation.deleted', $a['name'], (int) $a['project_id']);
    }

    // ------------------------------------------------------------------
    // Enrolment
    // ------------------------------------------------------------------

    /** Enrols a contact (once per automation and cycle). Returns true when it is new. */
    public static function enroll(array $a, int $contactId, string $source, string $cycle = ''): bool
    {
        $ok = DB::value("SELECT id FROM contacts WHERE id = ? AND project_id = ? AND status = 'subscribed'", [$contactId, $a['project_id']]);
        if (!$ok) {
            return false;
        }
        $trigger = self::trigger(self::decode($a));
        $now = now_utc();
        $n = DB::exec('INSERT IGNORE INTO automation_runs (automation_id, contact_id, cycle, step_id, status, next_at, source, entered_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$a['id'], $contactId, $cycle, $trigger['id'], 'active', $now, mb_substr($source, 0, 60), $now, $now]);
        if ($n !== 1) {
            return false;
        }
        $run = (int) DB::pdo()->lastInsertId();
        self::log($a, $run, $contactId, $trigger['id'], 'enter', $source);
        return true;
    }

    /** Manual enrolment of a list / segment selection. */
    public static function enrollAudience(array $a, array $audience): int
    {
        $n = 0;
        foreach (Audience::contacts(Audience::clean($audience), (int) $a['project_id']) as $ct) {
            $n += self::enroll($a, (int) $ct['id'], 'manual') ? 1 : 0;
        }
        return $n;
    }

    /** API event: enrols the contact in the active automations waiting for it. */
    public static function fireEvent(int $projectId, string $event, int $contactId): int
    {
        $event = strtolower(trim($event));
        $n = 0;
        foreach (DB::all("SELECT * FROM automations WHERE project_id = ? AND status = 'active' AND trigger_type = 'event'", [$projectId]) as $a) {
            if (self::trigger(self::decode($a))['config']['event'] === $event) {
                $n += self::enroll($a, $contactId, 'esdeveniment: ' . $event) ? 1 : 0;
            }
        }
        return $n;
    }

    public static function remove(array $run, string $reason): void
    {
        if ($run['status'] === 'active') {
            DB::update('automation_runs', ['status' => 'exited', 'exit_reason' => mb_substr($reason, 0, 190), 'finished_at' => now_utc(), 'updated_at' => now_utc()], 'id = ?', [$run['id']]);
            $a = DB::one('SELECT * FROM automations WHERE id = ?', [$run['automation_id']]);
            if ($a) {
                self::log($a, (int) $run['id'], (int) $run['contact_id'], $run['step_id'], 'exit', $reason);
            }
        }
    }

    // ------------------------------------------------------------------
    // Worker
    // ------------------------------------------------------------------

    public static function tick(int $limit = 500): array
    {
        $out = ['enrolled' => 0, 'steps' => 0, 'exited' => 0];
        $out['exited'] = self::exitUnsubscribed();
        foreach (DB::all("SELECT * FROM automations WHERE status = 'active' AND trigger_type IN ('list', 'form', 'date')") as $a) {
            try {
                $out['enrolled'] += self::scan($a);
            } catch (\Throwable $e) {
                logger('Automation ' . $a['id'] . ' scan: ' . $e->getMessage(), [], 'automations');
            }
        }
        $runs = DB::all("SELECT r.* FROM automation_runs r JOIN automations a ON a.id = r.automation_id
            WHERE r.status = 'active' AND r.next_at <= UTC_TIMESTAMP() AND a.status = 'active' ORDER BY r.next_at, r.id LIMIT " . max(1, $limit));
        $cache = [];
        foreach ($runs as $run) {
            $aid = (int) $run['automation_id'];
            if (!isset($cache[$aid])) {
                $a = DB::one('SELECT * FROM automations WHERE id = ?', [$aid]);
                $cache[$aid] = [$a, self::decode($a), DB::one('SELECT * FROM projects WHERE id = ?', [$a['project_id']])];
            }
            try {
                $out['steps'] += self::advance($cache[$aid][0], $cache[$aid][1], $run);
            } catch (\Throwable $e) {
                logger('Automation run ' . $run['id'] . ': ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()], 'automations');
                DB::update('automation_runs', ['next_at' => gmdate('Y-m-d H:i:s', time() + 900), 'updated_at' => now_utc()], 'id = ?', [$run['id']]);
                self::log($cache[$aid][0], (int) $run['id'], (int) $run['contact_id'], $run['step_id'], 'error', mb_substr($e->getMessage(), 0, 400));
            }
        }
        return $out;
    }

    /** Whoever unsubscribed, bounced, complained or was deleted leaves every flow. */
    private static function exitUnsubscribed(): int
    {
        $rows = DB::all("SELECT r.id, r.automation_id, r.contact_id, r.step_id, c.status AS cstatus FROM automation_runs r LEFT JOIN contacts c ON c.id = r.contact_id
            WHERE r.status = 'active' AND (c.id IS NULL OR c.status <> 'subscribed') LIMIT 1000");
        foreach ($rows as $r) {
            $reason = $r['cstatus'] ? 'Contacte ' . mb_strtolower(Contacts::STATUSES[$r['cstatus']] ?? $r['cstatus']) : 'Contacte eliminat';
            DB::update('automation_runs', ['status' => 'exited', 'exit_reason' => $reason, 'finished_at' => now_utc(), 'updated_at' => now_utc()], 'id = ?', [$r['id']]);
            DB::insert('automation_log', ['automation_id' => $r['automation_id'], 'run_id' => $r['id'], 'contact_id' => $r['cstatus'] ? $r['contact_id'] : null,
                'step_id' => $r['step_id'], 'type' => 'exit', 'detail' => $reason, 'created_at' => now_utc()]);
        }
        return count($rows);
    }

    /** New list members / form signups since the last scan, or today's dates. */
    private static function scan(array $a): int
    {
        $t = self::trigger(self::decode($a))['config'];
        $pid = (int) $a['project_id'];
        $n = 0;
        if ($t['kind'] === 'date') {
            $now = new \DateTimeImmutable('now', app_tz());
            $today = $now->format('Y-m-d');
            if ($t['field'] === '' || ($a['scan_day'] && $a['scan_day'] >= $today) || $now->format('H:i') < $t['at']) {
                return 0;
            }
            DB::update('automations', ['scan_day' => $today], 'id = ?', [$a['id']]);
            // The day whose date (plus the offset) is today.
            $target = $now->modify(($t['offset'] >= 0 ? '-' : '+') . abs($t['offset']) . ' days');
            $rows = $t['field'] === 'created_at'
                ? DB::all("SELECT id, created_at AS v FROM contacts WHERE project_id = ? AND status = 'subscribed'", [$pid])
                : DB::all("SELECT id, JSON_UNQUOTE(JSON_EXTRACT(fields, ?)) AS v FROM contacts WHERE project_id = ? AND status = 'subscribed' AND fields IS NOT NULL", ['$.' . substr($t['field'], 5), $pid]);
            foreach ($rows as $r) {
                $d = self::parseDate((string) $r['v'], $t['field'] === 'created_at');
                if ($d && self::dateMatches($d, $target, $t['repeat'] === 'yearly')) {
                    $n += self::enroll($a, (int) $r['id'], 'data: ' . $d, $t['repeat'] === 'yearly' ? $now->format('Y') : '') ? 1 : 0;
                }
            }
            return $n;
        }
        $from = $a['scan_at'] ?: now_utc();
        $until = now_utc();
        if ($t['kind'] === 'list' && $t['list_id']) {
            $rows = DB::all("SELECT lc.contact_id, lc.source FROM list_contacts lc JOIN contacts c ON c.id = lc.contact_id JOIN lists l ON l.id = lc.list_id
                WHERE lc.list_id = ? AND l.project_id = ? AND lc.status = 'subscribed' AND c.status = 'subscribed' AND lc.added_at >= ? ORDER BY lc.added_at", [$t['list_id'], $pid, $from]);
            foreach ($rows as $r) {
                $n += self::enroll($a, (int) $r['contact_id'], 'llista' . ($r['source'] ? ' (' . $r['source'] . ')' : '')) ? 1 : 0;
            }
        } elseif ($t['kind'] === 'form' && $t['form_id']) {
            $rows = DB::all('SELECT s.contact_id FROM form_signups s JOIN forms f ON f.id = s.form_id WHERE s.form_id = ? AND f.project_id = ? AND s.contact_id IS NOT NULL AND s.confirmed_at >= ? ORDER BY s.confirmed_at',
                [$t['form_id'], $pid, $from]);
            foreach ($rows as $r) {
                $n += self::enroll($a, (int) $r['contact_id'], 'formulari') ? 1 : 0;
            }
        }
        DB::update('automations', ['scan_at' => $until], 'id = ?', [$a['id']]);
        return $n;
    }

    public static function parseDate(string $v, bool $datetime = false): ?string
    {
        $v = trim($v);
        if ($datetime) {
            return $v !== '' ? local_dt($v)?->format('Y-m-d') : null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    private static function dateMatches(string $date, \DateTimeImmutable $target, bool $yearly): bool
    {
        if (!$yearly) {
            return $date === $target->format('Y-m-d');
        }
        $md = substr($date, 5);
        // 29 February is celebrated on the 28th in non-leap years.
        if ($md === '02-29' && !$target->format('L')) {
            $md = '02-28';
        }
        return $md === $target->format('m-d');
    }

    /** Moves a run forward until it waits, finishes or leaves. Returns the steps done. */
    private static function advance(array $a, array $flow, array $run): int
    {
        $steps = self::stepsById($flow);
        $contact = DB::one('SELECT * FROM contacts WHERE id = ?', [$run['contact_id']]);
        $set = [];
        $done = 0;
        $finish = static function (string $status, ?string $reason) use (&$set): void {
            $set = ['status' => $status, 'exit_reason' => $reason, 'finished_at' => now_utc(), 'waiting' => 0];
        };
        $id = (string) $run['step_id'];
        $waiting = (bool) $run['waiting'];
        for ($guard = 0; $guard < 40; $guard++) {
            $step = $steps[$id] ?? null;
            if (!$step) {
                $finish('done', 'El pas ja no existeix');
                break;
            }
            if (!$contact || $contact['status'] !== 'subscribed') {
                $finish('exited', $contact ? 'Contacte ' . mb_strtolower(Contacts::STATUSES[$contact['status']] ?? $contact['status']) : 'Contacte eliminat');
                self::log($a, (int) $run['id'], (int) $run['contact_id'], $id, 'exit', $set['exit_reason']);
                break;
            }
            $c = $step['config'];
            $branch = '';
            // An incomplete step (edited while active) holds the contact until it is fixed.
            $problem = self::notReady($a, $flow, $step, $contact);
            if ($problem !== null) {
                $set = ['step_id' => $id, 'waiting' => 0, 'next_at' => gmdate('Y-m-d H:i:s', time() + 900)];
                if (!DB::value("SELECT COUNT(*) FROM automation_log WHERE run_id = ? AND step_id = ? AND type = 'hold'", [$run['id'], $id])) {
                    self::log($a, (int) $run['id'], (int) $contact['id'], $id, 'hold', $problem);
                }
                break;
            }
            switch ($step['type']) {
                case 'email':
                    self::log($a, (int) $run['id'], (int) $contact['id'], $id, 'email', self::queueEmail($a, $run, $c, $contact));
                    break;
                case 'delay':
                    if (!$waiting) {
                        $until = self::waitUntil($c, time());
                        $set = ['step_id' => $id, 'waiting' => 1, 'next_at' => $until];
                        self::log($a, (int) $run['id'], (int) $contact['id'], $id, 'wait', 'Fins al ' . fmt_dt($until));
                        break 2;
                    }
                    $waiting = false;
                    break;
                case 'condition':
                    $yes = self::evaluate($c, $flow, $contact, (int) $run['id'], (int) $a['project_id']);
                    self::log($a, (int) $run['id'], (int) $contact['id'], $id, $yes ? 'yes' : 'no', self::describeStep($step, $flow, (int) $a['project_id']));
                    if (!empty($c['branched'])) {
                        $branch = $yes ? 'yes' : 'no';
                    } elseif (!$yes) {
                        $finish('done', 'No compleix la condició');
                        $done++;
                        break 2;
                    }
                    break;
                case 'action':
                    self::log($a, (int) $run['id'], (int) $contact['id'], $id, 'action', self::act($c, $contact, (int) $a['project_id']));
                    $contact = DB::one('SELECT * FROM contacts WHERE id = ?', [$run['contact_id']]);
                    break;
            }
            $done++;
            $next = self::next($flow, $id, $branch);
            if ($next === null) {
                $finish('done', null);
                self::log($a, (int) $run['id'], (int) $run['contact_id'], $id, 'done', null);
                break;
            }
            $id = $next;
            $set = ['step_id' => $id, 'waiting' => 0, 'next_at' => now_utc()];
        }
        $set['updated_at'] = now_utc();
        if (!isset($set['step_id']) && ($set['status'] ?? '') === '') {
            $set['step_id'] = $id;
        }
        DB::update('automation_runs', $set, 'id = ?', [$run['id']]);
        return $done;
    }

    /** Why a step cannot run yet (null = ready). */
    private static function notReady(array $a, array $flow, array $step, array $contact): ?string
    {
        $c = $step['config'];
        switch ($step['type']) {
            case 'email':
                $camp = $c['campaign_id'] ? DB::one("SELECT doc, audience FROM campaigns WHERE id = ? AND automation_id = ? AND status = 'auto'", [$c['campaign_id'], $a['id']]) : null;
                if (!$camp) {
                    return 'El correu encara no existeix';
                }
                $doc = Document::decode($camp['doc']);
                $l = $doc['langs'][Document::langFor($doc, $contact['language'] ?: null, Document::forcedLang($doc, Audience::clean($camp['audience'])))];
                return $l['subject'] === '' || !$l['doc']['blocks'] ? 'El correu no té assumpte o és buit' : null;
            case 'condition':
                if (in_array($c['kind'], ['opened', 'clicked'], true)) {
                    return in_array($c['step'], self::ancestors($flow, $step['id']), true) ? null : 'La condició no mira cap correu anterior';
                }
                return match ($c['kind']) {
                    'field' => $c['field'] === '' ? 'Falta el camp de la condició' : null,
                    'list' => $c['list_id'] ? null : 'Falta la llista de la condició',
                    'segment' => $c['segment_id'] ? null : 'Falta el segment de la condició',
                    default => null,
                };
            case 'action':
                if ($c['kind'] === 'set_field') {
                    return $c['field'] === '' ? 'Falta el camp de l\'acció' : null;
                }
                return $c['list_id'] ? null : 'Falta la llista de l\'acció';
        }
        return null;
    }

    public static function waitUntil(array $c, int $from): string
    {
        $t = $from + $c['amount'] * self::UNIT_SECONDS[$c['unit']];
        if ($c['at'] !== '') {
            $d = (new \DateTimeImmutable('@' . $t))->setTimezone(app_tz());
            [$h, $m] = array_map('intval', explode(':', $c['at']));
            $at = $d->setTime($h, $m);
            if ($at < $d) {
                $at = $at->modify('+1 day');
            }
            $t = $at->getTimestamp();
        }
        return gmdate('Y-m-d H:i:s', $t);
    }

    private static function queueEmail(array $a, array $run, array $c, array $contact): string
    {
        $camp = $c['campaign_id'] ? DB::one("SELECT id, doc, audience, subject FROM campaigns WHERE id = ? AND automation_id = ? AND status = 'auto'", [$c['campaign_id'], $a['id']]) : null;
        if (!$camp) {
            return 'Correu no trobat: no s\'ha enviat';
        }
        $doc = Document::decode($camp['doc']);
        $lang = Document::langFor($doc, $contact['language'] ?: null, Document::forcedLang($doc, Audience::clean($camp['audience'])));
        DB::exec('INSERT IGNORE INTO messages (campaign_id, contact_id, email, language, status, run_id) VALUES (?, ?, ?, ?, ?, ?)',
            [$camp['id'], $contact['id'], $contact['email'], $lang, 'queued', $run['id']]);
        return '«' . Document::subject($doc, $lang) . '» (' . strtoupper($lang) . ')';
    }

    /** Is the condition true for this contact right now? */
    public static function evaluate(array $c, array $flow, array $contact, int $runId, int $projectId): bool
    {
        switch ($c['kind']) {
            case 'opened':
            case 'clicked':
                $email = self::stepsById($flow)[$c['step']] ?? null;
                $cid = $email['config']['campaign_id'] ?? 0;
                $col = $c['kind'] === 'opened' ? 'opened_at' : 'clicked_at';
                $hit = $cid && DB::value("SELECT COUNT(*) FROM messages WHERE campaign_id = ? AND contact_id = ? AND run_id = ? AND $col IS NOT NULL", [$cid, $contact['id'], $runId]);
                return $c['op'] === 'no' ? !$hit : (bool) $hit;
            case 'field':
                $rules = ['conditions' => [['type' => 'field', 'field' => $c['field'], 'op' => $c['op'], 'value' => $c['value']]]];
                break;
            case 'list':
                $rules = ['conditions' => [['type' => 'list', 'op' => $c['op'], 'value' => $c['list_id']]]];
                break;
            case 'segment':
                $seg = DB::one('SELECT rules FROM segments WHERE id = ? AND project_id = ?', [$c['segment_id'], $projectId]);
                $sql = $seg ? Segments::compile(Segments::clean($seg['rules']), $projectId) : null;
                $in = $sql && DB::value('SELECT COUNT(*) FROM contacts c WHERE c.id = ? AND (' . $sql[0] . ')', array_merge([$contact['id']], $sql[1]));
                return $c['op'] === 'not_in' ? !$in : (bool) $in;
            default:
                return true;
        }
        $sql = Segments::compile($rules, $projectId);
        return !$sql || (bool) DB::value('SELECT COUNT(*) FROM contacts c WHERE c.id = ? AND (' . $sql[0] . ')', array_merge([$contact['id']], $sql[1]));
    }

    private static function act(array $c, array $contact, int $projectId): string
    {
        if ($c['kind'] === 'set_field') {
            if (str_starts_with($c['field'], 'camp:')) {
                $fields = json_decode((string) $contact['fields'], true) ?: [];
                $fields[substr($c['field'], 5)] = $c['value'];
                DB::update('contacts', ['fields' => json_encode($fields, JSON_UNESCAPED_UNICODE), 'updated_at' => now_utc()], 'id = ?', [$contact['id']]);
            } elseif (in_array($c['field'], ['first_name', 'last_name'], true)) {
                DB::update('contacts', [$c['field'] => mb_substr($c['value'], 0, 120), 'updated_at' => now_utc()], 'id = ?', [$contact['id']]);
            } elseif ($c['field'] === 'language') {
                DB::update('contacts', ['language' => Contacts::normalizeLang($c['value']), 'updated_at' => now_utc()], 'id = ?', [$contact['id']]);
            }
            return 'Camp ' . self::fieldLabel($c['field'], $projectId) . ' = «' . $c['value'] . '»';
        }
        $list = DB::one('SELECT id, name FROM lists WHERE id = ? AND project_id = ?', [$c['list_id'], $projectId]);
        if (!$list) {
            return 'Llista no trobada';
        }
        if ($c['kind'] === 'list_add') {
            DB::exec("INSERT INTO list_contacts (list_id, contact_id, status, source, added_at) VALUES (?, ?, 'subscribed', 'automatització', ?) ON DUPLICATE KEY UPDATE status = 'subscribed'",
                [$list['id'], $contact['id'], now_utc()]);
            return 'Afegit a «' . $list['name'] . '»';
        }
        DB::exec('DELETE FROM list_contacts WHERE list_id = ? AND contact_id = ?', [$list['id'], $contact['id']]);
        return 'Tret de «' . $list['name'] . '»';
    }

    private static function log(array $a, ?int $runId, ?int $contactId, ?string $step, string $type, ?string $detail): void
    {
        DB::insert('automation_log', ['automation_id' => $a['id'], 'run_id' => $runId, 'contact_id' => $contactId, 'step_id' => $step,
            'type' => $type, 'detail' => $detail !== null ? mb_substr($detail, 0, 500) : null, 'created_at' => now_utc()]);
    }

    // ------------------------------------------------------------------
    // Test path, labels and statistics
    // ------------------------------------------------------------------

    /**
     * The path a contact would follow now (waits skipped; "opened" uses
     * their current run in this automation, if any).
     *
     * @return array<int, array{step: string, label: string, result?: string}>
     */
    public static function simulate(array $a, array $contact): array
    {
        $flow = self::decode($a);
        $steps = self::stepsById($flow);
        $runId = (int) DB::value('SELECT id FROM automation_runs WHERE automation_id = ? AND contact_id = ? ORDER BY id DESC LIMIT 1', [$a['id'], $contact['id']]);
        $out = [];
        $id = self::trigger($flow)['id'];
        for ($guard = 0; $guard < self::MAX_STEPS && $id !== null; $guard++) {
            $s = $steps[$id];
            $branch = '';
            $row = ['step' => $id, 'label' => self::describeStep($s, $flow, (int) $a['project_id'])];
            if ($s['type'] === 'condition') {
                $yes = self::evaluate($s['config'], $flow, $contact, $runId, (int) $a['project_id']);
                $row['result'] = $yes ? 'Sí' : 'No';
                $out[] = $row;
                if (!empty($s['config']['branched'])) {
                    $branch = $yes ? 'yes' : 'no';
                } elseif (!$yes) {
                    $out[] = ['step' => '', 'label' => 'Surt del flux: no compleix la condició.'];
                    break;
                }
            } else {
                $out[] = $row;
            }
            $id = self::next($flow, $id, $branch);
        }
        return $out;
    }

    public static function fieldLabel(string $field, int $projectId): string
    {
        if (str_starts_with($field, 'camp:')) {
            $key = substr($field, 5);
            return (string) (DB::value('SELECT label FROM fields WHERE project_id = ? AND `key` = ?', [$projectId, $key]) ?: $key);
        }
        return (Contacts::BASE_FIELDS + ['created_at' => 'Data d\'alta'])[$field] ?? $field;
    }

    /** One-line description of a step (activity and test path). */
    public static function describeStep(array $s, array $flow, int $projectId): string
    {
        $c = $s['config'];
        $listName = static fn (int $id): string => (string) (DB::value('SELECT name FROM lists WHERE id = ? AND project_id = ?', [$id, $projectId]) ?: '—');
        switch ($s['type']) {
            case 'trigger':
                $d = self::TRIGGERS[$c['kind']][0];
                return match ($c['kind']) {
                    'list' => $d . ': «' . $listName($c['list_id']) . '»',
                    'form' => $d . ': «' . (DB::value('SELECT title FROM forms WHERE id = ? AND project_id = ?', [$c['form_id'], $projectId]) ?: '—') . '»',
                    'event' => $d . ': «' . $c['event'] . '»',
                    'date' => $d . ': ' . self::fieldLabel($c['field'], $projectId),
                    default => $d,
                };
            case 'email':
                return 'Correu «' . ($c['name'] ?: 'sense nom') . '»';
            case 'delay':
                return 'Espera ' . $c['amount'] . ' ' . self::UNITS[$c['unit']] . ($c['at'] !== '' ? ' (i fins a les ' . $c['at'] . ')' : '');
            case 'condition':
                $steps = self::stepsById($flow);
                return match ($c['kind']) {
                    'opened' => ($c['op'] === 'no' ? 'No ha obert' : 'Ha obert') . ' «' . ($steps[$c['step']]['config']['name'] ?? '—') . '»',
                    'clicked' => ($c['op'] === 'no' ? 'No ha clicat' : 'Ha clicat') . ' «' . ($steps[$c['step']]['config']['name'] ?? '—') . '»',
                    'list' => ($c['op'] === 'not_in' ? 'No és a la llista' : 'És a la llista') . ' «' . $listName($c['list_id']) . '»',
                    'segment' => ($c['op'] === 'not_in' ? 'No és al segment' : 'És al segment') . ' «' . (DB::value('SELECT name FROM segments WHERE id = ? AND project_id = ?', [$c['segment_id'], $projectId]) ?: '—') . '»',
                    default => self::fieldLabel($c['field'], $projectId) . ' ' . Segments::FIELD_OPS[$c['op']] . (in_array($c['op'], ['empty', 'not_empty'], true) ? '' : ' «' . $c['value'] . '»'),
                };
            case 'action':
                return match ($c['kind']) {
                    'list_add' => 'Afegeix a la llista «' . $listName($c['list_id']) . '»',
                    'list_remove' => 'Treu de la llista «' . $listName($c['list_id']) . '»',
                    default => 'Posa ' . self::fieldLabel($c['field'], $projectId) . ' = «' . $c['value'] . '»',
                };
        }
        return '';
    }

    /**
     * Figures for each step of the editor.
     *
     * @return array<string, array<string, int>>
     */
    public static function stepStats(array $a): array
    {
        $out = [];
        foreach (DB::all("SELECT step_id, COUNT(*) AS n FROM automation_runs WHERE automation_id = ? AND status = 'active' GROUP BY step_id", [$a['id']]) as $r) {
            $out[$r['step_id']]['here'] = (int) $r['n'];
        }
        foreach (DB::all("SELECT step_id, type, COUNT(*) AS n FROM automation_log WHERE automation_id = ? AND type IN ('enter', 'yes', 'no', 'action', 'wait') GROUP BY step_id, type", [$a['id']]) as $r) {
            $out[$r['step_id']][$r['type']] = (int) $r['n'];
        }
        foreach (self::decode($a)['steps'] as $s) {
            if ($s['type'] === 'email' && $s['config']['campaign_id']) {
                $st = Stats::campaign((int) $s['config']['campaign_id']);
                $out[$s['id']] = ($out[$s['id']] ?? []) + ['sent' => $st['sent'], 'queued' => $st['queued'], 'opened' => $st['opened'], 'clicked' => $st['clicked'], 'unsub' => $st['unsub']];
            }
        }
        return $out;
    }

    /** @return array{total:int, active:int, done:int, exited:int} */
    public static function totals(int $automationId): array
    {
        $r = DB::one("SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(status = 'done') AS done, SUM(status = 'exited') AS exited FROM automation_runs WHERE automation_id = ?", [$automationId]) ?? [];
        return array_map('intval', $r);
    }
}
