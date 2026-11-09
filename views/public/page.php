<?php if ($logo): ?><img class="bare-logo" src="<?= e($logo) ?>" alt="<?= e($name) ?>"><?php elseif ($name): ?><p style="font-weight:800;font-size:18px;margin:0 0 16px"><?= e($name) ?></p><?php endif; ?>
<?= $html ?>
