<?php
use Mailing\Editor\MailDoc;
use Mailing\Services\Ai;

$isTemplate = $type === 'template';
$types = MailDoc::TYPES;
$contentTypes = ['event' => true, 'post' => true];
$icons = [
    'header' => '<path d="M4 4h16v6h-16z"/><path d="M4 14h10"/><path d="M4 18h7"/>',
    'heading' => '<path d="M7 12h10"/><path d="M7 5v14"/><path d="M17 5v14"/>',
    'text' => '<path d="M4 6h16"/><path d="M4 10h16"/><path d="M4 14h16"/><path d="M4 18h10"/>',
    'image' => '<path d="M15 8h.01"/><path d="M3 6a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-12z"/><path d="M3 16l5 -5c.928 -.893 2.072 -.893 3 0l5 5"/><path d="M14 14l1 -1c.928 -.893 2.072 -.893 3 0l3 3"/>',
    'button' => '<path d="M3 9a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v6a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3z"/><path d="M8 12h8"/>',
    'link' => '<path d="M9 15l6 -6"/><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464"/><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463"/>',
    'box' => '<path d="M4 7h16v10h-16z"/><path d="M8 11h8"/>',
    'columns' => '<path d="M4 4h6v16h-6z"/><path d="M14 4h6v16h-6z"/>',
    'imagetext' => '<path d="M3 5h8v8h-8z"/><path d="M14 6h7"/><path d="M14 10h7"/><path d="M3 18h18"/>',
    'divider' => '<path d="M3 12h18"/>',
    'spacer' => '<path d="M8 7l4 -4l4 4"/><path d="M8 17l4 4l4 -4"/><path d="M12 3v18"/>',
    'footer' => '<path d="M4 20h16"/><path d="M4 15h16"/><path d="M8 10h8"/>',
    'event' => '<path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12z"/><path d="M16 3v4"/><path d="M8 3v4"/><path d="M4 11h16"/><path d="M8 15h2v2h-2z"/>',
    'post' => '<path d="M16 6h3a1 1 0 0 1 1 1v11a2 2 0 0 1 -4 0v-13a1 1 0 0 0 -1 -1h-10a1 1 0 0 0 -1 1v12a3 3 0 0 0 3 3h11"/><path d="M8 8h4"/><path d="M8 12h4"/><path d="M8 16h4"/>',
];
$aiText = $ai->textProviders();
$aiImage = $ai->imageReady();
$aiShow = $cfg['ai']['show'];
$svg = static fn (string $p, int $s = 16): string => '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
\Mailing\Core\View::section('head', '<link rel="stylesheet" href="/assets/editor.css?v=' . e(app_version()) . '">');
?>
<div class="me" id="me">
    <div class="me-bar">
        <a class="me-back" href="<?= e($cfg['urls']['back']) ?>">← <?= $isTemplate ? 'Plantilles' : 'Campanya' ?></a>
        <input class="me-title" id="me-title" type="text" maxlength="255" value="" placeholder="Assumpte del correu" aria-label="Assumpte del correu">
        <span class="me-langs">
            <span class="me-seg" role="group" aria-label="Idioma que edites" id="me-langs"></span>
            <details class="ml-menu" id="me-lang-menu">
                <summary class="btn secondary me-ico" title="Idiomes i traducció" aria-label="Idiomes i traducció"><?= $svg('<path d="M4 5h7"/><path d="M9 3v2c0 4.418 -2.239 8 -5 8"/><path d="M5 9c0 2.144 2.952 3.908 6.7 4"/><path d="M12 20l4 -9l4 9"/><path d="M19.1 18h-6.2"/>') ?></summary>
                <div class="ml-menu-pop" style="min-width:300px">
                    <div id="me-lang-add-list"></div>
                    <button type="button" id="me-lang-retranslate">Torna a traduir aquest idioma<small>Des de l'idioma principal, amb IA (substitueix el text)</small></button>
                    <button type="button" id="me-lang-primary">Fes-lo l'idioma principal<small>El reben els contactes sense idioma</small></button>
                    <button type="button" id="me-lang-remove" style="color:#b42318">Treu aquest idioma<small>Es pot desfer amb Ctrl+Z</small></button>
                    <span class="muted" style="font-size:11.5px;padding:6px 12px 4px;line-height:1.4">Cada contacte rep el correu en el seu idioma (camp «Idioma» de la fitxa); si no hi és, el principal (★).</span>
                </div>
            </details>
        </span>
        <button type="button" class="btn secondary me-ico" id="me-undo" aria-label="Desfés" title="Desfés (Ctrl+Z)" disabled><?= $svg('<path d="M9 14l-4 -4l4 -4"/><path d="M5 10h11a4 4 0 1 1 0 8h-1"/>') ?></button>
        <button type="button" class="btn secondary me-ico" id="me-redo" aria-label="Refés" title="Refés (Ctrl+Y)" disabled><?= $svg('<path d="M15 14l4 -4l-4 -4"/><path d="M19 10h-11a4 4 0 1 0 0 8h1"/>') ?></button>
        <?php if ($aiShow): ?><button type="button" class="btn secondary" id="me-ai" style="font-size:13px;" title="Escriu textos o crea imatges amb IA">✨ IA</button><?php endif; ?>
        <button type="button" class="btn secondary" id="me-preview" style="font-size:13px;">Previsualitza</button>
        <details class="ml-menu" id="me-more">
            <summary class="btn secondary me-ico" aria-label="Més opcions" title="Més opcions"><?= $svg('<path d="M5 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M19 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/>') ?></summary>
            <div class="ml-menu-pop">
                <button type="button" id="me-history">Historial de versions<small>Mira i recupera versions anteriors</small></button>
                <?php if (!$isTemplate): ?><button type="button" id="me-test">Envia una prova<small>A una o més adreces</small></button><?php endif; ?>
                <?php if ($isTemplate): ?><a href="/plantilles/<?= (int) $row['id'] ?>/exporta" id="me-export-json">Exporta (.json)<small>Desa primer els canvis</small></a><?php endif; ?>
                <button type="button" id="me-astemplate"><?= $isTemplate ? 'Crea una plantilla a partir d\'aquesta' : 'Desa com a plantilla' ?><small>Una còpia a «Plantilles»</small></button>
            </div>
        </details>
        <button type="button" class="btn" id="me-save">Desa</button>
    </div>

    <div class="me-meta">
        <?php if ($isTemplate): ?><label class="name">Nom de la plantilla <input type="text" id="me-name" maxlength="190" value="<?= e($row['name']) ?>"></label><?php endif; ?>
        <label title="L'idioma en què està escrit aquest correu. El peu legal surt en aquest idioma.">Idioma del correu
            <select id="me-lang-select" style="font:inherit;font-size:12.5px;padding:6px 9px;border:0;border-radius:8px;background:var(--fill, #f1f2f4)"></select>
        </label>
        <label class="pre">Text de previsualització
            <input type="text" id="me-pre" maxlength="255" value="" placeholder="El resum que es veu a la safata d'entrada, al costat de l'assumpte">
        </label>
        <span><span id="me-saved">Desat <?= e(ago($row['updated_at'])) ?></span> · <b id="me-ver">versió <?= (int) $row['version'] ?></b> <span id="me-dirty" style="color:#b45309;font-weight:700;" hidden>· canvis sense desar</span></span>
    </div>

    <div class="me-grid">
        <aside class="me-side me-left" aria-label="Blocs i estils">
            <div class="me-tabs" role="tablist">
                <button type="button" role="tab" aria-selected="true" data-tab="blocks">Blocs</button>
                <button type="button" role="tab" aria-selected="false" data-tab="styles">Estils</button>
            </div>
            <div data-pane="blocks">
                <span class="me-kicker">Arrossega al correu</span>
                <div class="me-pal" id="me-pal">
                    <?php foreach ($types as $key => $name): if (isset($contentTypes[$key])) continue; ?>
                        <button type="button" draggable="true" data-type="<?= e($key) ?>" title="Arrossega'l al correu o clica per afegir-lo"><?= $svg($icons[$key] ?? '', 20) ?><?= e($name) ?></button>
                    <?php endforeach; ?>
                </div>
                <span class="me-kicker" style="margin-top:14px;">Blocs de contingut</span>
                <div class="me-pal" id="me-pal-src">
                    <?php foreach ($contentTypes as $key => $_): ?>
                        <button type="button" draggable="true" data-type="<?= e($key) ?>" title="Arrossega'l al correu o clica per afegir-lo"><?= $svg($icons[$key], 20) ?><?= e($types[$key]) ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="me-tip">Arrossega un bloc on vulguis, o clica'l i s'afegeix sota el seleccionat. Per moure un bloc, agafa'l per <b>⠿</b> o fes servir ↑ ↓. El text s'escriu directament al correu. Amb <b>{{ }}</b> de la barra de format hi poses el nom del contacte i altres camps.</div>
            </div>
            <div data-pane="styles" hidden id="me-styles"></div>
        </aside>

        <section class="me-canvas" aria-label="Correu">
            <div class="me-mail" id="me-mail"></div>
        </section>

        <aside class="me-side me-props" id="me-props" aria-label="Opcions del bloc"></aside>
    </div>
</div>

<div class="me-rtbar" id="me-rtbar" role="toolbar" aria-label="Format del text">
    <button type="button" data-cmd="bold" title="Negreta (Ctrl+B)"><b>B</b></button>
    <button type="button" data-cmd="italic" title="Cursiva (Ctrl+I)"><i>I</i></button>
    <button type="button" data-cmd="underline" title="Subratllat (Ctrl+U)"><u>U</u></button>
    <button type="button" data-cmd="link" title="Enllaç">Enllaç</button>
    <button type="button" data-cmd="unlink" title="Treu l'enllaç">✕ enllaç</button>
    <button type="button" data-cmd="insertUnorderedList" title="Llista">• Llista</button>
    <button type="button" data-cmd="insertOrderedList" title="Llista numerada">1.</button>
    <button type="button" data-cmd="h3" title="Subtítol dins del text">Subtítol</button>
    <button type="button" data-cmd="merge" title="Insereix un camp del contacte (nom, cognoms…)">{{ }}</button>
    <button type="button" data-cmd="removeFormat" title="Treu el format">Neteja</button>
</div>
<div class="me-merge" id="me-merge" role="menu" aria-label="Camps del contacte"></div>

<dialog class="me-dlg me-prev" id="me-prev" aria-label="Previsualització">
    <div class="in" style="display:flex;flex-direction:column;">
        <div class="me-prev-bar">
            <b style="flex:1;font-size:15px;" id="me-prev-title">Previsualització</b>
            <span class="me-seg" role="group" aria-label="Mida" id="me-prev-mode">
                <button type="button" data-mode="desk" aria-pressed="false">Ordinador</button>
                <button type="button" data-mode="phone" aria-pressed="false">Mòbil</button>
                <button type="button" data-mode="both" aria-pressed="true">Tots dos</button>
            </span>
            <label class="me-chk" style="margin:0;"><input type="checkbox" id="me-prev-noimg"> Sense imatges</label>
            <button type="button" class="btn secondary" onclick="this.closest('dialog').close()">Tanca</button>
        </div>
        <div class="me-prev-body">
            <div class="me-pane desk"><span>ORDINADOR</span><iframe title="Correu a l'ordinador" id="me-if-desk" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"></iframe></div>
            <div class="me-pane ph"><span>MÒBIL · 375 PX</span><div class="me-phone"><iframe title="Correu al mòbil" id="me-if-phone" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"></iframe></div></div>
        </div>
    </div>
</dialog>

<dialog class="me-dlg" id="me-hist" aria-labelledby="me-hist-h">
    <div class="in">
        <div style="display:flex;align-items:center;gap:10px;"><h2 id="me-hist-h" style="flex:1;">Historial de versions</h2><button type="button" class="btn secondary" onclick="this.closest('dialog').close()">Tanca</button></div>
        <p class="muted" style="margin:0;font-size:12.5px;">Cada «Desa» guarda una versió (totes les llengües). Recuperar-ne una la desa com a versió nova (no s'esborra res).</p>
        <div class="me-hist" id="me-hist-list"><span class="muted">Carregant…</span></div>
    </div>
</dialog>

<?php if ($aiShow): ?>
<dialog class="me-dlg me-ai" id="me-ai-dlg" aria-labelledby="me-ai-h">
    <div class="in">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <h2 id="me-ai-h" style="flex:1;">✨ Crea amb IA</h2>
            <span class="me-seg" role="group" aria-label="Què vols crear" id="me-ai-mode">
                <button type="button" data-mode="text" aria-pressed="true">Text</button>
                <button type="button" data-mode="image" aria-pressed="false">Imatge</button>
            </span>
            <button type="button" class="btn secondary" onclick="this.closest('dialog').close()" aria-label="Tanca">×</button>
        </div>
        <div class="me-ai-target" id="me-ai-target"></div>

        <?php if (!$aiText && !$aiImage): ?>
            <p style="margin:0;font-size:13.5px;line-height:1.5;">Encara no hi ha cap servei d'IA configurat. <a href="/configuracio#ia">Configura Claude o OpenAI</a>.</p>
        <?php endif; ?>

        <div data-ai-pane="text" <?= $aiText ? '' : 'hidden' ?>>
            <div style="display:flex;flex-direction:column;gap:12px;">
                <?php if (count($aiText) > 1): ?>
                    <div class="me-f" style="margin:0;"><span>Amb</span>
                        <div class="seg" role="group" aria-label="Servei d'IA per al text" id="me-ai-provider">
                            <?php foreach ($aiText as $k => $label): ?><button type="button" data-provider="<?= e($k) ?>" aria-pressed="<?= $k === $ai->defaultTextProvider() ? 'true' : 'false' ?>"><?= e($label) ?></button><?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <label class="me-f" style="margin:0;">Què vols que escrigui?
                    <textarea id="me-ai-prompt" maxlength="4000" placeholder="Ex.: Anuncia el nou servei de formació en línia, amb inscripcions obertes fins al 30 de novembre."></textarea></label>
                <div class="opts">
                    <label class="me-f">Tipus<select id="me-ai-format"><?php foreach (Ai::FORMATS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
                    <label class="me-f">To<select id="me-ai-tone"><?php foreach (Ai::TONES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
                    <label class="me-f">Llargada<select id="me-ai-length"><?php foreach (Ai::LENGTHS as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'mitja' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
                </div>
                <label class="me-chk" style="margin:0;"><input type="checkbox" id="me-ai-context" checked> Té en compte el que ja diu el correu</label>
                <label class="me-chk" style="margin:0;" id="me-ai-rewrite-wrap" hidden><input type="checkbox" id="me-ai-rewrite"> Parteix del text del bloc (millora'l o reescriu-lo)</label>
            </div>
        </div>

        <div data-ai-pane="image" hidden>
            <div style="display:flex;flex-direction:column;gap:12px;">
                <?php if ($aiImage): ?>
                    <label class="me-f" style="margin:0;">Descriu la imatge
                        <textarea id="me-ai-iprompt" maxlength="3000" placeholder="Ex.: Equip de professionals sanitaris somrients en una sala de formació moderna, llum natural."></textarea></label>
                    <div class="opts" style="grid-template-columns:1fr 1fr;">
                        <div class="me-f"><span>Forma</span><div class="seg" role="group" aria-label="Forma" id="me-ai-shape"><?php $i = 0; foreach (Ai::SHAPES as $k => $v): ?><button type="button" data-v="<?= e($k) ?>" aria-pressed="<?= $i++ === 0 ? 'true' : 'false' ?>"><?= e($v['label']) ?></button><?php endforeach; ?></div></div>
                        <div class="me-f"><span>Estil</span><div class="seg" role="group" aria-label="Estil" id="me-ai-style"><?php $i = 0; foreach (Ai::STYLES as $k => $v): ?><button type="button" data-v="<?= e($k) ?>" aria-pressed="<?= $i++ === 0 ? 'true' : 'false' ?>"><?= e($v['label']) ?></button><?php endforeach; ?></div></div>
                    </div>
                    <div class="me-f" style="margin:0;">
                        <span>Imatges de referència (opcional)</span>
                        <div class="me-refs" id="me-ai-refs" aria-label="Imatges de referència">
                            <button type="button" class="me-ref-add" id="me-ai-refs-add"><span aria-hidden="true">＋</span>Afegeix</button>
                            <input type="file" id="me-ai-refs-file" accept="image/png,image/jpeg,image/webp,image/gif" multiple hidden data-no-drop>
                        </div>
                        <small class="muted" style="font-weight:400;font-size:12px;line-height:1.45;">Fotos, captures o logotips perquè la IA s'hi basi. Arrossega-les aquí o enganxa una captura amb <b>Ctrl+V</b>. Màxim <?= Ai::MAX_REFS ?>. Explica a la descripció què n'ha de fer.</small>
                    </div>
                    <label class="me-chk" style="margin:0;" id="me-ai-usecur-wrap" hidden><input type="checkbox" id="me-ai-usecur"> Fes servir també la imatge que ja hi ha al bloc</label>
                    <label class="me-chk" style="margin:0;" id="me-ai-keep-wrap" hidden><input type="checkbox" id="me-ai-keep"> Conserva fidelment els detalls de les referències (cares, logotips, textos)</label>
                    <label class="me-chk" style="margin:0;"><input type="checkbox" id="me-ai-notext" checked> Sense text ni logotips dins de la imatge (la IA els escriu malament)</label>
                    <p class="me-ai-note" style="margin:0;">Les imatges les crea OpenAI (<?= e($ai->openaiImageModel()) ?>): Claude no en fa. Pot trigar fins a un minut.</p>
                <?php else: ?>
                    <p style="margin:0;font-size:13.5px;line-height:1.5;">Per crear imatges cal la clau d'OpenAI (Claude no crea imatges).<?= \Mailing\Core\Auth::isAdmin() ? ' <a href="/configuracio#ia">Configura-la</a>.' : ' Demana-ho a una persona administradora.' ?></p>
                <?php endif; ?>
            </div>
        </div>

        <p class="me-ai-note" style="margin:0;">El que escrius aquí (i el text del correu, si ho marques) s'envia al servei d'IA. No hi posis dades personals de ningú. Revisa sempre el resultat abans d'enviar el correu.</p>
        <div class="me-ai-out" id="me-ai-out" hidden></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;" id="me-ai-actions">
            <button type="button" class="btn secondary" id="me-ai-new" hidden>Afegeix com a bloc nou</button>
            <button type="button" class="btn secondary" id="me-ai-replace" hidden>Substitueix</button>
            <button type="button" class="btn secondary" id="me-ai-subject" hidden>Fes-ho servir d'assumpte</button>
            <button type="button" class="btn" id="me-ai-go">Genera</button>
        </div>
    </div>
</dialog>
<?php endif; ?>

<dialog class="me-dlg me-crop" id="me-crop-dlg" aria-labelledby="me-crop-h">
    <div class="in">
        <div style="display:flex;align-items:center;gap:10px;"><h2 id="me-crop-h" style="flex:1;">Retalla la imatge</h2><span class="muted" id="me-crop-size" style="font-size:12.5px;"></span></div>
        <div class="me-crop-ratios" role="group" aria-label="Proporció del retall">
            <button type="button" data-ratio="0" aria-pressed="true">Lliure</button>
            <button type="button" data-ratio="1.7778">16:9</button>
            <button type="button" data-ratio="1.3333">4:3</button>
            <button type="button" data-ratio="1.5">3:2</button>
            <button type="button" data-ratio="1">Quadrada</button>
            <button type="button" data-ratio="0.75">Vertical 3:4</button>
        </div>
        <div class="me-crop-stage" id="me-crop-stage">
            <img id="me-crop-img" alt="">
            <div class="me-crop-box" id="me-crop-box" tabindex="0" role="group" aria-label="Zona del retall: arrossega-la o mou-la amb les fletxes (Maj + fletxes per canviar-ne la mida)">
                <span class="me-crop-h" data-h="nw"></span><span class="me-crop-h" data-h="n"></span><span class="me-crop-h" data-h="ne"></span><span class="me-crop-h" data-h="e"></span>
                <span class="me-crop-h" data-h="se"></span><span class="me-crop-h" data-h="s"></span><span class="me-crop-h" data-h="sw"></span><span class="me-crop-h" data-h="w"></span>
            </div>
        </div>
        <p class="muted" style="margin:0;font-size:12.5px;">Es desa com una imatge nova: l'original no s'esborra i amb «Desfés» la pots recuperar.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" class="btn secondary" id="me-crop-reset">Tota la imatge</button>
            <span style="flex:1;"></span>
            <button type="button" class="btn secondary" onclick="this.closest('dialog').close()">Cancel·la</button>
            <button type="button" class="btn" id="me-crop-ok">Retalla</button>
        </div>
    </div>
</dialog>

<script type="application/json" id="me-cfg"><?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php \Mailing\Core\View::section('foot', '<script src="/assets/editor.js?v=' . e(app_version()) . '"></script>'); ?>
