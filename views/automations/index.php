<?php use Mailing\Core\Auth; use Mailing\Services\Automations; ?>
<div class="page-head">
    <div><h1 class="page-title">Automatitzacions</h1><p class="page-sub">Fluxos de correus que s'envien sols: quan algú s'apunta a una llista, en una data o quan una altra plataforma avisa d'un esdeveniment.</p></div>
    <?php if (Auth::canEdit()): ?><a class="btn" href="#nova" onclick="document.getElementById('nova').open = true">+ Nova automatització</a><?php endif; ?>
</div>

<?php if (Auth::canEdit()): ?>
<details class="card" id="nova" <?= $rows ? '' : 'open' ?>>
    <summary class="card-head" style="cursor:pointer">Nova automatització</summary>
    <form method="post" action="/automatitzacions" class="card-body">
        <?= csrf_field() ?>
        <label class="field"><span>Nom</span><input class="input" name="name" maxlength="190" required placeholder="Benvinguda al butlletí"></label>
        <span class="field-label">Comença amb</span>
        <div class="tpls auto-presets">
            <label class="tpl"><input type="radio" name="preset" value="" checked> <b>Un flux en blanc</b><small>Només el disparador: hi afegeixes els passos amb el botó ＋.</small>
                <select class="input" name="trigger" style="margin-top:8px">
                    <?php foreach (Automations::TRIGGERS as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t[0]) ?></option><?php endforeach; ?>
                </select></label>
            <label class="tpl"><input type="radio" name="preset" value="welcome"> <b>Benvinguda (3 correus)</b><small>Alta a una llista → correu de benvinguda → 3 dies → si l'ha obert, un segon correu; si no, un recordatori.</small></label>
            <label class="tpl"><input type="radio" name="preset" value="birthday"> <b>Aniversari</b><small>Cada any, el dia de la data d'un camp (naixement, renovació…), un correu.</small></label>
        </div>
        <button class="btn" type="submit">Crea l'automatització</button>
    </form>
</details>
<?php endif; ?>

<div class="card">
<?php if ($rows): ?>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Automatització</th><th>Estat</th><th class="num">Inscrits</th><th class="num">Ara dins</th><th class="num">Correus enviats</th><th class="num">Obertura</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a class="t-main" href="/automatitzacions/<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a><div class="sub"><?= e($r['trigger']) ?></div></td>
                <td><span class="st <?= e(['active' => 'sending', 'paused' => 'paused', 'draft' => 'draft'][$r['status']] ?? 'draft') ?>"><?= e(Automations::STATUSES[$r['status']] ?? $r['status']) ?></span></td>
                <td class="num"><?= fmt_n($r['n_total']) ?></td>
                <td class="num"><?= fmt_n($r['n_active']) ?></td>
                <td class="num"><?= fmt_n($r['n_sent']) ?></td>
                <td class="num"><?= $r['n_sent'] ? pct($r['n_open'], $r['n_sent']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php else: ?>
    <div class="empty"><b>Encara no hi ha cap automatització</b>Crea'n una per enviar correus de benvinguda, d'aniversari o de seguiment sense haver-hi de pensar.</div>
<?php endif; ?>
</div>
