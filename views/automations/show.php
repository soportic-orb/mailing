<?php
use Mailing\Core\Auth;
use Mailing\Core\View;
use Mailing\Services\Automations;
$base = '/automatitzacions/' . (int) $a['id'];
$sentN = (int) ($sent['sent'] ?? 0);
$problems = array_values(array_filter($checks, static fn ($c) => !$c['ok']));
$stClass = ['active' => 'sending', 'paused' => 'paused', 'draft' => 'draft'][$a['status']] ?? 'draft';
?>
<a class="crumb" href="/automatitzacions">← Automatitzacions</a>
<div class="page-head">
    <div><h1 class="page-title"><?= e($a['name']) ?> <span class="st <?= e($stClass) ?>" style="vertical-align:4px"><?= e(Automations::STATUSES[$a['status']] ?? $a['status']) ?></span></h1>
        <p class="page-sub"><?= $a['status'] === 'active' ? 'Activa des del ' . e(fmt_dt($a['activated_at'])) . '.' : ($a['status'] === 'paused' ? 'Pausada: els contactes es queden on són i no s\'envia res.' : 'Esborrany: prepara el flux i activa-la quan estigui a punt.') ?></p></div>
    <?php if (Auth::canEdit()): ?>
    <div class="btns">
        <?php if ($a['status'] === 'active'): ?>
            <form method="post" action="<?= $base ?>/pausa" data-confirm="Pausar l'automatització? No s'enviarà res fins que la tornis a activar."><?= csrf_field() ?><button class="btn secondary" type="submit">Pausa</button></form>
        <?php else: ?>
            <form method="post" action="<?= $base ?>/activa" id="activate-form"><?= csrf_field() ?><button class="btn ok" type="submit"><?= $a['status'] === 'paused' ? 'Reactiva' : 'Activa' ?></button></form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="kpis" style="margin-bottom:16px">
    <div class="kpi"><span>Inscrits</span><b><?= fmt_n($totals['total']) ?></b><small>des que es va activar</small></div>
    <div class="kpi"><span>Ara dins</span><b><?= fmt_n($totals['active']) ?></b><small>esperant o en curs</small></div>
    <div class="kpi"><span>Han acabat</span><b><?= fmt_n($totals['done']) ?></b><small><?= fmt_n($totals['exited']) ?> n'han sortit (baixes…)</small></div>
    <div class="kpi"><span>Correus enviats</span><b><?= fmt_n($sentN) ?></b><small>entre tots els correus</small></div>
    <div class="kpi"><span>Obertura</span><b><?= $sentN ? pct((int) $sent['opened'], $sentN) : '—' ?></b><small><?= $sentN ? pct((int) $sent['clicked'], $sentN) . ' han clicat' : 'encara cap enviament' ?></small></div>
</div>

<nav class="subtabs" aria-label="Apartats">
    <a href="<?= $base ?>"<?= $tab === 'flux' ? ' class="active"' : '' ?>>Flux</a>
    <a href="<?= $base ?>?vista=activitat"<?= $tab === 'activitat' ? ' class="active"' : '' ?>>Activitat</a>
    <a href="<?= $base ?>?vista=configuracio"<?= $tab === 'configuracio' ? ' class="active"' : '' ?>>Configuració</a>
</nav>

<?php if ($tab === 'flux'): ?>
    <div id="flow-checks" class="card"<?= $problems ? '' : ' hidden' ?>>
        <div class="card-body"><ul class="checks">
            <?php foreach ($problems as $c): ?><li class="<?= $c['block'] ? 'block' : 'warn' ?>"><?= e($c['label']) ?></li><?php endforeach; ?>
        </ul></div>
    </div>
    <div id="flow-app" class="flow-app" aria-label="Editor del flux"></div>
    <?php View::section('head', '<link rel="stylesheet" href="/assets/flow.css?v=' . e(app_version()) . '">'); ?>
    <?php View::section('foot', '<script>window.FLOW_CFG = ' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script><script src="/assets/flow.js?v=' . e(app_version()) . '"></script>'); ?>

<?php elseif ($tab === 'activitat'):
    $stepsById = array_column($flow['steps'], null, 'id');
    $runSt = ['active' => ['sending', 'Dins'], 'done' => ['sent', 'Ha acabat'], 'exited' => ['cancelled', 'Ha sortit']];
    $logLabels = ['enter' => 'Entra', 'email' => 'Correu', 'wait' => 'Espera', 'yes' => 'Sí', 'no' => 'No', 'action' => 'Acció', 'done' => 'Acaba', 'exit' => 'Surt', 'error' => 'Error', 'hold' => 'En espera'];
?>
    <div class="grid side">
        <div class="card">
            <form class="toolbar" method="get">
                <input type="hidden" name="vista" value="activitat">
                <div class="chips">
                    <?php foreach (['' => 'Tots', 'active' => 'Dins', 'done' => 'Han acabat', 'exited' => 'Han sortit'] as $k => $l): ?>
                        <a class="chip" href="<?= $base ?>?vista=activitat<?= $k !== '' ? '&estat=' . $k : '' ?>" style="text-decoration:none;<?= $filter === $k ? 'background:var(--accent-soft);color:var(--accent-dark);font-weight:700' : '' ?>"><?= e($l) ?></a>
                    <?php endforeach; ?>
                </div>
                <span style="flex:1"></span>
                <?php if ($filter !== ''): ?><input type="hidden" name="estat" value="<?= e($filter) ?>"><?php endif; ?>
                <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Cerca per correu">
            </form>
            <?php if ($page['rows']): ?>
            <div class="table-wrap"><table class="t">
                <thead><tr><th>Contacte</th><th>Estat</th><th>On és</th><th>Entrada</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($page['rows'] as $r): $st = $runSt[$r['status']] ?? ['draft', $r['status']]; $step = $stepsById[$r['step_id']] ?? null; ?>
                    <tr>
                        <td><?= $r['email'] ? '<a class="t-main" href="/contactes/' . (int) $r['contact_id'] . '">' . e($r['email']) . '</a>' : '<span class="muted">Contacte eliminat</span>' ?><div class="sub"><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?><?= $r['source'] ? ' · ' . e($r['source']) : '' ?></div></td>
                        <td><span class="st <?= e($st[0]) ?>"><?= e($st[1]) ?></span><?= $r['exit_reason'] ? '<div class="sub">' . e($r['exit_reason']) . '</div>' : '' ?></td>
                        <td class="small"><?php if ($r['status'] === 'active'): ?><?= $step ? e(Automations::describeStep($step, $flow, (int) $project['id'])) : '—' ?><?php if ($r['waiting'] && $r['next_at']): ?><div class="sub">fins al <?= e(fmt_dt($r['next_at'])) ?></div><?php endif; ?><?php else: ?><span class="muted"><?= e(fmt_dt($r['finished_at'])) ?></span><?php endif; ?></td>
                        <td class="small"><?= e(fmt_dt($r['entered_at'])) ?></td>
                        <td class="num"><?php if ($r['status'] === 'active' && Auth::canEdit()): ?><form method="post" action="<?= $base ?>/inscripcions/<?= (int) $r['id'] ?>/treu" data-confirm="Treure aquest contacte de l'automatització? No rebrà cap més correu d'aquest flux."><?= csrf_field() ?><button class="btn sm ghost" type="submit">Treu</button></form><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php include __DIR__ . '/../partials/pager.php'; ?>
            <?php else: ?><div class="empty"><b>Encara no hi ha ningú</b><?= $a['status'] === 'active' ? 'Els contactes apareixeran aquí quan es compleixi el disparador.' : 'Activa l\'automatització perquè hi comencin a entrar contactes.' ?></div><?php endif; ?>
        </div>
        <div class="card">
            <div class="card-head">Registre recent</div>
            <?php if ($log): ?>
            <ul class="list auto-log">
                <?php foreach ($log as $l): ?>
                    <li><div class="main"><span class="pill <?= in_array($l['type'], ['error', 'exit', 'hold'], true) ? 'err' : ($l['type'] === 'email' ? 'accent' : '') ?>"><?= e($logLabels[$l['type']] ?? $l['type']) ?></span> <b class="small"><?= e($l['email'] ?: '—') ?></b>
                        <div class="sub"><?= e($l['detail'] ?? (isset($stepsById[$l['step_id']]) ? Automations::describeStep($stepsById[$l['step_id']], $flow, (int) $project['id']) : '')) ?> · <?= e(ago($l['created_at'])) ?></div></div></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?><div class="empty">Sense activitat.</div><?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <div class="grid two">
        <form method="post" action="<?= $base ?>" class="card">
            <?= csrf_field() ?>
            <div class="card-head">Configuració</div>
            <div class="card-body">
                <fieldset <?= Auth::canEdit() ? '' : 'disabled' ?> style="border:0;margin:0;padding:0">
                <label class="field"><span>Nom</span><input class="input" name="name" value="<?= e($a['name']) ?>" maxlength="190"></label>
                <label class="field"><span>Adreça d'emissió (remitent)</span>
                    <select class="input" name="sender_id">
                        <option value="">— Tria'n una —</option>
                        <?php foreach ($senders as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === (int) $a['sender_id'] ? 'selected' : '' ?>><?= e(($s['name'] ?: $s['email']) . ' <' . $s['email'] . '>') ?><?= strtolower((string) $s['mailjet_status']) === 'active' ? '' : ' (no validat)' ?></option><?php endforeach; ?>
                    </select><small>Tots els correus del flux surten des d'aquesta adreça. <a href="/remitents">Gestiona els remitents</a></small></label>
                <label class="field"><span>Respondre a (opcional)</span><input class="input" type="email" name="reply_to" value="<?= e($a['reply_to']) ?>" placeholder="La del remitent"></label>
                <?php if (Auth::canEdit()): ?><button class="btn" type="submit">Desa</button><?php endif; ?>
                </fieldset>
            </div>
        </form>

        <div>
            <form method="post" action="<?= $base ?>/inscriu" class="card" data-confirm="Inscriure ara els contactes subscrits d'aquestes llistes i segments?">
                <?= csrf_field() ?>
                <div class="card-head">Inscriu contactes</div>
                <div class="card-body">
                    <p class="small muted" style="margin-top:0">Fa entrar al flux, a partir d'ara mateix, els contactes subscrits de les llistes o segments que triïs. Qui ja hi ha estat no hi torna a entrar. <?= $a['status'] !== 'active' ? '<b>Cal que l\'automatització estigui activa.</b>' : '' ?></p>
                    <fieldset <?= Auth::canEdit() && $a['status'] === 'active' ? '' : 'disabled' ?> style="border:0;margin:0;padding:0">
                    <?php if ($lists): ?><p class="small muted" style="margin:4px 0 8px">Llistes</p><div class="chips" style="margin-bottom:12px"><?php foreach ($lists as $l): ?><label class="chip"><input type="checkbox" name="lists[]" value="<?= (int) $l['id'] ?>"> <?= e($l['name']) ?> <span class="muted">(<?= fmt_n($l['n']) ?>)</span></label><?php endforeach; ?></div><?php endif; ?>
                    <?php if ($segments): ?><p class="small muted" style="margin:4px 0 8px">Segments</p><div class="chips" style="margin-bottom:12px"><?php foreach ($segments as $sg): ?><label class="chip"><input type="checkbox" name="segments[]" value="<?= (int) $sg['id'] ?>"> <?= e($sg['name']) ?></label><?php endforeach; ?></div><?php endif; ?>
                    <button class="btn secondary" type="submit">Inscriu</button>
                    </fieldset>
                </div>
            </form>

            <?php if ($a['trigger_type'] === 'event'): $t = json_decode((string) $a['trigger_config'], true) ?: []; ?>
            <div class="card">
                <div class="card-head">Esdeveniment per API</div>
                <div class="card-body">
                    <p class="small muted" style="margin-top:0">L'altra plataforma fa aquesta crida amb la clau d'una connexió API del projecte (Sincronització). Si el contacte no existeix, es crea.</p>
                    <pre class="mono small" style="white-space:pre-wrap;background:#f6f7f9;padding:10px;border-radius:10px">POST <?= e(url('api/v1/events')) ?>

Authorization: Bearer &lt;clau&gt;
Content-Type: application/json

{"event": "<?= e($t['event'] ?? 'registre') ?>", "email": "persona@exemple.cat", "first_name": "Anna", "language": "ca", "fields": {"pla": "Pro"}}</pre>
                </div>
            </div>
            <?php endif; ?>

            <?php if (Auth::canEdit()): ?>
            <form method="post" action="<?= $base ?>/elimina" class="card" data-confirm="Eliminar l'automatització, els seus correus, les estadístiques i el registre? No es pot desfer.">
                <?= csrf_field() ?>
                <div class="card-head">Eliminar</div>
                <div class="card-body">
                    <p class="small muted" style="margin-top:0">S'eliminen el flux, els correus, les estadístiques i el registre. <?= $a['status'] === 'active' ? '<b>Pausa-la primer.</b>' : '' ?></p>
                    <button class="btn danger" type="submit" <?= $a['status'] === 'active' ? 'disabled' : '' ?>>Elimina l'automatització</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
