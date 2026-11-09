<?php $titles = [403 => 'Sense accés', 404 => 'No trobat', 405 => 'No permès', 419 => 'Sessió caducada', 500 => 'Error', 503 => 'En manteniment']; ?>
<h1><?= e($titles[$code] ?? 'Error') ?></h1>
<p class="muted" style="line-height:1.5"><?= e($message ?: 'Error ' . $code) ?></p>
<p><a class="btn secondary" href="/">Torna a l'inici</a></p>
