<?php use Mailing\Editor\Document; ?>
<a class="crumb" href="/campanyes">← Campanyes</a>
<div class="page-head"><div><h1 class="page-title">Nova campanya</h1><p class="page-sub">Després podràs editar el contingut amb l'editor visual i triar els destinataris.</p></div></div>
<form method="post" action="/campanyes" class="card"><div class="card-body">
    <?= csrf_field() ?>
    <div class="row">
        <label class="field"><span>Nom intern de la campanya</span><input class="input" name="name" required maxlength="190" value="<?= e(old('name')) ?>" placeholder="Ex.: Butlletí de novembre" autofocus><small>No el veuen els destinataris (l'assumpte s'escriu a l'editor).</small></label>
        <label class="field"><span>Adreça d'emissió</span>
            <select class="input" name="sender_id">
                <option value="">— Tria-la més tard —</option>
                <?php foreach ($senders as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === (int) $project['default_sender_id'] ? 'selected' : '' ?>><?= e($s['name'] . ' <' . $s['email'] . '>') ?></option><?php endforeach; ?>
            </select>
            <?php if (!$senders): ?><small>Aquest projecte encara no té remitents. <a href="/remitents">Afegeix-ne un</a>.</small><?php endif; ?>
        </label>
    </div>
    <span class="field-label">Comença a partir de</span>
    <div class="tpls" style="margin-bottom:18px">
        <label class="tpl"><input type="radio" name="template_id" value="" checked><div class="blank">+</div><b>Correu bàsic</b><span class="muted small">Capçalera, títol, text, botó i peu</span></label>
        <?php foreach ($templates as $t): $doc = Document::decode($t['doc']); ?>
            <label class="tpl"><input type="radio" name="template_id" value="<?= (int) $t['id'] ?>"><?php include __DIR__ . '/../partials/thumb.php'; ?><b><?= e($t['name']) ?></b></label>
        <?php endforeach; ?>
    </div>
    <button class="btn" type="submit">Crea i obre l'editor</button>
</div></form>
