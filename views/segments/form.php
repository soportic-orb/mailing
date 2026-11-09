<?php
use Mailing\Services\Contacts;
use Mailing\Services\Segments;
$fieldOpts = Contacts::BASE_FIELDS;
unset($fieldOpts['external_id']);
$fieldOpts['created_at'] = 'Data d\'alta';
foreach ($fields as $fd) { $fieldOpts['camp:' . $fd['key']] = $fd['label']; }
?>
<a class="crumb" href="/segments">← Segments</a>
<div class="page-head"><div><h1 class="page-title"><?= $s ? e($s['name']) : 'Segment nou' ?></h1></div></div>
<form method="post" action="<?= $s ? '/segments/' . (int) $s['id'] : '/segments' ?>" class="card" id="segf"><div class="card-body">
    <?= csrf_field() ?>
    <input type="hidden" name="rules" id="rules">
    <label class="field" style="max-width:480px"><span>Nom</span><input class="input" name="name" required maxlength="120" value="<?= e($s['name'] ?? old('name')) ?>"></label>
    <p style="margin:0 0 12px">Contactes subscrits que compleixen
        <select class="input" id="match" style="width:auto;display:inline-block"><option value="all">totes</option><option value="any">alguna</option></select>
        d'aquestes condicions:</p>
    <div id="conds" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px"></div>
    <div class="btns" style="margin-bottom:18px">
        <button type="button" class="btn sm secondary" data-add="field">+ Dada del contacte</button>
        <button type="button" class="btn sm secondary" data-add="list">+ Llista</button>
        <button type="button" class="btn sm secondary" data-add="activity">+ Activitat (obertures, clics)</button>
    </div>
    <p class="alert info"><b id="seg-count">—</b> contactes compleixen ara mateix aquestes condicions.</p>
    <button class="btn" type="submit">Desa el segment</button>
</div></form>
<script>
(function () {
    var RULES = <?= json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var FIELDS = <?= json_encode($fieldOpts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var FOPS = <?= json_encode(Segments::FIELD_OPS, JSON_UNESCAPED_UNICODE) ?>;
    var AOPS = <?= json_encode(Segments::ACTIVITY_OPS, JSON_UNESCAPED_UNICODE) ?>;
    var LISTS = <?= json_encode(array_map(static fn ($l) => [(int) $l['id'], $l['name']], $lists), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var CAMPS = <?= json_encode(array_map(static fn ($c) => [(int) $c['id'], $c['name']], $campaigns), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var box = document.getElementById('conds'), timer;
    function sel(opts, val) {
        var s = document.createElement('select'); s.className = 'input'; s.style.width = 'auto';
        opts.forEach(function (o) { var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; if (String(o[0]) === String(val)) op.selected = true; s.appendChild(op); });
        s.addEventListener('change', recount); return s;
    }
    function pairs(obj) { return Object.keys(obj).map(function (k) { return [k, obj[k]]; }); }
    function row(c) {
        var r = document.createElement('div'); r.className = 'btns'; r.style.background = '#f8f9fb'; r.style.padding = '8px'; r.style.borderRadius = '10px'; r.dataset.type = c.type;
        if (c.type === 'field') {
            var f = sel(pairs(FIELDS), c.field), op = sel(pairs(FOPS), c.op), v = document.createElement('input');
            v.className = 'input'; v.style.width = '220px'; v.value = c.value || ''; v.placeholder = 'valor (data: AAAA-MM-DD)';
            v.addEventListener('input', recount);
            var tog = function () { v.hidden = op.value === 'empty' || op.value === 'not_empty'; }; op.addEventListener('change', tog); tog();
            r.append(f, op, v); r.get = function () { return { type: 'field', field: f.value, op: op.value, value: v.value }; };
        } else if (c.type === 'list') {
            var o = sel([['in', 'és a la llista'], ['not_in', 'no és a la llista']], c.op), l = sel(LISTS.length ? LISTS : [[0, '(no hi ha llistes)']], c.value);
            r.append(o, l); r.get = function () { return { type: 'list', op: o.value, value: +l.value }; };
        } else {
            var a = sel(pairs(AOPS), c.op), ca = sel([[0, 'cap campanya']].concat(CAMPS), c.campaign), d = sel([[0, 'mai / qualsevol data'], [7, 'últims 7 dies'], [30, 'últims 30 dies'], [90, 'últims 90 dies'], [180, 'últims 6 mesos'], [365, 'últim any']], c.days);
            r.append(a, ca, d); r.get = function () { return { type: 'activity', op: a.value, campaign: +ca.value, days: +d.value }; };
        }
        var x = document.createElement('button'); x.type = 'button'; x.className = 'btn sm ghost'; x.textContent = '✕'; x.setAttribute('aria-label', 'Treu la condició');
        x.addEventListener('click', function () { r.remove(); recount(); });
        r.appendChild(x); box.appendChild(r);
    }
    function collect() { return { match: document.getElementById('match').value, conditions: Array.prototype.map.call(box.children, function (r) { return r.get(); }) }; }
    function recount() {
        clearTimeout(timer);
        timer = setTimeout(function () {
            mlApi('/segments/compta', { method: 'POST', json: { rules: JSON.stringify(collect()) } }).then(function (r) { return r.json(); }).then(function (j) { document.getElementById('seg-count').textContent = j.ok ? j.count.toLocaleString('ca') : '—'; });
        }, 300);
    }
    document.getElementById('match').value = RULES.match; document.getElementById('match').addEventListener('change', recount);
    RULES.conditions.forEach(row);
    document.querySelectorAll('[data-add]').forEach(function (b) { b.addEventListener('click', function () {
        var t = b.dataset.add;
        row(t === 'field' ? { type: 'field', field: 'first_name', op: 'contains', value: '' } : t === 'list' ? { type: 'list', op: 'in', value: LISTS[0] ? LISTS[0][0] : 0 } : { type: 'activity', op: 'opened', campaign: 0, days: 90 });
        recount();
    }); });
    document.getElementById('segf').addEventListener('submit', function () { document.getElementById('rules').value = JSON.stringify(collect()); });
    if (!RULES.conditions.length) row({ type: 'field', field: 'first_name', op: 'not_empty', value: '' });
    recount();
})();
</script>
