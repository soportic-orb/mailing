<div class="page-head"><div><h1 class="page-title">Activitat</h1><p class="page-sub">Registre de les accions fetes a la plataforma.</p></div></div>
<div class="card"><div class="table-wrap"><table class="t"><thead><tr><th>Quan</th><th>Qui</th><th>Projecte</th><th>Acció</th><th>Detall</th></tr></thead><tbody>
<?php foreach ($page['rows'] as $a): ?><tr><td class="small"><?= e(fmt_short($a['created_at'])) ?></td><td class="small"><?= e($a['who'] ?? 'Sistema') ?></td><td class="small"><?= e($a['project'] ?? '—') ?></td><td><code><?= e($a['action']) ?></code></td><td class="small"><?= e($a['detail']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php include __DIR__ . '/../partials/pager.php'; ?></div>
