<?php
// Miniatura d'un correu a partir dels seus blocs (del Mailing del Tauler).
$blocks = $doc['langs'][$doc['primary']]['doc']['blocks'] ?? [];
$s = $doc['langs'][$doc['primary']]['doc']['settings'] ?? [];
$acc = $s['accent'] ?? '#ac1966';
?>
<div class="thumb" aria-hidden="true">
<?php foreach (array_slice($blocks, 0, 12) as $b):
    $st = match ($b['type']) {
        'header' => 'height:16px;width:50%;margin:0 auto 4px;background:#d6d9de',
        'heading' => 'height:8px;width:70%;background:#3d4352',
        'text', 'box' => 'height:5px;width:100%;background:#d6d9de;box-shadow:0 8px 0 #d6d9de;margin-bottom:8px',
        'image', 'imagetext', 'post' => 'height:34px;width:100%;background:#eceef1',
        'button' => 'height:10px;width:40%;margin:2px auto;background:' . $acc . ';border-radius:4px',
        'columns' => 'height:26px;width:100%;background:linear-gradient(90deg,#eceef1 48%,transparent 48% 52%,#eceef1 52%)',
        'divider' => 'height:1px;width:100%;background:#d6d9de',
        'footer' => 'height:12px;width:60%;margin:4px auto 0;background:#eef0f2',
        default => 'height:6px;width:80%;background:#eceef1',
    }; ?>
    <span style="<?= e($st) ?>"></span>
<?php endforeach; ?>
</div>
