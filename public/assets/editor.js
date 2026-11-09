/*
 * Editor visual de correus: portat de l'editor del Mailing del Tauler (iSEM),
 * amb un document per idioma, traducció automàtica i camps del contacte.
 */
(function () {
    'use strict';
    var CFG = JSON.parse(document.getElementById('me-cfg').textContent);
    // Un document per idioma: {primary, langs: {ca: {subject, preheader, doc}}}. S'edita el de «lang».
    var envelope = CFG.doc, lang = envelope.primary, version = CFG.version, tplName = CFG.name || '';
    var doc, title, preheader;
    function load(l) { lang = l; var x = envelope.langs[l]; doc = x.doc; title = x.subject || ''; preheader = x.preheader || ''; }
    function stash() { envelope.langs[lang] = { subject: title, preheader: preheader, doc: doc }; }
    load(lang);
    var $ = function (id) { return document.getElementById(id); };
    var mailEl = $('me-mail'), propsEl = $('me-props'), rtbar = $('me-rtbar');
    var selected = null, editing = null;
    var SWATCHES = ['#ac1966', '#bc0067', '#1f2430', '#333333', '#000000', '#6b7280', '#1a7f56', '#2563a8', '#b45309', '#c0262d', '#f9eaf2', '#fdeeec', '#e6f4ec', '#eef4ff', '#f1f2f4', '#ffffff'];

    // ------------------------------------------------------------ utilitats
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    function uid() { return 'b' + Math.random().toString(16).slice(2, 12); }
    function S() { return doc.settings; }
    function font() { return (CFG.fonts[S().font] || CFG.fonts.arial).stack; }
    function find(id) { for (var i = 0; i < doc.blocks.length; i++) if (doc.blocks[i].id === id) return i; return -1; }
    function block(id) { var i = find(id); return i < 0 ? null : doc.blocks[i]; }
    function imgUrl(src) {
        if (!src) return '';
        if (CFG.images[src]) return CFG.images[src];
        if (src.indexOf('img:') === 0) return CFG.urls.image.replace(/X{40}$/, src.slice(4));
        return /^https:\/\//.test(src) ? src : '';
    }
    function toast(msg, err) {
        document.querySelectorAll('.me-toast').forEach(function (x) { x.remove(); });
        var t = document.createElement('div'); t.className = 'me-toast' + (err ? ' err' : ''); t.setAttribute('role', 'status'); t.textContent = msg;
        document.body.appendChild(t); setTimeout(function () { t.remove(); }, err ? 6000 : 3500);
    }
    function api(url, opts) {
        opts = opts || {};
        var headers = Object.assign({ 'X-CSRF-TOKEN': CFG.csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, opts.headers || {});
        if (opts.json !== undefined) { headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(opts.json); }
        return fetch(url, { method: opts.method || 'GET', headers: headers, body: opts.body, credentials: 'same-origin' });
    }
    function errorText(r) {
        return r.json().then(function (j) {
            if (j.errors) { var k = Object.keys(j.errors)[0]; return j.errors[k][0]; }
            return j.message || 'No s\'ha pogut fer.';
        }).catch(function () { return 'No s\'ha pogut fer (error ' + r.status + ').'; });
    }

    // Neteja l'HTML enganxat: només format bàsic (la neteja de debò la fa el servidor).
    var OK_TAGS = { P: 'p', DIV: 'p', BR: 'br', B: 'strong', STRONG: 'strong', I: 'em', EM: 'em', U: 'u', A: 'a', UL: 'ul', OL: 'ol', LI: 'li', H1: 'h3', H2: 'h3', H3: 'h3', H4: 'h4' };
    function cleanHtml(html) {
        var src = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html').body;
        function walk(node) {
            var out = '';
            node.childNodes.forEach(function (n) {
                if (n.nodeType === 3) { out += esc(n.nodeValue); return; }
                if (n.nodeType !== 1) return;
                if (/^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|IMG|SVG|VIDEO|AUDIO|HEAD|META|LINK|TITLE)$/.test(n.tagName)) return;
                var tag = OK_TAGS[n.tagName], inner = walk(n);
                if (!tag) { out += inner; return; }
                if (tag === 'br') { out += '<br>'; return; }
                if (tag === 'a') { var h = n.getAttribute('href') || ''; out += /^(https?:|mailto:)/i.test(h) ? '<a href="' + esc(h) + '">' + inner + '</a>' : inner; return; }
                out += '<' + tag + '>' + inner + '</' + tag + '>';
            });
            return out;
        }
        return walk(src);
    }

    // ------------------------------------------------------------ desfer / desat
    var undo = [], redo = [], last = snap(), savedState = fullSnap(), commitTimer = null;
    function snap() { stash(); return JSON.stringify(envelope); }
    function fullSnap() { return snap() + '|' + tplName; }
    function commit(now) {
        clearTimeout(commitTimer);
        var run = function () {
            var cur = snap();
            if (cur !== last) { undo.push(last); if (undo.length > 80) undo.shift(); redo = []; last = cur; }
            updateButtons();
        };
        if (now) run(); else commitTimer = setTimeout(run, 600);
        updateButtons();
    }
    function restore(state) {
        envelope = JSON.parse(state);
        load(envelope.langs[lang] ? lang : envelope.primary);
        $('me-title').value = title; $('me-pre').value = preheader; last = state;
        if (selected && find(selected) < 0) selected = null;
        renderAll(); renderProps(); renderLangs(); updateButtons();
    }
    function doUndo() { commit(true); if (!undo.length) return; redo.push(last); restore(undo.pop()); }
    function doRedo() { if (!redo.length) return; undo.push(last); restore(redo.pop()); }
    function dirty() { return fullSnap() !== savedState; }
    function updateButtons() {
        $('me-undo').disabled = !undo.length && snap() === last;
        $('me-redo').disabled = !redo.length;
        $('me-dirty').hidden = !dirty();
    }
    $('me-undo').addEventListener('click', doUndo);
    $('me-redo').addEventListener('click', doRedo);

    var saving = null;
    function save(opts) {
        opts = opts || {};
        if (saving) return saving.then(function () { return save(opts); });
        commit(true);
        stash();
        var body = { doc: envelope, base_version: version, force: !!opts.force, name: tplName };
        var btn = $('me-save'); btn.disabled = true; btn.textContent = 'Desant…';
        saving = api(CFG.urls.update, { method: 'POST', json: body }).then(function (r) {
            if (r.status === 409) {
                return r.json().then(function (j) {
                    if (confirm(j.message + '\n\nVols desar igualment?')) { saving = null; return save(Object.assign({}, opts, { force: true })); }
                    throw new Error('cancel');
                });
            }
            if (!r.ok) return errorText(r).then(function (m) { throw new Error(m); });
            return r.json().then(function (j) {
                version = j.version;
                savedState = fullSnap();
                $('me-saved').textContent = j.saved; $('me-ver').textContent = 'versió ' + j.version;
                updateButtons();
                if (!opts.quiet) toast(j.result === 'unchanged' ? 'No hi havia canvis per desar.' : 'Desat (versió ' + j.version + ').');
                return j;
            });
        }).catch(function (e) {
            if (e.message !== 'cancel') toast(e.message || 'No s\'ha pogut desar.', true);
            throw e;
        }).finally(function () { saving = null; btn.disabled = false; btn.textContent = 'Desa'; });
        return saving;
    }
    function ensureSaved() { return dirty() ? save({ quiet: true }) : Promise.resolve(); }
    $('me-save').addEventListener('click', function () { save().catch(function () {}); });
    window.addEventListener('beforeunload', function (e) { if (dirty()) { e.preventDefault(); e.returnValue = ''; } });

    $('me-title').addEventListener('input', function () { title = this.value; commit(); });
    $('me-pre').addEventListener('input', function () { preheader = this.value; commit(); });
    if ($('me-name')) $('me-name').addEventListener('input', function () { tplName = this.value; updateButtons(); });

    // ------------------------------------------------------------ dibuix del correu (vista d'edició)
    function px(b) { return 'padding:' + b.pt + 'px ' + S().padX + 'px ' + b.pb + 'px;' + (b.bg ? 'background:' + b.bg + ';' : ''); }
    function base(size, color) { size = size || S().textSize; return 'font-family:' + font() + ';font-size:' + size + 'px;line-height:' + Math.round(size * 1.45) + 'px;color:' + (color || S().textColor) + ';'; }
    function ph(text, h) { return '<div class="me-ph" style="min-height:' + (h || 120) + 'px">' + text + '</div>'; }
    function imgTag(src, alt, width, radius, placeholder) {
        var u = imgUrl(src);
        if (!u) return placeholder === false ? '' : ph('<span>🖼</span>Afegeix una imatge<br><small style="font-weight:400">Arrossega-la aquí o tria-la a la dreta</small>', 110);
        return '<img src="' + esc(u) + '" alt="' + esc(alt) + '" style="display:inline-block;width:' + width + 'px;max-width:100%;height:auto;border:0;' + (radius ? 'border-radius:' + radius + 'px;' : '') + '">';
    }
    function rich(html, size, color, align) {
        return '<div class="rt" style="' + base(size, color) + 'text-align:' + (align || 'left') + ';">' + (html || '') + '</div>';
    }
    function inner() { return 600 - 2 * S().padX; }
    function colHtml(c, width) {
        var out = '';
        if (c.src) out += '<div style="margin:0 0 12px">' + imgTag(c.src, c.alt, width, 0) + '</div>';
        if (c.title) out += '<div style="font-family:' + font() + ';font-size:' + (S().textSize + 2) + 'px;font-weight:bold;color:' + S().headingColor + ';margin:0 0 6px">' + esc(c.title) + '</div>';
        out += rich(c.html);
        if (c.linkText) out += '<div style="' + base() + 'margin-top:8px;font-weight:bold;color:' + S().linkColor + '">' + esc(c.linkText) + '</div>';
        return out;
    }
    var R = {
        header: function (b) {
            var img = imgTag(b.src, b.alt, b.width, 0, false) || '<div class="me-ph" style="min-height:40px">Sense logotip</div>';
            return '<div style="' + px(b) + 'text-align:' + b.align + ';' + (b.borderTop && b.borderWidth ? 'border-top:' + b.borderWidth + 'px solid ' + b.borderTop + ';' : '') + '">' + img + '</div>';
        },
        heading: function (b) {
            var color = b.color || (b.level === 2 ? S().headingColor : '#333333');
            return '<div style="' + px(b) + '"><div data-edit="heading" style="font-family:' + font() + ';font-size:' + b.size + 'px;line-height:' + Math.round(b.size * 1.4) + 'px;font-weight:bold;color:' + color + ';text-align:' + b.align + ';">' + esc(b.text) + '</div></div>';
        },
        text: function (b) { return '<div style="' + px(b) + '"><div data-edit="rich" class="rt" style="' + base(b.size, b.color) + 'text-align:' + b.align + ';">' + (b.html || '<p></p>') + '</div></div>'; },
        image: function (b) {
            var w = b.width ? Math.min(b.width, inner()) : inner();
            return '<div style="' + px(b) + 'text-align:' + b.align + ';" data-drop-image="1">' + imgTag(b.src, b.alt, w, b.radius) + '</div>';
        },
        button: function (b) {
            var bg = b.btnBg || S().accent, h = Math.round(b.size * 2.9);
            return '<div style="' + px(b) + 'text-align:' + b.align + ';"><span style="display:' + (b.full ? 'block' : 'inline-block') + ';background:' + bg + ';color:' + b.color + ';font-family:' + font() + ';font-size:' + b.size + 'px;font-weight:bold;line-height:' + h + 'px;padding:0 28px;border-radius:' + b.radius + 'px;text-align:center;">' + esc(b.text || 'Botó') + '</span></div>';
        },
        link: function (b) {
            return '<div style="' + px(b) + '"><div style="' + base(b.size) + 'text-align:' + b.align + ';color:' + (b.color || S().linkColor) + ';' + (b.bold ? 'font-weight:bold;' : '') + '">' + esc(b.text || 'Enllaç') + '</div></div>';
        },
        box: function (b) {
            return '<div style="' + px(b) + '"><div style="background:' + b.boxBg + ';border-radius:' + b.radius + 'px;padding:16px 20px;"><div data-edit="rich" class="rt" style="' + base(b.size, b.color) + '">' + (b.html || '<p></p>') + '</div></div></div>';
        },
        columns: function (b) {
            var w = Math.floor((inner() + 20) / b.count) - 20;
            return '<div style="' + px(b) + 'display:grid;grid-template-columns:repeat(' + b.count + ',minmax(0,1fr));gap:20px;">' + b.cols.map(function (c) { return '<div>' + colHtml(c, w) + '</div>'; }).join('') + '</div>';
        },
        imagetext: function (b) {
            var wi = Math.floor((inner() + 20) * 0.45) - 20;
            var img = '<div>' + imgTag(b.src, b.alt, wi, 0) + '</div>', txt = '<div>' + colHtml(Object.assign({}, b, { src: '' }), 0) + '</div>';
            return '<div style="' + px(b) + 'display:grid;grid-template-columns:' + (b.side === 'right' ? '55fr 45fr' : '45fr 55fr') + ';gap:20px;align-items:start;">' + (b.side === 'right' ? txt + img : img + txt) + '</div>';
        },
        divider: function (b) { return '<div style="' + px(b) + '"><div style="width:' + b.widthPct + '%;margin:0 auto;border-top:' + b.thickness + 'px solid ' + b.color + ';"></div></div>'; },
        spacer: function (b) { return '<div style="' + px(b) + 'height:' + b.height + 'px;background-image:repeating-linear-gradient(45deg,transparent 0 6px,rgba(172,25,102,.06) 6px 12px);"></div>'; },
        event: function (b) {
            if (!b.title) return '<div style="' + px(b) + '">' + ph('<span>📅</span>Tria un esdeveniment del Calendari', 90) + '</div>';
            var badge = b.day ? '<div style="flex:none;align-self:flex-start;width:64px;background:' + S().accent + ';border-radius:10px;padding:8px 0;text-align:center;color:#fff;font-family:' + font() + ';"><div style="font-size:24px;line-height:26px;font-weight:bold;">' + esc(b.day) + '</div><div style="font-size:11px;font-weight:bold;letter-spacing:1px;">' + esc(String(b.month).toUpperCase()) + '</div></div>' : '';
            return '<div style="' + px(b) + '"><div style="background:' + b.boxBg + ';border-radius:12px;padding:16px 18px;display:flex;gap:16px;">' + badge + '<div style="flex:1;min-width:0;">'
                + '<div style="' + base(S().textSize + 2, '#1f2430') + 'font-weight:bold;margin-bottom:6px;">' + esc(b.title) + '</div>'
                + (b.when ? '<div style="' + base(S().textSize - 1, S().headingColor) + 'font-weight:bold;">' + esc(b.when) + '</div>' : '')
                + (b.location ? '<div style="' + base(S().textSize - 1, '#4b5260') + '">' + esc(b.location) + '</div>' : '')
                + (b.desc ? '<div style="' + base(S().textSize - 1) + 'margin-top:6px;white-space:pre-line;">' + esc(b.desc) + '</div>' : '')
                + (b.href && b.btnText ? '<div style="' + base(S().textSize - 1, S().linkColor) + 'font-weight:bold;margin-top:8px;">' + esc(b.btnText) + '</div>' : '')
                + '</div></div></div>';
        },
        survey: function (b) { return ctaHtml(b, 'Enquesta', '📊'); },
        form: function (b) { return ctaHtml(b, 'Formulari', '📝'); },
        post: function (b) {
            if (!b.title) return '<div style="' + px(b) + '">' + ph('<span>📰</span>Tria una publicació del web', 90) + '</div>';
            var u = imgUrl(b.src), side = b.layout === 'side' && u;
            var text = (b.date ? '<div style="font-family:' + font() + ';font-size:12px;font-weight:bold;color:#6b7280;margin-bottom:4px;">' + esc(b.date) + '</div>' : '')
                + '<div style="' + base(S().textSize + 2, '#1f2430') + 'font-weight:bold;margin-bottom:6px;">' + esc(b.title) + '</div>'
                + (b.desc ? '<div style="' + base(S().textSize - 1) + 'white-space:pre-line;">' + esc(b.desc) + '</div>' : '')
                + (b.href && b.btnText ? '<div style="' + base(S().textSize - 1, S().linkColor) + 'font-weight:bold;margin-top:8px;">' + esc(b.btnText) + '</div>' : '');
            var img = u ? '<img src="' + esc(u) + '" alt="" style="display:block;width:100%;height:auto;border-radius:' + (side ? 10 : 12) + 'px;">' : '';
            return '<div style="' + px(b) + '">' + (side ? '<div style="display:grid;grid-template-columns:40fr 60fr;gap:20px;align-items:start;"><div>' + img + '</div><div>' + text + '</div></div>' : (img ? '<div style="margin-bottom:12px;">' + img + '</div>' : '') + text) + '</div>';
        },
        footer: function (b) {
            var out = '';
            if (b.lineColor) out += '<div style="border-top:1px solid ' + b.lineColor + ';margin-bottom:16px;"></div>';
            if (b.width) out += '<div style="text-align:center;margin-bottom:10px;">' + (imgTag(b.src, b.alt, b.width, 0, false) || '') + '</div>';
            if (b.name) out += '<div style="font-family:' + font() + ';font-size:12px;line-height:18px;text-align:center;color:' + b.color + ';margin-bottom:8px;">' + esc(b.name) + '</div>';
            if (b.links.length) out += '<div style="font-family:' + font() + ';font-size:12px;line-height:18px;text-align:center;color:#333;">' + b.links.map(function (l) { return '<span style="color:' + (b.linkColor || S().linkColor) + '">' + esc(l.text) + '</span>'; }).join('&nbsp;&nbsp;·&nbsp;&nbsp;') + '</div>';
            if (b.note) out += '<div style="font-family:' + font() + ';font-size:11px;line-height:16px;text-align:center;color:' + b.color + ';margin-top:12px;white-space:pre-line;">' + esc(b.note) + '</div>';
            return '<div style="' + px(b) + '">' + out + '</div>';
        }
    };

    function ctaHtml(b, kicker, icon) {
        if (!b.title) return '<div style="' + px(b) + '">' + ph('<span>' + icon + '</span>Tria ' + (kicker === 'Enquesta' ? 'una enquesta' : 'un formulari') + ' del web', 90) + '</div>';
        return '<div style="' + px(b) + '"><div style="background:' + b.boxBg + ';border-radius:12px;padding:18px 20px;">'
            + '<div style="font-family:' + font() + ';font-size:11px;font-weight:bold;letter-spacing:1px;color:' + S().headingColor + ';margin-bottom:4px;">' + kicker.toUpperCase() + '</div>'
            + '<div style="' + base(S().textSize + 2, '#1f2430') + 'font-weight:bold;margin-bottom:6px;">' + esc(b.title) + '</div>'
            + (b.desc ? '<div style="' + base(S().textSize - 1) + 'white-space:pre-line;margin-bottom:6px;">' + esc(b.desc) + '</div>' : '')
            + (b.ends ? '<div style="' + base(S().textSize - 3, '#4b5260') + '">' + esc(b.ends) + '</div>' : '')
            + (b.href && b.btnText ? '<span style="display:inline-block;margin-top:12px;background:' + S().accent + ';color:#fff;font-family:' + font() + ';font-size:15px;font-weight:bold;line-height:44px;padding:0 24px;border-radius:8px;">' + esc(b.btnText) + '</span>' : '')
            + '</div></div>';
    }

    function blockEl(b) {
        var el = document.createElement('div');
        el.className = 'me-b' + (b.id === selected ? ' sel' : '');
        el.dataset.id = b.id; el.tabIndex = 0;
        el.setAttribute('role', 'group'); el.setAttribute('aria-label', 'Bloc: ' + CFG.types[b.type]);
        el.innerHTML = '<span class="me-b-name">' + esc(CFG.types[b.type]) + '</span>'
            + '<span class="me-b-tools">'
            + '<button type="button" class="grip" draggable="true" data-act="grip" title="Arrossega per moure" aria-label="Arrossega per moure">⠿</button>'
            + '<button type="button" data-act="up" title="Puja" aria-label="Puja el bloc">↑</button>'
            + '<button type="button" data-act="down" title="Baixa" aria-label="Baixa el bloc">↓</button>'
            + '<button type="button" data-act="dup" title="Duplica" aria-label="Duplica el bloc">⧉</button>'
            + '<button type="button" data-act="del" title="Elimina" aria-label="Elimina el bloc">✕</button>'
            + '</span>'
            + '<div class="me-b-in">' + (R[b.type] ? R[b.type](b) : '') + '</div>';
        if (b.hideMobile) el.querySelector('.me-b-in').style.opacity = '.9';
        if (b.id === selected) makeEditable(el, b);
        return el;
    }
    function renderAll() {
        mailEl.style.background = S().contentBg;
        mailEl.parentNode.style.background = S().bodyBg === '#ffffff' ? '#e9ebef' : S().bodyBg;
        mailEl.innerHTML = '';
        if (!doc.blocks.length) { mailEl.innerHTML = '<div class="me-empty">El correu és buit. Arrossega aquí blocs de l\'esquerra.</div>'; }
        doc.blocks.forEach(function (b) { mailEl.appendChild(blockEl(b)); });
        mailEl.appendChild(tailEl());
        var drop = document.createElement('div'); drop.className = 'me-drop'; drop.id = 'me-drop'; drop.innerHTML = '<span>Deixa anar aquí</span>';
        mailEl.appendChild(drop);
        hideRt();
    }
    // Peu legal automàtic, en l'idioma del correu (el mateix que afegeix el servidor en enviar-lo).
    function tailEl() {
        var t = (CFG.tail || {})[lang] || (CFG.tail || {}).ca || { why: '%s', unsub: '', view: '' }, b = CFG.brand || {};
        var el = document.createElement('div');
        el.className = 'me-tail'; el.setAttribute('aria-label', 'Peu legal automàtic');
        el.style.background = S().bodyBg; el.style.fontFamily = font(); el.style.padding = '22px ' + S().padX + 'px 26px';
        el.innerHTML = '<p>' + esc((b.why && b.why[lang]) || t.why.replace('%s', b.name || '')) + '</p><p><a>' + esc(t.unsub) + '</a>&nbsp;&nbsp;·&nbsp;&nbsp;<a>' + esc(t.view) + '</a></p>'
            + (b.address ? '<p>' + esc(b.address) + '</p>' : '');
        return el;
    }
    function renderBlock(id) {
        var old = mailEl.querySelector('.me-b[data-id="' + id + '"]'), b = block(id);
        if (!old || !b) { renderAll(); return; }
        old.replaceWith(blockEl(b));
    }

    // Edició del text directament al correu (títols, text i requadres).
    function makeEditable(el, b) {
        el.querySelectorAll('[data-edit]').forEach(function (t) {
            t.contentEditable = 'true';
            t.spellcheck = true;
            if (t.dataset.edit === 'heading') {
                t.addEventListener('input', function () { b.text = t.textContent.replace(/\s+/g, ' ').trim(); commit(); });
                t.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); t.blur(); } });
                t.addEventListener('blur', function () { var f = propsEl.querySelector('input[type="text"]'); if (f && selected === b.id && b.type === 'heading') f.value = b.text; });
                t.addEventListener('paste', function (e) { e.preventDefault(); document.execCommand('insertText', false, (e.clipboardData.getData('text/plain') || '').replace(/\s+/g, ' ')); });
            } else {
                t.addEventListener('focus', function () { editing = t; showRt(t); try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (x) {} });
                t.addEventListener('input', function () { b.html = t.innerHTML; commit(); });
                t.addEventListener('paste', function (e) {
                    e.preventDefault();
                    var html = e.clipboardData.getData('text/html'), text = e.clipboardData.getData('text/plain');
                    document.execCommand('insertHTML', false, html ? cleanHtml(html) : esc(text).replace(/\n{2,}/g, '</p><p>').replace(/\n/g, '<br>'));
                });
            }
        });
    }

    // Barra de format del text.
    function showRt(target) {
        var r = target.getBoundingClientRect();
        rtbar.style.display = 'flex';
        var top = window.scrollY + r.top - rtbar.offsetHeight - 8, left = window.scrollX + r.left;
        rtbar.style.top = Math.max(window.scrollY + 70, top) + 'px';
        rtbar.style.left = Math.max(8, Math.min(left, window.scrollX + document.documentElement.clientWidth - rtbar.offsetWidth - 8)) + 'px';
    }
    function hideRt() { rtbar.style.display = 'none'; editing = null; var mm = $('me-merge'); if (mm) mm.style.display = 'none'; }
    rtbar.addEventListener('mousedown', function (e) { e.preventDefault(); });
    rtbar.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-cmd]'); if (!b || !editing) return;
        var cmd = b.dataset.cmd;
        if (cmd === 'merge') { toggleMerge(b); return; }
        if (cmd === 'link') {
            var url = prompt('Adreça de l\'enllaç (https://… o mailto:…)', 'https://');
            if (!url || url === 'https://') return;
            if (/^www\./i.test(url)) url = 'https://' + url;
            if (!/^(https?:\/\/|mailto:)/i.test(url)) { toast('L\'enllaç ha de començar per https:// o mailto:', true); return; }
            document.execCommand('createLink', false, url);
        } else if (cmd === 'h3') {
            var cur = (document.queryCommandValue('formatBlock') || '').toLowerCase();
            document.execCommand('formatBlock', false, cur === 'h3' ? 'p' : 'h3');
        } else {
            document.execCommand(cmd, false, null);
        }
        editing.dispatchEvent(new Event('input', { bubbles: true }));
    });
    document.addEventListener('focusin', function (e) { if (editing && !e.target.closest('[data-edit="rich"], .me-rich, #me-rtbar')) hideRt(); });
    window.addEventListener('scroll', function () { if (editing) showRt(editing); }, { passive: true });

    // ------------------------------------------------------------ selecció i accions dels blocs
    function select(id, focusProps) {
        if (selected === id) return;
        var prev = selected; selected = id;
        if (prev && block(prev)) renderBlock(prev);
        if (id) renderBlock(id);
        renderProps();
        if (focusProps) { var f = propsEl.querySelector('input, select, textarea'); if (f) f.focus(); }
    }
    mailEl.addEventListener('click', function (e) {
        var act = e.target.closest('[data-act]'), el = e.target.closest('.me-b');
        if (!el) return;
        var id = el.dataset.id;
        if (act) {
            var i = find(id);
            if (act.dataset.act === 'up' && i > 0) { move(i, i - 1); }
            else if (act.dataset.act === 'down' && i < doc.blocks.length - 1) { move(i, i + 2); }
            else if (act.dataset.act === 'dup') { commit(true); var c = clone(doc.blocks[i]); c.id = uid(); doc.blocks.splice(i + 1, 0, c); selected = c.id; renderAll(); renderProps(); commit(true); }
            else if (act.dataset.act === 'del') { removeBlock(id); }
            return;
        }
        if (id !== selected) {
            select(id);
            // Si s'ha clicat sobre un text, que es pugui escriure de seguida.
            var t = mailEl.querySelector('.me-b[data-id="' + id + '"] [data-edit]');
            if (t && e.target.closest('[data-edit]')) t.focus();
        }
    });
    function move(from, to) {
        commit(true); // els canvis de text pendents queden com un pas propi de «Desfés»
        var b = doc.blocks.splice(from, 1)[0];
        if (to > from) to--;
        doc.blocks.splice(Math.max(0, Math.min(to, doc.blocks.length)), 0, b);
        renderAll(); commit(true);
        var el = mailEl.querySelector('.me-b[data-id="' + b.id + '"]'); if (el) el.focus();
    }
    function removeBlock(id) {
        var i = find(id); if (i < 0) return;
        commit(true);
        doc.blocks.splice(i, 1);
        if (selected === id) selected = doc.blocks[Math.min(i, doc.blocks.length - 1)] ? doc.blocks[Math.min(i, doc.blocks.length - 1)].id : null;
        renderAll(); renderProps(); commit(true);
        toast('Bloc eliminat. Es pot desfer amb Ctrl+Z.');
    }
    function newBlock(type) { var b = clone(CFG.defaults[type]); b.type = type; b.id = uid(); return b; }
    function insert(type, index) {
        commit(true);
        var b = newBlock(type);
        doc.blocks.splice(index, 0, b);
        selected = b.id; renderAll(); renderProps(); commit(true);
        var el = mailEl.querySelector('.me-b[data-id="' + b.id + '"]');
        if (el) { el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); var t = el.querySelector('[data-edit]'); if (t) setTimeout(function () { t.focus(); }, 50); }
        return b;
    }
    /** On va un bloc clicat a la paleta: sota el seleccionat; si no, abans del peu. */
    function defaultIndex() {
        var i = selected ? find(selected) : -1;
        if (i >= 0) return i + 1;
        var n = doc.blocks.length;
        return n && doc.blocks[n - 1].type === 'footer' ? n - 1 : n;
    }
    ['me-pal', 'me-pal-src'].forEach(function (id) { $(id).addEventListener('click', function (e) { var b = e.target.closest('button[data-type]'); if (b) insert(b.dataset.type, defaultIndex()); }); });

    document.addEventListener('keydown', function (e) {
        var inField = e.target.closest('input, textarea, select, [contenteditable="true"]');
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save().catch(function () {}); return; }
        if (inField) return;
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) doRedo(); else doUndo(); return; }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') { e.preventDefault(); doRedo(); return; }
        var el = e.target.closest && e.target.closest('.me-b');
        if (!el || e.target !== el) return;
        var i = find(el.dataset.id);
        if (e.key === 'Enter') { select(el.dataset.id, true); }
        else if (e.key === 'Escape') { select(null); }
        else if (e.altKey && e.key === 'ArrowUp' && i > 0) { e.preventDefault(); move(i, i - 1); }
        else if (e.altKey && e.key === 'ArrowDown' && i < doc.blocks.length - 1) { e.preventDefault(); move(i, i + 2); }
        else if (e.key === 'Delete') { removeBlock(el.dataset.id); }
    });

    // ------------------------------------------------------------ arrossegar i deixar anar
    var dragData = null;
    ['me-pal', 'me-pal-src'].forEach(function (id) { $(id).addEventListener('dragstart', function (e) {
        var b = e.target.closest('button[data-type]'); if (!b) return;
        dragData = { kind: 'new', type: b.dataset.type };
        e.dataTransfer.effectAllowed = 'copy'; e.dataTransfer.setData('text/plain', 'bloc:' + b.dataset.type);
    }); });
    mailEl.addEventListener('dragstart', function (e) {
        var g = e.target.closest('[data-act="grip"]'); if (!g) return;
        var el = g.closest('.me-b');
        dragData = { kind: 'move', id: el.dataset.id };
        e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'bloc');
        try { e.dataTransfer.setDragImage(el, 20, 20); } catch (x) {}
        setTimeout(function () { el.classList.add('dragging'); }, 0);
    });
    document.addEventListener('dragend', function () { dragData = null; hideDrop(); mailEl.querySelectorAll('.dragging').forEach(function (x) { x.classList.remove('dragging'); }); });
    function dropIndex(y) {
        var els = mailEl.querySelectorAll('.me-b');
        for (var i = 0; i < els.length; i++) { var r = els[i].getBoundingClientRect(); if (y < r.top + r.height / 2) return i; }
        return els.length;
    }
    function showDrop(index, label) {
        var d = $('me-drop'), els = mailEl.querySelectorAll('.me-b'), top;
        if (!d) return;
        var box = mailEl.getBoundingClientRect();
        if (!els.length) top = 20;
        else if (index < els.length) top = els[index].getBoundingClientRect().top - box.top - 2;
        else top = els[els.length - 1].getBoundingClientRect().bottom - box.top - 2;
        d.style.top = top + 'px'; d.style.display = 'block'; d.querySelector('span').textContent = label;
    }
    function hideDrop() { var d = $('me-drop'); if (d) d.style.display = 'none'; }
    function isFileDrag(e) { return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') >= 0; }
    mailEl.addEventListener('dragover', function (e) {
        if (!dragData && !isFileDrag(e)) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = dragData && dragData.kind === 'move' ? 'move' : 'copy';
        var imgTarget = isFileDrag(e) && e.target.closest('[data-drop-image]');
        if (imgTarget) { hideDrop(); return; }
        showDrop(dropIndex(e.clientY), dragData ? (dragData.kind === 'new' ? 'Deixa anar aquí: ' + CFG.types[dragData.type] : 'Mou aquí') : 'Deixa anar aquí: imatge');
    });
    mailEl.addEventListener('dragleave', function (e) { if (!mailEl.contains(e.relatedTarget)) hideDrop(); });
    mailEl.addEventListener('drop', function (e) {
        e.preventDefault(); hideDrop();
        var index = dropIndex(e.clientY);
        if (isFileDrag(e) && e.dataTransfer.files.length) {
            var file = e.dataTransfer.files[0], onImage = e.target.closest('[data-drop-image]');
            var target = onImage ? block(onImage.closest('.me-b').dataset.id) : insert('image', index);
            upload(file, function (src) { target.src = src; if (!target.alt) target.alt = file.name.replace(/\.[a-z0-9]+$/i, ''); selected = target.id; renderAll(); renderProps(); commit(true); });
            return;
        }
        if (!dragData) return;
        if (dragData.kind === 'new') insert(dragData.type, index);
        else { var from = find(dragData.id); if (from >= 0 && index !== from && index !== from + 1) move(from, index); }
        dragData = null;
    });

    // ------------------------------------------------------------ imatges
    function upload(file, done, name) {
        if (!/^image\//.test(file.type)) { toast('Només es poden afegir imatges (JPG, PNG o GIF).', true); return; }
        var fd = new FormData(); name ? fd.append('image', file, name) : fd.append('image', file);
        toast(name ? 'Desant el retall…' : 'Pujant la imatge…');
        api(CFG.urls.upload, { method: 'POST', body: fd }).then(function (r) {
            if (!r.ok) return errorText(r).then(function (m) { throw new Error(m); });
            return r.json();
        }).then(function (j) { CFG.images[j.src] = j.url; done(j.src, j); }).catch(function (e) { toast(e.message, true); });
    }

    // ------------------------------------------------------------ retallar imatges
    // La imatge es carrega com a blob (no «embruta» el canvas), es marca la zona
    // a la vista i es retalla a la mida original. El resultat es puja com una
    // imatge nova; l'original queda (i «Desfés» la recupera).
    var crop = { ratio: 0, x: 0, y: 0, w: 0, h: 0, nat: null, blobUrl: null, mime: 'image/png', done: null };
    var CROP_MIN = 24;
    function cropScale() { var im = $('me-crop-img'); return im.naturalWidth / im.clientWidth; }
    function cropPaint() {
        var box = $('me-crop-box'), s = cropScale();
        box.style.left = crop.x + 'px'; box.style.top = crop.y + 'px'; box.style.width = crop.w + 'px'; box.style.height = crop.h + 'px';
        $('me-crop-size').textContent = Math.round(crop.w * s) + ' × ' + Math.round(crop.h * s) + ' px';
    }
    function cropFit(full) {
        var im = $('me-crop-img'), W = im.clientWidth, H = im.clientHeight;
        if (full || !crop.ratio) { crop.x = 0; crop.y = 0; crop.w = W; crop.h = H; }
        if (crop.ratio) {
            var w = W, hh = w / crop.ratio;
            if (hh > H) { hh = H; w = hh * crop.ratio; }
            crop.w = w; crop.h = hh; crop.x = (W - w) / 2; crop.y = (H - hh) / 2;
        }
        cropPaint();
    }
    function cropClamp() {
        var im = $('me-crop-img'), W = im.clientWidth, H = im.clientHeight;
        crop.w = Math.min(Math.max(crop.w, CROP_MIN), W); crop.h = Math.min(Math.max(crop.h, CROP_MIN), H);
        crop.x = Math.min(Math.max(crop.x, 0), W - crop.w); crop.y = Math.min(Math.max(crop.y, 0), H - crop.h);
    }
    function openCrop(src, done) {
        var url = imgUrl(src);
        if (!url) return;
        toast('Obrint la imatge…');
        // Les imatges «img:» són d'aquest mateix servidor: per l'adreça relativa no hi ha problemes de CORS.
        fetch(url.replace(/^https?:\/\/[^/]+/, ''), { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error(); return r.blob(); }).then(function (blob) {
            if (crop.blobUrl) URL.revokeObjectURL(crop.blobUrl);
            crop.blobUrl = URL.createObjectURL(blob);
            // PNG (i GIF, que queda estàtica) es retallen a PNG; la resta, a JPEG.
            crop.mime = /png|gif/.test(blob.type) ? 'image/png' : 'image/jpeg';
            crop.done = done;
            var im = $('me-crop-img');
            im.onload = function () { document.querySelectorAll('.me-toast').forEach(function (x) { x.remove(); }); $('me-crop-dlg').showModal(); cropFit(true); $('me-crop-box').focus(); };
            im.onerror = function () { toast('No s\'ha pogut obrir la imatge per retallar-la.', true); };
            im.src = crop.blobUrl;
        }).catch(function () { toast('No s\'ha pogut obrir la imatge per retallar-la.', true); });
    }
    (function () {
        var stage = $('me-crop-stage'), box = $('me-crop-box'), drag = null;
        if (!stage) return;
        document.querySelectorAll('.me-crop-ratios [data-ratio]').forEach(function (b) {
            b.addEventListener('click', function () {
                crop.ratio = parseFloat(b.dataset.ratio) || 0;
                document.querySelectorAll('.me-crop-ratios [data-ratio]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
                if (crop.ratio) cropFit(false);
            });
        });
        $('me-crop-reset').addEventListener('click', function () { cropFit(true); });
        box.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            var hd = e.target.closest('[data-h]');
            drag = { h: hd ? hd.dataset.h : 'move', sx: e.clientX, sy: e.clientY, x: crop.x, y: crop.y, w: crop.w, hh: crop.h };
            box.setPointerCapture(e.pointerId);
        });
        box.addEventListener('pointermove', function (e) {
            if (!drag) return;
            var im = $('me-crop-img'), W = im.clientWidth, H = im.clientHeight;
            var dx = e.clientX - drag.sx, dy = e.clientY - drag.sy, k = drag.h;
            if (k === 'move') { crop.x = drag.x + dx; crop.y = drag.y + dy; cropClamp(); cropPaint(); return; }
            var x1 = drag.x, y1 = drag.y, x2 = drag.x + drag.w, y2 = drag.y + drag.hh;
            if (k.indexOf('w') >= 0) x1 = Math.min(Math.max(0, x1 + dx), x2 - CROP_MIN);
            if (k.indexOf('e') >= 0) x2 = Math.max(Math.min(W, x2 + dx), x1 + CROP_MIN);
            if (k.indexOf('n') >= 0) y1 = Math.min(Math.max(0, y1 + dy), y2 - CROP_MIN);
            if (k.indexOf('s') >= 0) y2 = Math.max(Math.min(H, y2 + dy), y1 + CROP_MIN);
            var w = x2 - x1, hh = y2 - y1;
            if (crop.ratio) {
                // Amb proporció fixa: mana l'amplada (o l'alçada si s'estira per dalt/baix).
                if (k === 'n' || k === 's') w = hh * crop.ratio; else hh = w / crop.ratio;
                if (k.indexOf('w') >= 0) x1 = x2 - w; else if (k === 'n' || k === 's') x1 = drag.x + (drag.w - w) / 2;
                if (k.indexOf('n') >= 0) y1 = y2 - hh;
                if (x1 < 0 || y1 < 0 || x1 + w > W || y1 + hh > H) return;
            }
            crop.x = x1; crop.y = y1; crop.w = w; crop.h = hh; cropPaint();
        });
        ['pointerup', 'pointercancel'].forEach(function (ev) { box.addEventListener(ev, function () { drag = null; }); });
        box.addEventListener('keydown', function (e) {
            var step = e.altKey ? 1 : 10, m = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
            if (!m) return;
            e.preventDefault();
            if (e.shiftKey) { crop.w += m[0]; crop.h = crop.ratio ? crop.w / crop.ratio : crop.h + m[1]; } else { crop.x += m[0]; crop.y += m[1]; }
            cropClamp(); cropPaint();
        });
        window.addEventListener('resize', function () { if ($('me-crop-dlg').open) cropFit(true); });
        $('me-crop-ok').addEventListener('click', function () {
            var im = $('me-crop-img'), s = cropScale();
            var sx = Math.round(crop.x * s), sy = Math.round(crop.y * s), sw = Math.max(1, Math.round(crop.w * s)), sh = Math.max(1, Math.round(crop.h * s));
            sw = Math.min(sw, im.naturalWidth - sx); sh = Math.min(sh, im.naturalHeight - sy);
            var c = document.createElement('canvas'); c.width = sw; c.height = sh;
            var ctx = c.getContext('2d');
            if (crop.mime === 'image/jpeg') { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, sw, sh); }
            ctx.drawImage(im, sx, sy, sw, sh, 0, 0, sw, sh);
            var btn = this; btn.disabled = true;
            c.toBlob(function (blob) {
                btn.disabled = false;
                if (!blob) { toast('No s\'ha pogut retallar la imatge.', true); return; }
                var done = crop.done;
                $('me-crop-dlg').close();
                upload(blob, function (src) { toast('Imatge retallada.'); if (done) done(src); }, 'retall.' + (crop.mime === 'image/png' ? 'png' : 'jpg'));
            }, crop.mime, 0.92);
        });
    })();

    // ------------------------------------------------------------ panell d'opcions
    function h(tag, attrs, html) { var el = document.createElement(tag); Object.keys(attrs || {}).forEach(function (k) { el.setAttribute(k, attrs[k]); }); if (html != null) el.innerHTML = html; return el; }
    function changed(obj, rerender) {
        if (rerender === 'all') renderAll(); else if (selected) renderBlock(selected);
        commit();
    }
    function fText(parent, label, obj, key, opts) {
        opts = opts || {};
        var f = h('label', { 'class': 'me-f' }, esc(label));
        var input = h(opts.area ? 'textarea' : 'input', opts.area ? { maxlength: opts.max || 1500 } : { type: opts.type || 'text', maxlength: opts.max || 300 });
        input.value = obj[key] == null ? '' : obj[key];
        if (opts.placeholder) input.placeholder = opts.placeholder;
        input.addEventListener('input', function () { obj[key] = input.value; changed(obj, opts.rerender); });
        f.appendChild(input); parent.appendChild(f); return input;
    }
    function fUrl(parent, label, obj, key) {
        var input = fText(parent, label, obj, key, { type: 'url', max: 1000, placeholder: 'https://…' });
        input.addEventListener('change', function () {
            var v = input.value.trim();
            if (/^www\./i.test(v)) { v = 'https://' + v; input.value = v; obj[key] = v; changed(obj); }
            input.setCustomValidity(v && !/^(https?:\/\/|mailto:|tel:)/i.test(v) ? 'Ha de començar per https://, mailto: o tel:' : '');
            input.reportValidity();
        });
        return input;
    }
    function fRange(parent, label, obj, key, min, max, step, unit, rerender) {
        var f = h('div', { 'class': 'me-f' }); var id = 'r' + Math.random().toString(16).slice(2);
        f.appendChild(h('label', { 'for': id }, esc(label)));
        var row = h('div', { 'class': 'row' }), input = h('input', { type: 'range', min: min, max: max, step: step || 1, id: id }), val = h('span', { 'class': 'val' });
        input.value = obj[key]; val.textContent = obj[key] + (unit || '');
        input.addEventListener('input', function () { obj[key] = Number(input.value); val.textContent = input.value + (unit || ''); changed(obj, rerender); });
        row.appendChild(input); row.appendChild(val); f.appendChild(row); parent.appendChild(f);
    }
    function fSeg(parent, label, obj, key, options, rerender, after) {
        var f = h('div', { 'class': 'me-f' }); f.appendChild(h('span', {}, esc(label)));
        var seg = h('div', { 'class': 'seg', role: 'group', 'aria-label': label });
        options.forEach(function (o) {
            var b = h('button', { type: 'button', 'aria-pressed': String(obj[key]) === String(o[0]) ? 'true' : 'false' }, esc(o[1]));
            b.addEventListener('click', function () {
                obj[key] = o[0]; seg.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); }); b.setAttribute('aria-pressed', 'true');
                changed(obj, rerender); if (after) after();
            });
            seg.appendChild(b);
        });
        f.appendChild(seg); parent.appendChild(f);
    }
    function fSelect(parent, label, obj, key, options, rerender) {
        var f = h('label', { 'class': 'me-f' }, esc(label)), sel = h('select');
        options.forEach(function (o) { var op = h('option', { value: o[0] }, esc(o[1])); if (String(obj[key]) === String(o[0])) op.selected = true; sel.appendChild(op); });
        sel.addEventListener('change', function () { obj[key] = isNaN(Number(sel.value)) || sel.value === '' ? sel.value : Number(sel.value); changed(obj, rerender); });
        f.appendChild(sel); parent.appendChild(f);
    }
    function fCheck(parent, label, obj, key, rerender) {
        var l = h('label', { 'class': 'me-chk' }), c = h('input', { type: 'checkbox' }); c.checked = !!obj[key];
        c.addEventListener('change', function () { obj[key] = c.checked; changed(obj, rerender); });
        l.appendChild(c); l.appendChild(document.createTextNode(label)); parent.appendChild(l);
    }
    function fColor(parent, label, obj, key, allowEmpty, rerender) {
        var f = h('div', { 'class': 'me-f' }); f.appendChild(h('span', {}, esc(label)));
        var sw = h('div', { 'class': 'me-sw', role: 'group', 'aria-label': label });
        function paint() { sw.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', (x.dataset.c || '') === (obj[key] || '') ? 'true' : 'false'); }); picker.value = obj[key] || '#ffffff'; }
        if (allowEmpty) { var n = h('button', { type: 'button', 'class': 'none', title: 'Cap (el per defecte)', 'aria-label': 'Cap color' }); n.dataset.c = ''; sw.appendChild(n); }
        SWATCHES.forEach(function (c) { var b = h('button', { type: 'button', title: c, 'aria-label': 'Color ' + c }); b.style.background = c; b.dataset.c = c; sw.appendChild(b); });
        var picker = h('input', { type: 'color', title: 'Un altre color', 'aria-label': 'Un altre color' });
        sw.appendChild(picker);
        sw.addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; obj[key] = b.dataset.c || ''; paint(); changed(obj, rerender); });
        picker.addEventListener('input', function () { obj[key] = picker.value; paint(); changed(obj, rerender); });
        f.appendChild(sw); parent.appendChild(f); paint();
    }
    function fImage(parent, obj, key, opts) {
        opts = opts || {};
        var g = h('div', { 'class': 'me-group' }); g.appendChild(h('b', {}, opts.title || 'Imatge'));
        var prev = h('img', { 'class': 'me-imgprev', alt: '' });
        function paint() { var u = imgUrl(obj[key]); prev.hidden = !u; if (u) prev.src = u; }
        g.appendChild(prev); paint();
        var row = h('div', { style: 'display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;' });
        var file = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', hidden: '' });
        var up = h('button', { type: 'button', 'class': 'btn secondary', style: 'padding:6px 10px;font-size:12.5px;' }, 'Puja una imatge');
        up.addEventListener('click', function () { file.click(); });
        file.addEventListener('change', function () { if (file.files[0]) upload(file.files[0], function (src) { obj[key] = src; paint(); url.value = ''; changed(obj); commit(true); renderProps(); }); });
        row.appendChild(up); row.appendChild(file);
        if (opts.logo && CFG.images['brand:logo']) { var lg = h('button', { type: 'button', 'class': 'btn secondary', style: 'padding:6px 10px;font-size:12.5px;' }, 'Logotip del projecte'); lg.addEventListener('click', function () { obj[key] = 'brand:logo'; paint(); changed(obj); }); row.appendChild(lg); }
        // Retallar: les imatges pujades o creades amb IA (són al servidor de la plataforma).
        if (/^img:/.test(obj[key] || '')) {
            var cr = h('button', { type: 'button', 'class': 'btn secondary', style: 'padding:6px 10px;font-size:12.5px;' }, '✂ Retalla');
            cr.addEventListener('click', function () { openCrop(obj[key], function (src) { obj[key] = src; paint(); changed(obj); commit(true); renderProps(); }); });
            row.appendChild(cr);
        }
        if (obj[key]) { var rm = h('button', { type: 'button', 'class': 'btn secondary', style: 'padding:6px 10px;font-size:12.5px;' }, 'Treu-la'); rm.addEventListener('click', function () { obj[key] = ''; paint(); changed(obj); renderProps(); }); row.appendChild(rm); }
        g.appendChild(row);
        var tmp = { u: /^https:\/\//.test(obj[key] || '') ? obj[key] : '' };
        var url = fText(g, 'O l\'adreça d\'una imatge d\'internet', tmp, 'u', { type: 'url', max: 1000, placeholder: 'https://…' });
        url.addEventListener('change', function () { var v = url.value.trim(); if (/^https:\/\//.test(v)) { obj[key] = v; paint(); changed(obj); } else if (v) toast('L\'adreça de la imatge ha de començar per https://', true); });
        if (opts.alt !== false) fText(g, 'Text alternatiu (si no es veu la imatge)', obj, 'alt', { max: 200 });
        parent.appendChild(g);
    }
    function fRich(parent, label, obj, key) {
        var f = h('div', { 'class': 'me-f' }); f.appendChild(h('span', {}, esc(label)));
        var ed = h('div', { 'class': 'me-rich', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-label': label });
        ed.innerHTML = obj[key] || '';
        ed.addEventListener('focus', function () { editing = ed; showRt(ed); try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (x) {} });
        ed.addEventListener('input', function () { obj[key] = ed.innerHTML; if (selected) renderBlock(selected); commit(); });
        ed.addEventListener('paste', function (e) { e.preventDefault(); var html = e.clipboardData.getData('text/html'), text = e.clipboardData.getData('text/plain'); document.execCommand('insertHTML', false, html ? cleanHtml(html) : esc(text).replace(/\n/g, '<br>')); });
        f.appendChild(ed); parent.appendChild(f);
    }
    function fCommon(parent, b) {
        parent.appendChild(h('div', { 'class': 'me-sep' }));
        fRange(parent, 'Espai a dalt', b, 'pt', 0, 80, 2, ' px');
        fRange(parent, 'Espai a baix', b, 'pb', 0, 80, 2, ' px');
        fColor(parent, 'Fons de la franja', b, 'bg', true);
        fCheck(parent, 'Amaga-ho al mòbil', b, 'hideMobile');
    }
    var ALIGN = [['left', 'Esquerra'], ['center', 'Centre'], ['right', 'Dreta']];
    var SIZES = [[0, 'Per defecte'], [13, '13 px'], [14, '14 px'], [15, '15 px'], [16, '16 px'], [17, '17 px'], [18, '18 px'], [20, '20 px']];

    var PANELS = {
        header: function (p, b) {
            fImage(p, b, 'src', { title: 'Logotip', logo: true });
            fRange(p, 'Amplada del logotip', b, 'width', 80, 560, 5, ' px');
            fSeg(p, 'Alineació', b, 'align', ALIGN);
            fUrl(p, 'Enllaç del logotip (opcional)', b, 'href');
            fColor(p, 'Línia de dalt', b, 'borderTop', true);
            fRange(p, 'Gruix de la línia', b, 'borderWidth', 0, 8, 1, ' px');
        },
        heading: function (p, b) {
            fText(p, 'Text del títol', b, 'text', { rerender: 'block', max: 300 });
            fSeg(p, 'Tipus', b, 'level', [[1, 'Títol'], [2, 'Subtítol']], null, function () { b.size = b.level === 2 ? 19 : 18; b.color = ''; renderProps(); renderBlock(b.id); });
            fRange(p, 'Mida', b, 'size', 14, 34, 1, ' px');
            fColor(p, 'Color (cap = el de l\'estil)', b, 'color', true);
            fSeg(p, 'Alineació', b, 'align', ALIGN);
        },
        text: function (p, b) {
            p.appendChild(h('p', { 'class': 'muted', style: 'font-size:12.5px;margin:0 0 12px;' }, 'Escriu directament al correu. Selecciona text per posar-hi negreta, enllaços o llistes.'));
            fSelect(p, 'Mida del text', b, 'size', SIZES);
            fColor(p, 'Color del text (cap = el de l\'estil)', b, 'color', true);
            fSeg(p, 'Alineació', b, 'align', ALIGN);
        },
        image: function (p, b) {
            fImage(p, b, 'src');
            fSelect(p, 'Amplada', b, 'width', [[0, 'Tota l\'amplada'], [480, '480 px'], [400, '400 px'], [300, '300 px'], [200, '200 px'], [120, '120 px']]);
            fSeg(p, 'Alineació', b, 'align', ALIGN);
            fRange(p, 'Cantonades arrodonides', b, 'radius', 0, 30, 1, ' px');
            fUrl(p, 'Enllaç en clicar la imatge (opcional)', b, 'href');
        },
        button: function (p, b) {
            fText(p, 'Text del botó', b, 'text', { max: 80 });
            fUrl(p, 'Enllaç', b, 'href');
            fColor(p, 'Color del botó (cap = el de l\'estil)', b, 'btnBg', true);
            fColor(p, 'Color del text', b, 'color', false);
            fSeg(p, 'Alineació', b, 'align', ALIGN);
            fSeg(p, 'Amplada', b, 'full', [[false, 'Segons el text'], [true, 'Tota l\'amplada']]);
            fRange(p, 'Mida del text', b, 'size', 13, 22, 1, ' px');
            fRange(p, 'Cantonades', b, 'radius', 0, 30, 1, ' px');
            fCheck(p, 'Al mòbil, tota l\'amplada', b, 'mobileFull');
            p.appendChild(h('p', { 'class': 'muted', style: 'font-size:12px;margin:0 0 10px;' }, 'A Outlook per a Windows el botó es dibuixa amb un format especial perquè es vegi igual.'));
        },
        link: function (p, b) {
            fText(p, 'Text de l\'enllaç', b, 'text', { max: 160 });
            fUrl(p, 'Adreça', b, 'href');
            fColor(p, 'Color (cap = el dels enllaços)', b, 'color', true);
            fCheck(p, 'Negreta', b, 'bold');
            fSelect(p, 'Mida', b, 'size', SIZES);
            fSeg(p, 'Alineació', b, 'align', ALIGN);
        },
        box: function (p, b) {
            p.appendChild(h('p', { 'class': 'muted', style: 'font-size:12.5px;margin:0 0 12px;' }, 'Escriu directament dins del requadre, al correu.'));
            fColor(p, 'Fons del requadre', b, 'boxBg', false);
            fColor(p, 'Color del text (cap = el de l\'estil)', b, 'color', true);
            fSelect(p, 'Mida del text', b, 'size', SIZES);
            fRange(p, 'Cantonades', b, 'radius', 0, 30, 1, ' px');
        },
        columns: function (p, b) {
            fSeg(p, 'Columnes', b, 'count', [[2, '2'], [3, '3']], null, function () {
                while (b.cols.length < b.count) b.cols.push(clone(CFG.defaults.columns.cols[0]));
                b.cols.length = b.count; renderProps(); renderBlock(b.id);
            });
            p.appendChild(h('p', { 'class': 'muted', style: 'font-size:12px;margin:0 0 10px;' }, 'Al mòbil, les columnes es posen una sota l\'altra.'));
            b.cols.forEach(function (c, i) {
                var g = h('div', { 'class': 'me-group' }); g.appendChild(h('b', {}, 'Columna ' + (i + 1)));
                colFields(g, c);
                p.appendChild(g);
            });
        },
        imagetext: function (p, b) {
            fSeg(p, 'Imatge a', b, 'side', [['left', 'L\'esquerra'], ['right', 'La dreta']]);
            colFields(p, b);
        },
        divider: function (p, b) {
            fColor(p, 'Color', b, 'color', false);
            fRange(p, 'Gruix', b, 'thickness', 1, 6, 1, ' px');
            fRange(p, 'Amplada', b, 'widthPct', 10, 100, 5, ' %');
        },
        spacer: function (p, b) { fRange(p, 'Alçada', b, 'height', 4, 120, 2, ' px'); },
        event: function (p, b) { sourcePanel(p, b, [['day', 'Dia (requadre de color, p. ex. 14)', 4], ['month', 'Mes (p. ex. nov.)', 12], ['when', 'Quan', 160], ['location', 'On', 300]], 'Enllaç (opcional: inscripció, reunió en línia…)'); fColor(p, 'Fons del requadre', b, 'boxBg', false); },
        post: function (p, b) {
            sourcePanel(p, b, [['date', 'Data (opcional)', 60]], 'Enllaç a l\'article');
            fSeg(p, 'Imatge', b, 'layout', [['card', 'A dalt'], ['side', 'Al costat']]);
            fImage(p, b, 'src');
        },
        footer: function (p, b) {
            fImage(p, b, 'src', { title: 'Logotip', logo: true });
            fRange(p, 'Amplada del logotip (0 = sense)', b, 'width', 0, 400, 5, ' px');
            fText(p, 'Nom', b, 'name', { max: 200 });
            var g = h('div', { 'class': 'me-group' }); g.appendChild(h('b', {}, 'Enllaços'));
            b.links.forEach(function (l, i) {
                var row = h('div', { style: 'display:grid;grid-template-columns:1fr auto;gap:6px;align-items:end;' });
                var col = h('div');
                fText(col, 'Text', l, 'text', { max: 60 }); fUrl(col, 'Adreça', l, 'href');
                var rm = h('button', { type: 'button', 'class': 'btn secondary', style: 'padding:6px 9px;margin-bottom:12px;', 'aria-label': 'Treu l\'enllaç ' + (i + 1) }, '✕');
                rm.addEventListener('click', function () { b.links.splice(i, 1); renderProps(); renderBlock(b.id); commit(true); });
                row.appendChild(col); row.appendChild(rm); g.appendChild(row);
            });
            if (b.links.length < 6) {
                var add = h('button', { type: 'button', 'class': 'btn secondary', style: 'padding:6px 10px;font-size:12.5px;' }, '+ Afegeix un enllaç');
                add.addEventListener('click', function () { b.links.push({ text: 'Enllaç', href: '' }); renderProps(); renderBlock(b.id); commit(true); });
                g.appendChild(add);
            }
            p.appendChild(g);
            fText(p, 'Nota legal o text petit (opcional)', b, 'note', { area: true, max: 1500 });
            fColor(p, 'Línia de dalt', b, 'lineColor', true);
            fColor(p, 'Color del text', b, 'color', false);
            fColor(p, 'Color dels enllaços (cap = el de l\'estil)', b, 'linkColor', true);
        }
    };
    function sourcePanel(p, b, extra, hrefLabel) {
        fText(p, 'Títol', b, 'title', { max: 300 });
        extra.forEach(function (x) { fText(p, x[1], b, x[0], { max: x[2] }); });
        fText(p, b.type === 'post' ? 'Resum' : 'Text', b, 'desc', { area: true, max: 1500 });
        fText(p, 'Text de l\'enllaç', b, 'btnText', { max: 80 });
        fUrl(p, hrefLabel, b, 'href');
    }

    function colFields(g, c) {
        if (CFG.ai.show) {
            var owner = selected;
            var row = h('div', { style: 'display:flex;gap:6px;' });
            var bt = h('button', { type: 'button', 'class': 'btn secondary me-ai-btn', style: 'margin:0 0 10px;' }, '✨ Text amb IA');
            bt.addEventListener('click', function () { openAi('text', { block: owner, col: c }); });
            var bi = h('button', { type: 'button', 'class': 'btn secondary me-ai-btn', style: 'margin:0 0 10px;' }, '✨ Imatge amb IA');
            bi.addEventListener('click', function () { openAi('image', { block: owner, col: c }); });
            row.appendChild(bt); row.appendChild(bi); g.appendChild(row);
        }
        fImage(g, c, 'src');
        fText(g, 'Títol', c, 'title', { max: 200 });
        fRich(g, 'Text', c, 'html');
        fText(g, 'Text de l\'enllaç (opcional)', c, 'linkText', { max: 80, placeholder: 'Més informació >' });
        fUrl(g, 'Adreça de l\'enllaç', c, 'href');
    }

    function renderProps() {
        propsEl.innerHTML = '';
        var b = selected ? block(selected) : null;
        if (!b) {
            propsEl.innerHTML = '<span class="me-kicker">Opcions</span><p class="muted" style="font-size:13px;line-height:1.5;margin:0 0 10px;">Clica un bloc del correu per canviar-ne el text, els colors, les imatges o els enllaços.</p>'
                + '<p class="muted" style="font-size:12.5px;line-height:1.5;margin:0;"><b>Dreceres:</b> Ctrl+S desa · Ctrl+Z desfà · amb un bloc seleccionat, Alt+↑/↓ el mou i Supr l\'elimina.</p>';
            return;
        }
        var head = h('div', { style: 'display:flex;align-items:center;gap:8px;margin-bottom:12px;' });
        head.appendChild(h('span', { 'class': 'me-kicker', style: 'margin:0;flex:1;' }, 'Bloc seleccionat'));
        head.appendChild(h('h3', {}, esc(CFG.types[b.type])));
        propsEl.appendChild(head);
        aiButtons(propsEl, b);
        (PANELS[b.type] || function () {})(propsEl, b);
        fCommon(propsEl, b);
        var actions = h('div', { style: 'display:flex;gap:6px;margin-top:6px;' });
        var dup = h('button', { type: 'button', 'class': 'btn secondary', style: 'flex:1;' }, 'Duplica'), del = h('button', { type: 'button', 'class': 'btn danger', style: 'flex:1;' }, 'Elimina');
        dup.addEventListener('click', function () { commit(true); var i = find(b.id), c = clone(b); c.id = uid(); doc.blocks.splice(i + 1, 0, c); selected = c.id; renderAll(); renderProps(); commit(true); });
        del.addEventListener('click', function () { removeBlock(b.id); });
        actions.appendChild(dup); actions.appendChild(del); propsEl.appendChild(actions);
    }

    // ------------------------------------------------------------ estils generals
    function renderStyles() {
        var p = $('me-styles'); p.innerHTML = '';
        var s = S();
        p.appendChild(h('span', { 'class': 'me-kicker' }, 'Tot el correu'));
        fSelect(p, 'Tipus de lletra', s, 'font', Object.keys(CFG.fonts).map(function (k) { return [k, CFG.fonts[k].label]; }), 'all');
        fRange(p, 'Mida del text', s, 'textSize', 13, 20, 1, ' px', 'all');
        fColor(p, 'Color del text', s, 'textColor', false, 'all');
        fColor(p, 'Color dels subtítols', s, 'headingColor', false, 'all');
        fColor(p, 'Color dels enllaços', s, 'linkColor', false, 'all');
        fColor(p, 'Color dels botons', s, 'accent', false, 'all');
        fColor(p, 'Fons del correu', s, 'contentBg', false, 'all');
        fColor(p, 'Fons de fora', s, 'bodyBg', false, 'all');
        fRange(p, 'Marges laterals', s, 'padX', 0, 40, 2, ' px', 'all');
        p.appendChild(h('p', { 'class': 'muted', style: 'font-size:12px;line-height:1.5;margin:6px 0 0;' }, 'Els tipus de lletra són els que tenen tots els ordinadors i mòbils: així el correu es veu igual a tot arreu.'));
    }
    document.querySelector('.me-tabs').addEventListener('click', function (e) {
        var t = e.target.closest('button[data-tab]'); if (!t) return;
        document.querySelectorAll('.me-tabs button').forEach(function (b) { b.setAttribute('aria-selected', b === t ? 'true' : 'false'); });
        document.querySelectorAll('[data-pane]').forEach(function (p) { p.hidden = p.dataset.pane !== t.dataset.tab; });
        if (t.dataset.tab === 'styles') renderStyles();
    });

    // ------------------------------------------------------------ previsualització
    var prevHtml = '', prevMode = 'both';
    function setFrames() {
        var html = prevHtml;
        if ($('me-prev-noimg').checked) html = html.replace(/<img\b([^>]*?)\ssrc="[^"]*"/gi, '<img$1 src="data:,"');
        $('me-if-desk').srcdoc = html; $('me-if-phone').srcdoc = html;
    }
    function paintMode() {
        document.querySelectorAll('#me-prev-mode button').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.mode === prevMode ? 'true' : 'false'); });
        document.querySelector('.me-pane.desk').hidden = prevMode === 'phone';
        document.querySelector('.me-pane.ph').hidden = prevMode === 'desk';
    }
    function openPreview(promise, label) {
        $('me-prev-title').textContent = label || 'Previsualització';
        prevHtml = '<p style="font:14px system-ui;padding:20px;color:#6b7280">Carregant…</p>'; setFrames(); paintMode();
        $('me-prev').showModal();
        promise.then(function (r) { if (!r.ok) throw new Error(); return r.text(); })
            .then(function (t) { prevHtml = t; setFrames(); })
            .catch(function () { prevHtml = '<p style="font:14px system-ui;padding:20px;color:#b42318">No s\'ha pogut carregar la previsualització.</p>'; setFrames(); });
    }
    $('me-preview').addEventListener('click', function () {
        commit(true);
        stash();
        openPreview(api(CFG.urls.render, { method: 'POST', json: { doc: envelope, lang: lang }, headers: { Accept: 'text/html' } }), 'Previsualització: ' + title + (Object.keys(envelope.langs).length > 1 ? ' (' + CFG.langs[lang] + ')' : ''));
    });
    $('me-prev-mode').addEventListener('click', function (e) { var b = e.target.closest('button[data-mode]'); if (b) { prevMode = b.dataset.mode; paintMode(); } });
    $('me-prev-noimg').addEventListener('change', setFrames);

    // ------------------------------------------------------------ més opcions
    $('me-history').addEventListener('click', function () {
        $('me-more').removeAttribute('open');
        var list = $('me-hist-list'); list.innerHTML = '<span class="muted">Carregant…</span>';
        $('me-hist').showModal();
        api(CFG.urls.versions).then(function (r) { return r.json(); }).then(function (j) {
            list.innerHTML = '';
            j.versions.forEach(function (v) {
                var row = h('div', { 'class': 'me-hist-row' });
                row.appendChild(h('div', { style: 'flex:1;min-width:0;' }, '<b>Versió ' + v.version + '</b>' + (v.current ? ' <span class="pill" style="background:#e6f4ec;color:#1a7f56;font-size:11px;">Actual</span>' : '')
                    + '<div class="muted" style="font-size:12.5px;">' + esc(v.when) + ' · ' + esc(v.by) + (v.status && !CFG.isTemplate ? ' · ' + esc(v.status) : '') + '</div>'));
                var pv = h('button', { type: 'button', 'class': 'btn secondary', style: 'padding:6px 10px;font-size:12.5px;' }, 'Mira-la');
                pv.addEventListener('click', function () { $('me-hist').close(); openPreview(api(v.preview, { headers: { Accept: 'text/html' } }), 'Versió ' + v.version + ' · ' + v.when); });
                row.appendChild(pv);
                if (!v.current) {
                    var f = h('form', { method: 'POST', action: v.restore, style: 'margin:0;' }, '<input type="hidden" name="_csrf" value="' + esc(CFG.csrf) + '"><button type="submit" class="btn secondary" style="padding:6px 10px;font-size:12.5px;">Recupera-la</button>');
                    f.addEventListener('submit', function (e) {
                        if (!confirm('Recuperar la versió ' + v.version + '? Es desarà com a versió nova.' + (dirty() ? '\n\nEls canvis que no has desat es perdran.' : ''))) { e.preventDefault(); return; }
                        savedState = fullSnap();
                    });
                    row.appendChild(f);
                }
                list.appendChild(row);
            });
        }).catch(function () { list.innerHTML = '<span style="color:#b42318">No s\'ha pogut carregar l\'historial.</span>'; });
    });
    if ($('me-test')) $('me-test').addEventListener('click', function () {
        $('me-more').removeAttribute('open');
        var to = prompt('Envia una prova a (separa les adreces amb comes):', CFG.me || '');
        if (!to) return;
        ensureSaved().then(function () { return api(CFG.urls.test, { method: 'POST', json: { emails: to, lang: lang } }); })
            .then(function (r) { return r.json().then(function (j) { toast(j.message || (r.ok ? 'Enviat.' : 'No s\'ha pogut enviar.'), !r.ok); }); })
            .catch(function () {});
    });
    $('me-astemplate').addEventListener('click', function () {
        $('me-more').removeAttribute('open');
        var name = prompt('Nom de la plantilla nova:', CFG.isTemplate ? tplName + ' (còpia)' : title);
        if (!name) return;
        ensureSaved().then(function () { return api(CFG.urls.asTemplate, { method: 'POST', json: { name: name } }); })
            .then(function (r) { if (!r.ok) return errorText(r).then(function (m) { throw new Error(m); }); return r.json(); })
            .then(function (j) { toast(j.message); })
            .catch(function (e) { if (e && e.message) toast(e.message, true); });
    });
    // ------------------------------------------------------------ IA (textos i imatges)
    var AI_TEXT = { text: 'html', box: 'html', heading: 'plain', button: 'plain', link: 'plain' };
    var AI_IMAGE = { image: 1, imagetext: 1 };
    var aiMode = 'text', aiTarget = null, aiResult = null;
    function aiButtons(p, b) {
        if (!CFG.ai.show || b.type === 'columns') return;
        if (AI_TEXT[b.type]) {
            var bt = h('button', { type: 'button', 'class': 'btn secondary me-ai-btn' }, b.type === 'heading' || b.type === 'button' || b.type === 'link' ? '✨ Escriu-lo amb IA' : '✨ Escriu el text amb IA');
            bt.addEventListener('click', function () { openAi('text', { block: b.id }); });
            p.appendChild(bt);
        }
        if (AI_IMAGE[b.type]) {
            var bi = h('button', { type: 'button', 'class': 'btn secondary me-ai-btn' }, '✨ Crea la imatge amb IA');
            bi.addEventListener('click', function () { openAi('image', { block: b.id }); });
            p.appendChild(bi);
        }
        if (b.type === 'imagetext') {
            var bt2 = h('button', { type: 'button', 'class': 'btn secondary me-ai-btn' }, '✨ Escriu el text amb IA');
            bt2.addEventListener('click', function () { openAi('text', { block: b.id, col: b }); });
            p.appendChild(bt2);
        }
    }
    function plainOf(html) { var d = document.createElement('div'); d.innerHTML = (html || '').replace(/<\/(p|li|h3|h4)>/g, '$&\n'); return d.textContent.replace(/\n{3,}/g, '\n\n').trim(); }
    function mailText() {
        var out = [];
        doc.blocks.forEach(function (b) {
            if (b.type === 'heading' && b.text) out.push(b.text);
            else if ((b.type === 'text' || b.type === 'box') && b.html) out.push(plainOf(b.html));
            else if (b.type === 'columns') b.cols.forEach(function (c) { out.push([c.title, plainOf(c.html)].filter(Boolean).join('\n')); });
            else if (b.type === 'imagetext') out.push([b.title, plainOf(b.html)].filter(Boolean).join('\n'));
            else if ((b.type === 'button' || b.type === 'link') && b.text) out.push(b.text);
            else if ((b.type === 'event' || b.type === 'post') && b.title) out.push([b.title, b.when, b.location, b.desc].filter(Boolean).join('\n'));
        });
        return out.filter(Boolean).join('\n\n').slice(0, 8000);
    }
    /** Destinació: {block, col} → què se'n pot fer. */
    function aiInfo(t) {
        var b = t && block(t.block);
        if (!b) return null;
        if (t.col) return { b: b, obj: t.col, text: 'html', image: true, label: t.col === b ? 'el text del bloc «Imatge i text»' : 'una columna' };
        return { b: b, obj: b, text: AI_TEXT[b.type] || null, image: !!AI_IMAGE[b.type], label: 'el bloc «' + CFG.types[b.type] + '»' };
    }
    function setSeg(id, attr, v) { var g = $(id); if (g) g.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', x.dataset[attr] === v ? 'true' : 'false'); }); }
    function segVal(id, attr, def) { var x = $(id) && $(id).querySelector('button[aria-pressed="true"]'); return x ? x.dataset[attr] : def; }
    function openAi(mode, target) {
        var dlg = $('me-ai-dlg'); if (!dlg) return;
        aiTarget = target || (selected ? { block: selected } : null);
        var info = aiInfo(aiTarget);
        if (mode === 'text' && info && !info.text && info.image) mode = 'image';
        if (mode === 'image' && info && !info.image && info.text) mode = 'text';
        aiMode = mode; paintAi();
        var fmt = info && info.text ? (info.b.type === 'heading' ? 'heading' : (info.b.type === 'button' || info.b.type === 'link') && !info.obj.cols && info.obj === info.b ? 'button' : 'paragraphs') : 'paragraphs';
        if ($('me-ai-format')) $('me-ai-format').value = fmt;
        var cur = info && info.text ? (info.text === 'html' ? plainOf(info.obj.html) : info.obj.text) : '';
        $('me-ai-rewrite-wrap').hidden = !cur || /^\[.*\]$/.test(cur.trim());
        $('me-ai-rewrite').checked = false;
        $('me-ai-target').textContent = info ? 'Per a ' + info.label + ' seleccionat. Pots substituir-lo o afegir el resultat com a bloc nou.' : 'El resultat s\'afegirà com a bloc nou sota el bloc seleccionat.';
        aiResult = null; $('me-ai-out').hidden = true; $('me-ai-out').innerHTML = ''; paintAiActions();
        if ($('me-ai-refs')) {
            clearRefs();
            var cur = info && info.image && /^img:/.test(info.obj.src || '') ? info.obj.src : '';
            $('me-ai-usecur-wrap').hidden = !cur; $('me-ai-usecur').checked = false; $('me-ai-usecur').dataset.src = cur;
            paintRefs();
        }
        dlg.showModal();
        var ta = $(aiMode === 'text' ? 'me-ai-prompt' : 'me-ai-iprompt'); if (ta) ta.focus();
    }
    function paintAi() {
        setSeg('me-ai-mode', 'mode', aiMode);
        document.querySelectorAll('[data-ai-pane]').forEach(function (p) { p.hidden = p.dataset.aiPane !== aiMode || (aiMode === 'text' && !CFG.ai.text); });
        $('me-ai-go').hidden = aiMode === 'text' ? !CFG.ai.text : !CFG.ai.image;
    }
    function paintAiActions() {
        var info = aiInfo(aiTarget), r = aiResult;
        $('me-ai-new').hidden = !r || (r.kind === 'text' && r.format === 'subject');
        $('me-ai-replace').hidden = !r || !info || (r.kind === 'image' ? !info.image : (!info.text || r.format === 'subject'));
        if (info) $('me-ai-replace').textContent = 'Substitueix ' + (r && r.kind === 'image' ? 'la imatge' : 'el text') + ' del bloc';
        $('me-ai-subject').hidden = !r || r.kind !== 'text' || r.format !== 'subject';
        $('me-ai-go').textContent = r ? 'Torna a generar' : 'Genera';
        $('me-ai-go').className = r ? 'btn secondary' : 'btn';
    }
    if ($('me-ai')) $('me-ai').addEventListener('click', function () { openAi('text'); });
    if ($('me-ai-dlg')) {
        $('me-ai-mode').addEventListener('click', function (e) { var b = e.target.closest('button[data-mode]'); if (!b) return; aiMode = b.dataset.mode; aiResult = null; $('me-ai-out').hidden = true; paintAi(); paintAiActions(); });
        ['me-ai-provider', 'me-ai-shape', 'me-ai-style'].forEach(function (id) {
            if ($(id)) $(id).addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; $(id).querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); }); });
        });
        $('me-ai-go').addEventListener('click', generateAi);
        [$('me-ai-prompt'), $('me-ai-iprompt')].forEach(function (ta) { if (ta) ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); generateAi(); } }); });
        $('me-ai-new').addEventListener('click', function () { applyAi(false); });
        $('me-ai-replace').addEventListener('click', function () { applyAi(true); });
        $('me-ai-subject').addEventListener('click', function () {
            title = aiResult.text; $('me-title').value = title;
            if (aiResult.preheader) { preheader = aiResult.preheader; $('me-pre').value = preheader; }
            commit(true); $('me-ai-dlg').close(); toast('Assumpte i resum posats. Recorda desar.');
        });
    }
    // Imatges de referència per a la IA (es redueixen al navegador abans de pujar-les).
    var AI_MAX_REFS = CFG.ai.maxRefs || 4, aiRefs = [];
    function clearRefs() { aiRefs.forEach(function (r) { URL.revokeObjectURL(r.url); }); aiRefs = []; }
    function paintRefs() {
        var box = $('me-ai-refs'); if (!box) return;
        box.querySelectorAll('.me-ref').forEach(function (x) { x.remove(); });
        aiRefs.forEach(function (r, i) {
            var el = h('div', { 'class': 'me-ref' }, '<img src="' + r.url + '" alt="' + esc(r.name) + '"><button type="button" aria-label="Treu ' + esc(r.name) + '" title="Treu-la">✕</button>');
            el.querySelector('button').addEventListener('click', function () { URL.revokeObjectURL(r.url); aiRefs.splice(i, 1); paintRefs(); });
            box.insertBefore(el, $('me-ai-refs-add'));
        });
        $('me-ai-refs-add').hidden = aiRefs.length >= AI_MAX_REFS;
        $('me-ai-keep-wrap').hidden = !aiRefs.length && !$('me-ai-usecur').checked;
    }
    /** Redueix la imatge (costat màxim 2048 px) si és gran; les captures grans passen a JPEG. */
    function shrinkRef(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file), img = new Image();
            img.onload = function () {
                var w = img.naturalWidth, hh = img.naturalHeight, max = Math.max(w, hh);
                if (max <= 2048 && file.size <= 1.5 * 1024 * 1024 && /^image\/(png|jpeg|webp)$/.test(file.type)) { URL.revokeObjectURL(url); resolve(file); return; }
                var k = Math.min(1, 2048 / max), c = document.createElement('canvas');
                c.width = Math.round(w * k); c.height = Math.round(hh * k);
                var ctx = c.getContext('2d'); ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, c.width, c.height); ctx.drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(url);
                c.toBlob(function (b) { b ? resolve(b) : reject(new Error()); }, 'image/jpeg', 0.9);
            };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error()); };
            img.src = url;
        });
    }
    function addRefs(files) {
        Array.prototype.forEach.call(files, function (file) {
            if (!/^image\//.test(file.type)) { toast('«' + (file.name || 'arxiu') + '» no és una imatge.', true); return; }
            if (aiRefs.length >= AI_MAX_REFS) { toast('Com a màxim ' + AI_MAX_REFS + ' imatges de referència.', true); return; }
            var slot = { blob: null, url: '', name: '' }; aiRefs.push(slot);
            shrinkRef(file).then(function (blob) {
                slot.blob = blob; slot.url = URL.createObjectURL(blob);
                var base = (file.name || 'captura').replace(/\.[a-z0-9]+$/i, '') || 'captura';
                slot.name = base + (blob.type === 'image/jpeg' ? '.jpg' : blob.type === 'image/webp' ? '.webp' : '.png');
                paintRefs();
            }).catch(function () { aiRefs.splice(aiRefs.indexOf(slot), 1); toast('No s\'ha pogut llegir «' + (file.name || 'la imatge') + '».', true); paintRefs(); });
        });
    }
    if ($('me-ai-refs')) {
        $('me-ai-refs-add').addEventListener('click', function () { $('me-ai-refs-file').click(); });
        $('me-ai-refs-file').addEventListener('change', function () { addRefs(this.files); this.value = ''; });
        $('me-ai-usecur').addEventListener('change', paintRefs);
        var zone = $('me-ai-refs');
        zone.addEventListener('dragover', function (e) { if (isFileDrag(e)) { e.preventDefault(); zone.classList.add('over'); } });
        zone.addEventListener('dragleave', function () { zone.classList.remove('over'); });
        zone.addEventListener('drop', function (e) { e.preventDefault(); zone.classList.remove('over'); if (e.dataTransfer.files.length) addRefs(e.dataTransfer.files); });
        // Enganxar una captura (Ctrl+V) amb el quadre obert: passa a «Imatge» i l'afegeix.
        $('me-ai-dlg').addEventListener('paste', function (e) {
            var files = Array.prototype.filter.call((e.clipboardData && e.clipboardData.files) || [], function (f) { return /^image\//.test(f.type); });
            if (!files.length || !CFG.ai.image) return;
            e.preventDefault();
            if (aiMode !== 'image') { aiMode = 'image'; aiResult = null; $('me-ai-out').hidden = true; paintAi(); paintAiActions(); }
            addRefs(files);
        });
    }

    function generateAi() {
        var out = $('me-ai-out'), go = $('me-ai-go'), info = aiInfo(aiTarget), req;
        if (aiMode === 'text') {
            var prompt = $('me-ai-prompt').value.trim();
            if (!prompt) { $('me-ai-prompt').focus(); toast('Escriu què vols que escrigui la IA.', true); return; }
            var current = $('me-ai-rewrite').checked && info ? (info.text === 'html' ? plainOf(info.obj.html) : info.obj.text) : '';
            req = { url: CFG.urls.aiText, body: { provider: segVal('me-ai-provider', 'provider', CFG.ai.provider), prompt: prompt, format: $('me-ai-format').value, tone: $('me-ai-tone').value, length: $('me-ai-length').value, title: title, lang: lang, context: $('me-ai-context').checked ? mailText() : '', current: current } };
        } else {
            var ip = $('me-ai-iprompt').value.trim();
            if (!ip) { $('me-ai-iprompt').focus(); toast('Descriu la imatge que vols.', true); return; }
            var fd = new FormData();
            fd.append('prompt', ip); fd.append('shape', segVal('me-ai-shape', 'v', 'landscape')); fd.append('style', segVal('me-ai-style', 'v', 'photo'));
            fd.append('no_text', $('me-ai-notext').checked ? '1' : '0');
            if (aiRefs.some(function (r) { return !r.blob; })) { toast('Un moment: encara s\'estan preparant les imatges de referència.'); return; }
            var nRefs = aiRefs.length + ($('me-ai-usecur').checked ? 1 : 0);
            if (nRefs > AI_MAX_REFS) { toast('Com a màxim ' + AI_MAX_REFS + ' imatges de referència.', true); return; }
            fd.append('keep_details', nRefs && $('me-ai-keep').checked ? '1' : '0');
            aiRefs.forEach(function (r) { fd.append('refs[]', r.blob, r.name); });
            if ($('me-ai-usecur').checked && $('me-ai-usecur').dataset.src) fd.append('ref_srcs[]', $('me-ai-usecur').dataset.src);
            req = { url: CFG.urls.aiImage, form: fd, body: { prompt: ip }, refs: nRefs };
        }
        go.disabled = true;
        out.hidden = false; out.innerHTML = '<div class="me-ai-busy">' + (aiMode === 'text' ? 'Escrivint…' : 'Creant la imatge' + (req.refs ? ' a partir de ' + (req.refs === 1 ? 'la referència' : 'les ' + req.refs + ' referències') : '') + '… pot trigar fins a un minut.') + '</div>';
        var mode = aiMode;
        api(req.url, req.form ? { method: 'POST', body: req.form } : { method: 'POST', json: req.body }).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: 'Resposta no vàlida del servidor (error ' + r.status + ').' }; }).then(function (j) {
                if (!r.ok || !j.ok) throw new Error(j.message || (j.errors ? j.errors[Object.keys(j.errors)[0]][0] : 'No s\'ha pogut generar.'));
                return j;
            });
        }).then(function (j) {
            if (mode === 'text') {
                aiResult = { kind: 'text', format: req.body.format, html: j.html, text: j.text, preheader: j.preheader || '' };
                out.innerHTML = j.html ? '<div class="rt">' + j.html + '</div>' : '<div class="rt"><b>' + esc(j.text) + '</b>' + (j.preheader ? '<br><span style="color:#6b7280">' + esc(j.preheader) + '</span>' : '') + '</div>';
            } else {
                CFG.images[j.src] = j.url;
                aiResult = { kind: 'image', src: j.src, alt: req.body.prompt.slice(0, 150) };
                out.innerHTML = '<img src="' + esc(j.url) + '" alt="' + esc(aiResult.alt) + '">';
            }
            paintAiActions();
        }).catch(function (e) {
            out.innerHTML = '<div style="color:#b42318;font-size:13.5px;">' + esc(e.message || 'No s\'ha pogut generar.') + '</div>';
        }).finally(function () { go.disabled = false; });
    }
    function applyAi(replace) {
        var r = aiResult, info = aiInfo(aiTarget); if (!r) return;
        commit(true);
        if (replace && info) {
            if (r.kind === 'image') { info.obj.src = r.src; info.obj.alt = info.obj.alt || r.alt; }
            else if (info.text === 'html') { info.obj.html = r.html || '<p>' + esc(r.text) + '</p>'; }
            else { info.obj.text = r.text || plainOf(r.html).split('\n')[0]; }
            selected = info.b.id;
        } else {
            var nb;
            if (r.kind === 'image') { nb = newBlock('image'); nb.src = r.src; nb.alt = r.alt; }
            else if (r.format === 'heading') { nb = newBlock('heading'); nb.text = r.text; }
            else if (r.format === 'button') { nb = newBlock('button'); nb.text = r.text; }
            else { nb = newBlock('text'); nb.html = r.html || '<p>' + esc(r.text) + '</p>'; }
            var at = aiTarget && find(aiTarget.block) >= 0 ? find(aiTarget.block) + 1 : defaultIndex();
            doc.blocks.splice(at, 0, nb); selected = nb.id;
        }
        renderAll(); renderProps(); commit(true);
        $('me-ai-dlg').close();
        var el = mailEl.querySelector('.me-b[data-id="' + selected + '"]'); if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        toast(replace ? 'Fet. Revisa-ho i desa quan vulguis (es pot desfer amb Ctrl+Z).' : 'Afegit al correu. Revisa-ho i desa quan vulguis.');
    }

    // ------------------------------------------------------------ camps del contacte ({{nom}}…)
    var mergeEl = $('me-merge');
    Object.keys(CFG.merge || {}).forEach(function (k) {
        var b = h('button', { type: 'button', role: 'menuitem' }, esc(CFG.merge[k]) + ' <code>{{' + esc(k) + '}}</code>');
        b.addEventListener('mousedown', function (e) { e.preventDefault(); });
        b.addEventListener('click', function () {
            if (!editing) return;
            var tag = '{{' + k + '}}';
            if (k === 'unsubscribe_url' || k === 'view_url') {
                document.execCommand('createLink', false, tag);
            } else {
                document.execCommand('insertText', false, tag);
            }
            editing.dispatchEvent(new Event('input', { bubbles: true }));
            mergeEl.style.display = 'none';
        });
        mergeEl.appendChild(b);
    });
    function toggleMerge(btn) {
        if (mergeEl.style.display === 'flex') { mergeEl.style.display = 'none'; return; }
        var r = btn.getBoundingClientRect();
        mergeEl.style.display = 'flex';
        mergeEl.style.top = (window.scrollY + r.bottom + 6) + 'px';
        mergeEl.style.left = Math.max(8, Math.min(window.scrollX + r.left, window.scrollX + document.documentElement.clientWidth - mergeEl.offsetWidth - 8)) + 'px';
    }

    // ------------------------------------------------------------ idiomes i traducció automàtica
    function renderLangs() {
        var box = $('me-langs'); if (!box) return;
        var codes = Object.keys(envelope.langs);
        box.innerHTML = '';
        box.hidden = codes.length < 2;
        codes.forEach(function (c) {
            var b = h('button', { type: 'button', 'aria-pressed': c === lang ? 'true' : 'false', title: CFG.langs[c] + (c === envelope.primary ? ' (idioma principal)' : '') }, esc(c.toUpperCase()) + (c === envelope.primary ? ' ★' : ''));
            b.addEventListener('click', function () { if (c !== lang) switchLang(c); });
            box.appendChild(b);
        });
        var list = $('me-lang-add-list'); list.innerHTML = '';
        Object.keys(CFG.langs).forEach(function (c) {
            if (envelope.langs[c]) return;
            if (CFG.ai.text) {
                var t = h('button', { type: 'button' }, 'Tradueix al ' + esc(CFG.langs[c]) + ' amb IA<small>Fa una còpia traduïda de l\'idioma que edites</small>');
                t.addEventListener('click', function () { $('me-lang-menu').removeAttribute('open'); addLang(c, true); });
                list.appendChild(t);
            }
            var m = h('button', { type: 'button' }, 'Afegeix ' + esc(CFG.langs[c]) + '<small>Una còpia per traduir-la a mà</small>');
            m.addEventListener('click', function () { $('me-lang-menu').removeAttribute('open'); addLang(c, false); });
            list.appendChild(m);
        });
        var sel = $('me-lang-select');
        if (sel) {
            sel.innerHTML = '';
            Object.keys(CFG.langs).forEach(function (c) {
                var o = document.createElement('option'); o.value = c; o.textContent = CFG.langs[c];
                // Un idioma que ja té una altra versió no es pot triar: canvia de versió amb les pestanyes.
                if (envelope.langs[c] && c !== lang) { o.disabled = true; o.textContent += ' (ja hi és)'; }
                if (c === lang) o.selected = true;
                sel.appendChild(o);
            });
        }
        var other = lang !== envelope.primary;
        $('me-lang-primary').hidden = !other;
        $('me-lang-remove').hidden = !other;
        $('me-lang-retranslate').hidden = !other || !CFG.ai.text;
    }
    function switchLang(c) {
        stash(); load(c); selected = null;
        $('me-title').value = title; $('me-pre').value = preheader;
        renderAll(); renderProps(); renderLangs();
        if (!$('me-styles').hidden) renderStyles();
    }
    function translateTo(from, to) {
        var src = envelope.langs[from];
        toast('Traduint al ' + CFG.langs[to] + '… pot trigar una mica.');
        return api(CFG.urls.translate, { method: 'POST', json: { from: from, to: to, subject: src.subject, preheader: src.preheader, doc: src.doc } }).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: 'Resposta no vàlida del servidor (error ' + r.status + ').' }; }).then(function (j) {
                if (!r.ok || !j.ok) throw new Error(j.message || 'No s\'ha pogut traduir.');
                return j;
            });
        });
    }
    function addLang(c, ai) {
        commit(true);
        if (!ai) {
            envelope.langs[c] = clone(envelope.langs[lang]);
            switchLang(c); commit(true);
            toast('Idioma afegit: ' + CFG.langs[c] + '. Tradueix-ne els textos.');
            return;
        }
        translateTo(lang, c).then(function (j) {
            envelope.langs[c] = { subject: j.subject, preheader: j.preheader, doc: j.doc };
            switchLang(c); commit(true);
            toast('Traduït al ' + CFG.langs[c] + '. Revisa\'l i desa.');
        }).catch(function (e) { toast(e.message, true); });
    }
    // Canvia l'idioma en què està escrita aquesta versió (no la tradueix: per traduir, «Tradueix al…»).
    $('me-lang-select').addEventListener('change', function () {
        var to = this.value, from = lang;
        if (to === from || envelope.langs[to]) { renderLangs(); return; }
        commit(true);
        envelope.langs[to] = envelope.langs[from];
        delete envelope.langs[from];
        if (envelope.primary === from) envelope.primary = to;
        load(to);
        renderAll(); renderProps(); renderLangs(); commit(true);
        toast('Ara aquest correu consta escrit en ' + CFG.langs[to] + '. El text no s\'ha traduït: per fer-ho, fes servir «Tradueix al…».');
    });
    $('me-lang-retranslate').addEventListener('click', function () {
        $('me-lang-menu').removeAttribute('open');
        if (!confirm('Tornar a traduir el ' + CFG.langs[lang] + ' a partir del ' + CFG.langs[envelope.primary] + '? Es substituiran els textos d\'aquest idioma (es pot desfer).')) return;
        var target = lang;
        commit(true);
        translateTo(envelope.primary, target).then(function (j) {
            envelope.langs[target] = { subject: j.subject, preheader: j.preheader, doc: j.doc };
            if (lang === target) { load(target); $('me-title').value = title; $('me-pre').value = preheader; selected = null; renderAll(); renderProps(); }
            commit(true); toast('Traducció actualitzada. Revisa-la i desa.');
        }).catch(function (e) { toast(e.message, true); });
    });
    $('me-lang-primary').addEventListener('click', function () {
        $('me-lang-menu').removeAttribute('open');
        commit(true); envelope.primary = lang; renderLangs(); commit(true);
        toast(CFG.langs[lang] + ' és ara l\'idioma principal.');
    });
    $('me-lang-remove').addEventListener('click', function () {
        $('me-lang-menu').removeAttribute('open');
        if (!confirm('Treure el ' + CFG.langs[lang] + ' d\'aquest correu?')) return;
        commit(true);
        var del = lang;
        load(envelope.primary);
        delete envelope.langs[del];
        $('me-title').value = title; $('me-pre').value = preheader; selected = null;
        renderAll(); renderProps(); renderLangs(); commit(true);
        toast('Idioma tret. Es pot desfer amb Ctrl+Z.');
    });

    // ------------------------------------------------------------ inici
    $('me-title').value = title; $('me-pre').value = preheader;
    renderAll(); renderProps(); renderLangs(); updateButtons();
})();
