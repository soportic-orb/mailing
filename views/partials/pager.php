<?php if ($page['pages'] > 1):
    $qs = $_GET;
    $link = static function (int $n) use ($qs): string { $qs['pagina'] = $n; return '?' . http_build_query($qs); }; ?>
<div class="pager">
    <span class="muted"><?= fmt_n($page['total']) ?> en total · pàgina <?= $page['page'] ?> de <?= $page['pages'] ?></span>
    <?php if ($page['page'] > 1): ?><a class="btn sm secondary" href="<?= e($link($page['page'] - 1)) ?>">← Anterior</a><?php endif; ?>
    <?php if ($page['page'] < $page['pages']): ?><a class="btn sm secondary" href="<?= e($link($page['page'] + 1)) ?>">Següent →</a><?php endif; ?>
</div>
<?php endif; ?>
