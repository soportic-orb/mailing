/*
 * Editor visual del flux d'una automatització.
 * Port sense dependències del FlowBuilder del Panell de Torns
 * (panel/src/components/automations/FlowBuilder.tsx): arbre vertical que es
 * col·loca sol, botó ＋ a cada connector, condicions amb branques Sí / No i
 * panell lateral de configuració.
 */
(function () {
    'use strict';
    var CFG = window.FLOW_CFG;
    var root = document.getElementById('flow-app');
    if (!CFG || !root) return;

    var W = 264, H = 76, GAP_Y = 150, BRANCH_X = 300, PAD = 60;
    var COLORS = { trigger: '#16a34a', email: '#7c3aed', delay: '#2563eb', condition: '#d97706', action: '#0d9488' };
    var TITLES = { trigger: 'Disparador', email: 'Correu', delay: 'Espera', condition: 'Condició', action: 'Acció' };
    var ICONS = {
        trigger: '<path d="M13 3l0 7l6 0l-8 11l0 -7l-6 0l8 -11"/>',
        email: '<path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10z"/><path d="M3 7l9 6l9 -6"/>',
        delay: '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0"/><path d="M12 7v5l3 3"/>',
        condition: '<path d="M4 4h16v2.172a2 2 0 0 1 -.586 1.414l-4.414 4.414v7l-6 2v-8.5l-4.48 -4.928a2 2 0 0 1 -.52 -1.345v-2.227z"/>',
        action: '<path d="M4 6l8 0"/><path d="M16 6l4 0"/><path d="M14 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"/><path d="M4 12l2 0"/><path d="M10 12l10 0"/><path d="M8 12m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"/><path d="M4 18l11 0"/><path d="M19 18l1 0"/><path d="M17 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"/>'
    };
    var ADD = [['email', 'Correu', 'Envia un correu'], ['delay', 'Espera', 'Hores, dies o setmanes'], ['condition', 'Condició', 'Filtra o obre dues branques'], ['action', 'Acció', 'Llista o camp del contacte']];

    var flow = CFG.flow, campaigns = CFG.campaigns || {}, stats = CFG.stats || {};
    var editable = !!CFG.editable, dirty = false, selected = null, path = {}, view = { x: 0, y: 0, z: 1 }, fitted = false;

    // ---------------------------------------------------------------- helpers
    function h(tag, attrs, kids) {
        var el = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'class') el.className = v;
            else if (k === 'text') el.textContent = v;
            else if (k === 'html') el.innerHTML = v;
            else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
            else if (k === 'value') el.value = v;
            else el.setAttribute(k, v === true ? '' : v);
        });
        (kids || []).forEach(function (c) { if (c) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return el;
    }
    function svg(name, size) {
        return '<svg viewBox="0 0 24 24" width="' + (size || 18) + '" height="' + (size || 18) + '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[name] + '</svg>';
    }
    function n(v) { return (v || 0).toLocaleString('ca-ES'); }
    function pct(a, b) { return b ? Math.round(a * 1000 / b) / 10 + '%' : '—'; }
    function byId(id) { for (var i = 0; i < flow.steps.length; i++) if (flow.steps[i].id === id) return flow.steps[i]; return null; }
    function name(list, id) { for (var i = 0; i < list.length; i++) if (+list[i].id === +id) return list[i].name; return ''; }
    function fieldLabel(key) { for (var i = 0; i < CFG.fields.length; i++) if (CFG.fields[i].key === key) return CFG.fields[i].label; return key || '—'; }
    function newId(type) { var id; do { id = type + '-' + Math.random().toString(36).slice(2, 8); } while (byId(id)); return id; }
    function branched(s) { return s && s.type === 'condition' && !!s.config.branched; }
    function children(id) { return flow.edges.filter(function (e) { return e.from === id; }); }
    function child(id, branch) { var e = flow.edges.filter(function (x) { return x.from === id && (x.branch || '') === (branch || ''); })[0]; return e ? e.to : null; }
    function parentEdge(id) { return flow.edges.filter(function (e) { return e.to === id; })[0] || null; }
    function trigger() { return flow.steps.filter(function (s) { return s.type === 'trigger'; })[0]; }
    function ancestors(id) { var out = [], e; while ((e = parentEdge(id))) { id = e.from; out.push(id); } return out; }
    function subtree(id) { var out = [id]; children(id).forEach(function (e) { out = out.concat(subtree(e.to)); }); return out; }

    function defaults(type) {
        switch (type) {
            case 'email': return { campaign_id: 0, name: '', template_id: 0, lang: CFG.lang };
            case 'delay': return { amount: 1, unit: 'days', at: '' };
            case 'condition': return { kind: 'opened', op: 'yes', step: '', field: '', value: '', list_id: 0, segment_id: 0, branched: true };
            case 'action': return { kind: 'list_add', list_id: 0, field: '', value: '' };
        }
        return {};
    }

    function summary(s) {
        var c = s.config;
        switch (s.type) {
            case 'trigger':
                var t = CFG.triggers[c.kind] ? CFG.triggers[c.kind].label : '';
                if (c.kind === 'list') return t + ': ' + (name(CFG.lists, c.list_id) || 'tria la llista');
                if (c.kind === 'form') return t + ': ' + (name(CFG.forms, c.form_id) || 'tria el formulari');
                if (c.kind === 'event') return t + ': ' + (c.event || 'posa-li nom');
                if (c.kind === 'date') return (c.field ? fieldLabel(c.field) : 'tria el camp') + (c.offset ? ' (' + Math.abs(c.offset) + ' dies ' + (c.offset < 0 ? 'abans' : 'després') + ')' : '') + (c.repeat === 'yearly' ? ' · cada any' : '');
                return t;
            case 'email':
                var camp = campaigns[c.campaign_id], subj = '';
                if (camp) { var l = camp.langs[CFG.lang] || camp.langs[Object.keys(camp.langs)[0]]; subj = l && l.subject; }
                return (c.name || 'Sense nom') + (subj ? ' · «' + subj + '»' : camp ? ' · sense assumpte' : ' · nou');
            case 'delay':
                return c.amount + ' ' + CFG.units[c.unit] + (c.at ? ', fins a les ' + c.at : '');
            case 'condition':
                if (c.kind === 'opened' || c.kind === 'clicked') {
                    var em = byId(c.step);
                    return (c.op === 'no' ? 'No ha ' : 'Ha ') + (c.kind === 'opened' ? 'obert' : 'clicat') + ' «' + (em ? em.config.name || 'correu' : '?') + '»';
                }
                if (c.kind === 'list') return (c.op === 'not_in' ? 'No és a ' : 'És a ') + '«' + (name(CFG.lists, c.list_id) || '?') + '»';
                if (c.kind === 'segment') return (c.op === 'not_in' ? 'No és al segment ' : 'És al segment ') + '«' + (name(CFG.segments, c.segment_id) || '?') + '»';
                return fieldLabel(c.field) + ' ' + (CFG.fieldOps[c.op] || '') + (c.op === 'empty' || c.op === 'not_empty' ? '' : ' «' + c.value + '»');
            case 'action':
                if (c.kind === 'set_field') return fieldLabel(c.field) + ' = «' + c.value + '»';
                return (c.kind === 'list_add' ? 'Afegeix a ' : 'Treu de ') + '«' + (name(CFG.lists, c.list_id) || '?') + '»';
        }
        return '';
    }

    function statLine(s) {
        var st = stats[s.id] || {};
        switch (s.type) {
            case 'trigger': return st.enter ? n(st.enter) + ' inscrits' : '';
            case 'email':
                if (!st.sent && !st.queued) return '';
                return n(st.sent) + ' enviats · ' + pct(st.opened, st.sent) + ' oberts · ' + pct(st.clicked, st.sent) + ' clics' + (st.queued ? ' · ' + n(st.queued) + ' a la cua' : '');
            case 'delay': return st.here ? n(st.here) + ' esperant' : (st.wait ? n(st.wait) + ' hi han passat' : '');
            case 'condition': return (st.yes || st.no) ? 'Sí ' + n(st.yes) + ' · No ' + n(st.no) : '';
            case 'action': return st.action ? n(st.action) + ' fetes' : '';
        }
        return '';
    }

    // ---------------------------------------------------------------- edits
    function change() {
        dirty = true;
        path = {};
        render();
    }
    function insertAfter(fromId, branch, type) {
        var s = { id: newId(type), type: type, config: defaults(type) };
        var old = flow.edges.filter(function (e) { return e.from === fromId && (e.branch || '') === (branch || ''); })[0];
        flow.steps.push(s);
        if (old) {
            flow.edges.splice(flow.edges.indexOf(old), 1);
            flow.edges.push(edge(fromId, s.id, branch));
            flow.edges.push(edge(s.id, old.to, branched(s) ? 'yes' : ''));
        } else {
            flow.edges.push(edge(fromId, s.id, branch));
        }
        if (type === 'condition') {
            var emails = ancestors(s.id).map(byId).filter(function (x) { return x && x.type === 'email'; });
            if (emails.length) s.config.step = emails[0].id; else { s.config.kind = 'list'; s.config.op = 'in'; }
        }
        selected = s.id;
        change();
        renderPanel();
    }
    function edge(from, to, branch) { return branch ? { from: from, to: to, branch: branch } : { from: from, to: to }; }
    function removeStep(id) {
        var s = byId(id);
        if (!s || s.type === 'trigger') return;
        var inc = parentEdge(id), keep = null, drop = [id];
        if (branched(s)) {
            var no = child(id, 'no');
            keep = child(id, 'yes');
            if (no) drop = drop.concat(subtree(no));
            if (no && !confirm('S\'eliminaran també els passos de la branca «No». La branca «Sí» es manté.')) return;
        } else {
            keep = child(id, '');
        }
        flow.steps = flow.steps.filter(function (x) { return drop.indexOf(x.id) < 0; });
        flow.edges = flow.edges.filter(function (e) { return drop.indexOf(e.from) < 0 && drop.indexOf(e.to) < 0; });
        flow.edges = flow.edges.filter(function (e) { return !(keep && e.to === keep); });
        if (inc && keep) flow.edges.push(edge(inc.from, keep, inc.branch));
        if (drop.indexOf(selected) >= 0) selected = null;
        change();
        renderPanel();
    }
    function setBranched(s, on) {
        if (!!s.config.branched === on) return;
        if (on) {
            flow.edges.forEach(function (e) { if (e.from === s.id) e.branch = 'yes'; });
        } else {
            var no = child(s.id, 'no');
            if (no) {
                if (!confirm('Sense branques, s\'eliminen els passos de la branca «No».')) { renderPanel(); return; }
                var drop = subtree(no);
                flow.steps = flow.steps.filter(function (x) { return drop.indexOf(x.id) < 0; });
                flow.edges = flow.edges.filter(function (e) { return drop.indexOf(e.from) < 0 && drop.indexOf(e.to) < 0; });
            }
            flow.edges.forEach(function (e) { if (e.from === s.id) delete e.branch; });
        }
        s.config.branched = on;
        change();
        renderPanel();
    }

    // ---------------------------------------------------------------- layout
    function width(id) {
        var s = byId(id);
        if (!s) return 1;
        if (branched(s)) {
            var y = child(id, 'yes'), no = child(id, 'no');
            return (y ? width(y) : 1) + (no ? width(no) : 1);
        }
        var c = child(id, '');
        return c ? width(c) : 1;
    }
    function layout() {
        var pos = {}, ghosts = [];
        function place(id, x, y) {
            pos[id] = { x: x, y: y };
            var s = byId(id);
            if (branched(s)) {
                var yes = child(id, 'yes'), no = child(id, 'no');
                var yw = yes ? width(yes) : 1, nw = no ? width(no) : 1, tw = yw + nw;
                var yx = x - tw * BRANCH_X / 2 + yw * BRANCH_X / 2, nx = x + tw * BRANCH_X / 2 - nw * BRANCH_X / 2;
                if (yes) place(yes, yx, y + GAP_Y); else ghosts.push({ from: id, branch: 'yes', x: yx, y: y + GAP_Y });
                if (no) place(no, nx, y + GAP_Y); else ghosts.push({ from: id, branch: 'no', x: nx, y: y + GAP_Y });
            } else {
                var c = child(id, '');
                if (c) place(c, x, y + GAP_Y); else ghosts.push({ from: id, branch: '', x: x, y: y + GAP_Y, leaf: true });
            }
        }
        place(trigger().id, 0, 0);
        var xs = Object.keys(pos).map(function (k) { return pos[k].x; }).concat(ghosts.map(function (g) { return g.x; }));
        var minX = Math.min.apply(null, xs) - W / 2 - PAD, maxX = Math.max.apply(null, xs) + W / 2 + PAD;
        var maxY = Math.max.apply(null, Object.keys(pos).map(function (k) { return pos[k].y; }).concat(ghosts.map(function (g) { return g.y; })));
        Object.keys(pos).forEach(function (k) { pos[k].x -= minX; pos[k].y += PAD; });
        ghosts.forEach(function (g) { g.x -= minX; g.y += PAD; });
        return { pos: pos, ghosts: ghosts, w: maxX - minX, h: maxY + H + PAD * 2 };
    }

    // ---------------------------------------------------------------- render
    var toolbar, canvas, world, panel, saveBtn, stateEl;
    function build() {
        saveBtn = h('button', { class: 'btn sm', type: 'button', disabled: true, onclick: function () { save(); } }, ['Desa el flux']);
        stateEl = h('span', { class: 'small muted' });
        toolbar = h('div', { class: 'fl-toolbar' }, [
            editable ? saveBtn : null, stateEl, h('span', { class: 'fl-sp' }),
            h('button', { class: 'btn sm secondary', type: 'button', title: 'Allunya', onclick: function () { zoom(-0.15); } }, ['−']),
            h('button', { class: 'btn sm secondary', type: 'button', title: 'Apropa', onclick: function () { zoom(0.15); } }, ['+']),
            h('button', { class: 'btn sm secondary', type: 'button', onclick: function () { fit(); } }, ['Encaixa']),
            h('button', { class: 'btn sm secondary', type: 'button', onclick: function () { selected = null; render(); renderPanel(); var i = panel.querySelector('#fl-sim-email'); if (i) i.focus(); } }, ['Prova amb un contacte'])
        ]);
        world = h('div', { class: 'fl-world' });
        canvas = h('div', { class: 'fl-canvas' }, [world]);
        panel = h('aside', { class: 'fl-panel' });
        root.appendChild(h('div', { class: 'fl-main' }, [toolbar, canvas]));
        root.appendChild(panel);
        pan();
        window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
        var act = document.getElementById('activate-form');
        if (act) act.addEventListener('submit', function (e) {
            if (!dirty) return;
            e.preventDefault();
            save().then(function (ok) { if (ok) act.submit(); });
        });
        document.addEventListener('click', function (e) { if (!e.target.closest('.fl-menu') && !e.target.closest('.fl-add')) closeMenu(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
    }

    function render() {
        var L = layout();
        world.innerHTML = '';
        world.style.width = L.w + 'px';
        world.style.height = L.h + 'px';
        var paths = '', labels = [];
        flow.edges.forEach(function (e) {
            var a = L.pos[e.from], b = L.pos[e.to];
            if (!a || !b) return;
            paths += line(a, b, false, path[e.from] && path[e.to]);
            if (e.branch) labels.push(branchLabel(a, b, e.branch));
            if (editable) labels.push(addBtn(b.x, midPlus(a, b), e.from, e.branch || ''));
        });
        L.ghosts.forEach(function (g) {
            var a = L.pos[g.from];
            var end = { x: g.x, y: g.leaf ? a.y + H + 46 : g.y };
            paths += line(a, end, true);
            if (g.branch) labels.push(branchLabel(a, end, g.branch));
            if (editable) labels.push(addBtn(end.x, end.y + (g.leaf ? 0 : 14), g.from, g.branch, true));
            else if (!g.leaf) labels.push(h('div', { class: 'fl-end', style: 'left:' + end.x + 'px;top:' + (end.y + 4) + 'px', text: 'Fi' }));
        });
        world.appendChild(h('div', { html: '<svg class="fl-edges" width="' + L.w + '" height="' + L.h + '">' + paths + '</svg>' }).firstChild);
        flow.steps.forEach(function (s) {
            var p = L.pos[s.id];
            if (!p) return;
            var st = statLine(s);
            var node = h('div', { class: 'fl-node t-' + s.type + (s.id === selected ? ' sel' : '') + (path[s.id] ? ' on-path' : ''), style: 'left:' + (p.x - W / 2) + 'px;top:' + p.y + 'px;--c:' + COLORS[s.type], tabindex: '0', role: 'button',
                onclick: function () { selected = s.id; render(); renderPanel(); }, onkeydown: function (e) { if (e.key === 'Enter') { selected = s.id; render(); renderPanel(); } } }, [
                h('span', { class: 'fl-ic', html: svg(s.type) }),
                h('span', { class: 'fl-tx' }, [h('b', { text: s.type === 'condition' && s.config.branched ? 'Condició amb branques' : TITLES[s.type] }), h('span', { text: summary(s) }), st ? h('em', { text: st }) : null]),
                editable && s.type !== 'trigger' ? h('button', { class: 'fl-del', type: 'button', title: 'Elimina el pas', 'aria-label': 'Elimina el pas', onclick: function (e) { e.stopPropagation(); removeStep(s.id); } }, ['×']) : null
            ]);
            world.appendChild(node);
        });
        labels.forEach(function (l) { world.appendChild(l); });
        saveBtn.disabled = !dirty;
        stateEl.textContent = dirty ? 'Canvis sense desar' : '';
        stateEl.className = 'small ' + (dirty ? 'fl-dirty' : 'muted');
        if (!fitted) { fitted = true; requestAnimationFrame(fit); }
        apply();
    }
    function midPlus(a, b) { var top = a.y + H, mid = top + (b.y - top) / 2; return a.x === b.x ? mid : (mid + b.y) / 2; }
    function line(a, b, dashed, on) {
        var top = a.y + H, mid = top + (b.y - top) / 2, d;
        if (a.x === b.x) d = 'M' + a.x + ' ' + top + ' V' + b.y;
        else { var r = 10, dir = b.x > a.x ? 1 : -1; d = 'M' + a.x + ' ' + top + ' V' + (mid - r) + ' Q' + a.x + ' ' + mid + ' ' + (a.x + dir * r) + ' ' + mid + ' H' + (b.x - dir * r) + ' Q' + b.x + ' ' + mid + ' ' + b.x + ' ' + (mid + r) + ' V' + b.y; }
        return '<path d="' + d + '" class="' + (dashed ? 'dash' : '') + (on ? ' on' : '') + '"/>';
    }
    function branchLabel(a, b, branch) {
        var top = a.y + H, mid = top + (b.y - top) / 2, x = b.x + (b.x < a.x ? 26 : -26);
        return h('span', { class: 'fl-branch ' + branch, style: 'left:' + x + 'px;top:' + mid + 'px', text: branch === 'yes' ? 'Sí' : 'No' });
    }
    function addBtn(x, y, from, branch, big) {
        return h('button', { class: 'fl-add' + (big ? ' big' : ''), type: 'button', style: 'left:' + x + 'px;top:' + y + 'px', title: 'Afegeix un pas', 'aria-label': 'Afegeix un pas',
            onclick: function (e) { e.stopPropagation(); openMenu(e.currentTarget, from, branch); } }, ['+']);
    }

    var menu = null;
    function closeMenu() { if (menu) { menu.remove(); menu = null; } }
    function openMenu(btn, from, branch) {
        closeMenu();
        var r = btn.getBoundingClientRect();
        menu = h('div', { class: 'fl-menu', style: 'left:' + Math.min(window.innerWidth - 230, r.right + 8) + 'px;top:' + Math.max(8, r.top - 8) + 'px' },
            ADD.map(function (o) {
                return h('button', { type: 'button', style: '--c:' + COLORS[o[0]], onclick: function () { closeMenu(); insertAfter(from, branch, o[0]); } },
                    [h('span', { class: 'fl-ic sm', html: svg(o[0], 15) }), h('span', {}, [h('b', { text: o[1] }), h('small', { text: o[2] })])]);
            }));
        document.body.appendChild(menu);
    }

    // ---------------------------------------------------------------- pan & zoom
    function apply() { world.style.transform = 'translate(' + view.x + 'px,' + view.y + 'px) scale(' + view.z + ')'; }
    function zoom(d, cx, cy) {
        var z = Math.max(0.35, Math.min(1.5, view.z + d));
        var rect = canvas.getBoundingClientRect();
        cx = cx === undefined ? rect.width / 2 : cx;
        cy = cy === undefined ? rect.height / 2 : cy;
        view.x = cx - (cx - view.x) * z / view.z;
        view.y = cy - (cy - view.y) * z / view.z;
        view.z = z;
        apply();
    }
    function fit() {
        var rect = canvas.getBoundingClientRect(), w = parseFloat(world.style.width), ht = parseFloat(world.style.height);
        if (!rect.width) return;
        // Fit the width (readable nodes); a tall flow is scrolled, not shrunk.
        view.z = Math.max(0.6, Math.min(1, (rect.width - 20) / w, rect.height / ht < 0.8 ? 1 : rect.height / ht));
        view.x = (rect.width - w * view.z) / 2;
        view.y = ht * view.z < rect.height ? (rect.height - ht * view.z) / 2 * 0.4 : -PAD * view.z + 28;
        apply();
    }
    function pan() {
        var drag = null;
        canvas.addEventListener('pointerdown', function (e) {
            if (e.target.closest('.fl-node, .fl-add, button')) return;
            drag = { x: e.clientX, y: e.clientY, vx: view.x, vy: view.y };
            canvas.setPointerCapture(e.pointerId);
            canvas.classList.add('grab');
        });
        canvas.addEventListener('pointermove', function (e) {
            if (!drag) return;
            view.x = drag.vx + e.clientX - drag.x;
            view.y = drag.vy + e.clientY - drag.y;
            apply();
        });
        ['pointerup', 'pointercancel'].forEach(function (t) { canvas.addEventListener(t, function () { drag = null; canvas.classList.remove('grab'); }); });
        canvas.addEventListener('wheel', function (e) {
            e.preventDefault();
            var rect = canvas.getBoundingClientRect();
            if (e.ctrlKey || e.metaKey) zoom(-e.deltaY * 0.01, e.clientX - rect.left, e.clientY - rect.top);
            else { view.x -= e.deltaX; view.y -= e.deltaY; apply(); }
        }, { passive: false });
    }

    // ---------------------------------------------------------------- panel
    function field(label, input, help) {
        return h('label', { class: 'field' }, [h('span', { text: label }), input, help ? h('small', { html: help }) : null]);
    }
    function select(value, options, onchange, placeholder) {
        var el = h('select', { class: 'input', disabled: !editable, onchange: function () { onchange(el.value); } },
            (placeholder ? [h('option', { value: '', text: placeholder })] : []).concat(options.map(function (o) {
                var opt = h('option', { value: String(o[0]), text: o[1] });
                if (String(o[0]) === String(value)) opt.selected = true;
                return opt;
            })));
        return el;
    }
    function text(value, oninput, attrs) {
        var el = h('input', Object.assign({ class: 'input', value: value == null ? '' : String(value), disabled: !editable, oninput: function () { oninput(el.value); } }, attrs || {}));
        return el;
    }
    function opts(list) { return list.map(function (x) { return [x.id, x.name]; }); }
    function set(s, key, v, rebuild) {
        s.config[key] = v;
        change();
        if (rebuild) renderPanel();
    }

    function renderPanel() {
        panel.innerHTML = '';
        var s = selected ? byId(selected) : null;
        if (!s) return renderIntro();
        var c = s.config, body = [];
        panel.appendChild(h('div', { class: 'fl-ph', style: '--c:' + COLORS[s.type] }, [h('span', { class: 'fl-ic', html: svg(s.type) }), h('b', { text: TITLES[s.type] }),
            h('button', { class: 'btn sm ghost', type: 'button', onclick: function () { selected = null; render(); renderPanel(); } }, ['Tanca'])]));
        if (s.type === 'trigger') {
            body.push(field('Quan comença', select(c.kind, Object.keys(CFG.triggers).map(function (k) { return [k, CFG.triggers[k].label]; }), function (v) { set(s, 'kind', v, true); }), CFG.triggers[c.kind].help));
            if (c.kind === 'list') body.push(field('Llista', select(c.list_id || '', opts(CFG.lists), function (v) { set(s, 'list_id', +v); }, '— Tria una llista —'), 'Entren els que s\'hi apunten des que l\'actives. Per fer entrar els que ja hi són, fes servir «Inscriu contactes» a Configuració.'));
            if (c.kind === 'form') body.push(field('Formulari', select(c.form_id || '', opts(CFG.forms), function (v) { set(s, 'form_id', +v); }, '— Tria un formulari —')));
            if (c.kind === 'event') {
                body.push(field('Nom de l\'esdeveniment', text(c.event, function (v) { set(s, 'event', v.toLowerCase().replace(/[^a-z0-9_.:-]/g, '')); }, { placeholder: 'registre', maxlength: 60 }),
                    'L\'altra plataforma crida <code>POST ' + CFG.apiUrl + '</code> amb <code>{"event": "' + (c.event || 'registre') + '", "email": "…"}</code> i la clau d\'una connexió API. Exemple complet a Configuració.'));
            }
            if (c.kind === 'date') {
                var dates = CFG.fields.filter(function (f) { return f.type === 'date'; });
                body.push(field('Camp de data', select(c.field, dates.map(function (f) { return [f.key, f.label]; }), function (v) { set(s, 'field', v); }, '— Tria un camp —'),
                    dates.length > 1 ? null : 'Pots crear camps de tipus data (naixement, renovació…) a Contactes → Camps.'));
                var abs = Math.abs(c.offset || 0), dir = (c.offset || 0) < 0 ? 'before' : 'after';
                var num = text(abs, function (v) { set(s, 'offset', (dir === 'before' ? -1 : 1) * Math.max(0, Math.min(365, parseInt(v, 10) || 0))); }, { type: 'number', min: 0, max: 365 });
                body.push(h('div', { class: 'row' }, [field('Dies', num), field('Respecte la data', select(dir, [['after', 'després (o el mateix dia)'], ['before', 'abans']], function (v) { dir = v; set(s, 'offset', (v === 'before' ? -1 : 1) * abs); }))]));
                body.push(h('div', { class: 'row' }, [field('Es repeteix', select(c.repeat, [['yearly', 'Cada any (aniversari)'], ['once', 'Un sol cop (data exacta)']], function (v) { set(s, 'repeat', v); })),
                    field('Hora', text(c.at, function (v) { if (/^\d{2}:\d{2}$/.test(v)) set(s, 'at', v); }, { type: 'time' }))]));
            }
            if (c.kind === 'manual') body.push(h('p', { class: 'small muted', html: 'Inscriu els contactes d\'una llista o d\'un segment a <a href="' + CFG.urls.settings + '">Configuració</a> (amb l\'automatització activa).' }));
        } else if (s.type === 'email') {
            body.push(field('Nom intern', text(c.name, function (v) { set(s, 'name', v); }, { maxlength: 120, placeholder: 'Benvinguda' }), 'Per reconèixer-lo al flux i a les condicions.'));
            var camp = campaigns[c.campaign_id];
            if (camp) {
                body.push(h('div', { class: 'fl-subjects' }, Object.keys(camp.langs).map(function (l) {
                    var x = camp.langs[l];
                    return h('p', {}, [h('span', { class: 'pill', text: CFG.langs[l] || l }), ' ', x.subject ? h('b', { text: x.subject }) : h('span', { class: 'muted', text: 'Sense assumpte' }), h('span', { class: 'small muted', text: ' · ' + x.blocks + ' blocs' })]);
                })));
                body.push(h('div', { class: 'btns' }, [
                    editable ? h('button', { class: 'btn sm', type: 'button', onclick: function () { openEmail(s.id); } }, ['Edita el contingut']) : null,
                    h('a', { class: 'btn sm secondary', href: camp.preview, target: '_blank' }, ['Previsualitza']),
                    (stats[s.id] || {}).sent ? h('a', { class: 'btn sm secondary', href: camp.report }, ['Informe']) : null
                ]));
                var st = stats[s.id] || {};
                if (st.sent) body.push(h('p', { class: 'small muted', text: n(st.sent) + ' enviats · ' + pct(st.opened, st.sent) + ' d\'obertura · ' + pct(st.clicked, st.sent) + ' de clics · ' + n(st.unsub) + ' baixes' }));
                body.push(h('p', { class: 'small muted', text: 'S\'edita amb el mateix editor que les campanyes (idiomes, traducció, imatges i peu legal). Cada contacte el rep en el seu idioma.' }));
            } else {
                body.push(field('Comença amb', select(c.template_id || '', opts(CFG.templates), function (v) { set(s, 'template_id', +v); }, 'Correu bàsic')));
                body.push(field('Idioma en què l\'escrius', select(c.lang || CFG.lang, Object.keys(CFG.langs).map(function (k) { return [k, CFG.langs[k]]; }), function (v) { set(s, 'lang', v); })));
                if (editable) body.push(h('button', { class: 'btn sm', type: 'button', onclick: function () { openEmail(s.id); } }, ['Crea i edita el contingut']));
            }
        } else if (s.type === 'delay') {
            body.push(h('div', { class: 'row' }, [field('Quant', text(c.amount, function (v) { set(s, 'amount', Math.max(1, Math.min(999, parseInt(v, 10) || 1))); }, { type: 'number', min: 1, max: 999 })),
                field('Unitat', select(c.unit, Object.keys(CFG.units).map(function (k) { return [k, CFG.units[k]]; }), function (v) { set(s, 'unit', v); }))]));
            var cb = h('input', { type: 'checkbox', disabled: !editable, onchange: function () { set(s, 'at', cb.checked ? '09:00' : '', true); } });
            cb.checked = !!c.at;
            body.push(h('label', { class: 'check' }, [cb, ' I després, fins a una hora concreta']));
            if (c.at) body.push(field('Hora', text(c.at, function (v) { if (/^\d{2}:\d{2}$/.test(v)) set(s, 'at', v); }, { type: 'time' }), 'Per exemple: espera 1 dia i envia a les 9:00 del matí (hora de Madrid).'));
        } else if (s.type === 'condition') {
            var emails = ancestors(s.id).map(byId).filter(function (x) { return x && x.type === 'email'; }).reverse();
            body.push(field('Què comprova', select(c.kind, [['opened', 'Ha obert un correu del flux'], ['clicked', 'Ha clicat un correu del flux'], ['field', 'Un camp del contacte'], ['list', 'Si és en una llista'], ['segment', 'Si és en un segment']],
                function (v) { c.kind = v; c.op = { opened: 'yes', clicked: 'yes', list: 'in', segment: 'in' }[v] || 'eq'; if ((v === 'opened' || v === 'clicked') && !c.step && emails.length) c.step = emails[emails.length - 1].id; change(); renderPanel(); })));
            if (c.kind === 'opened' || c.kind === 'clicked') {
                body.push(field('Resultat', select(c.op, [['yes', c.kind === 'opened' ? 'L\'ha obert' : 'Hi ha clicat'], ['no', c.kind === 'opened' ? 'No l\'ha obert' : 'No hi ha clicat']], function (v) { set(s, 'op', v); })));
                body.push(field('Correu', select(c.step, emails.map(function (x) { return [x.id, x.config.name || 'Correu sense nom']; }), function (v) { set(s, 'step', v); }, emails.length ? null : '— Cap correu abans d\'aquest pas —'),
                    'Posa-hi una espera abans perquè el contacte tingui temps d\'obrir-lo.'));
            } else if (c.kind === 'field') {
                body.push(field('Camp', select(c.field, CFG.fields.map(function (f) { return [f.key, f.label]; }), function (v) { set(s, 'field', v); }, '— Tria un camp —')));
                body.push(field('Condició', select(c.op, Object.keys(CFG.fieldOps).map(function (k) { return [k, CFG.fieldOps[k]]; }), function (v) { set(s, 'op', v, true); })));
                if (c.op !== 'empty' && c.op !== 'not_empty') body.push(field('Valor', text(c.value, function (v) { set(s, 'value', v); }, { maxlength: 255, placeholder: c.field === 'language' ? 'ca, es, en o fr' : '' })));
            } else {
                var isList = c.kind === 'list';
                body.push(field('Condició', select(c.op, [['in', isList ? 'És a la llista' : 'És al segment'], ['not_in', isList ? 'No és a la llista' : 'No és al segment']], function (v) { set(s, 'op', v); })));
                body.push(isList ? field('Llista', select(c.list_id || '', opts(CFG.lists), function (v) { set(s, 'list_id', +v); }, '— Tria una llista —'))
                    : field('Segment', select(c.segment_id || '', opts(CFG.segments), function (v) { set(s, 'segment_id', +v); }, '— Tria un segment —')));
            }
            var br = h('input', { type: 'checkbox', disabled: !editable, onchange: function () { setBranched(s, br.checked); } });
            br.checked = !!c.branched;
            body.push(h('label', { class: 'check' }, [br, ' Dues branques: Sí / No']));
            body.push(h('p', { class: 'small muted', text: c.branched ? 'Cada contacte segueix la branca que li toca.' : 'Sense branques, qui no compleix la condició surt del flux.' }));
        } else if (s.type === 'action') {
            body.push(field('Què fa', select(c.kind, [['list_add', 'Afegeix a una llista'], ['list_remove', 'Treu d\'una llista'], ['set_field', 'Posa un valor a un camp']], function (v) { set(s, 'kind', v, true); })));
            if (c.kind === 'set_field') {
                body.push(field('Camp', select(c.field, CFG.fields.filter(function (f) { return ['email', 'created_at'].indexOf(f.key) < 0; }).map(function (f) { return [f.key, f.label]; }), function (v) { set(s, 'field', v); }, '— Tria un camp —')));
                body.push(field('Valor', text(c.value, function (v) { set(s, 'value', v); }, { maxlength: 1000 })));
            } else {
                body.push(field('Llista', select(c.list_id || '', opts(CFG.lists), function (v) { set(s, 'list_id', +v); }, '— Tria una llista —'),
                    c.kind === 'list_add' ? 'Si una altra automatització comença amb aquesta llista, el contacte hi entrarà.' : null));
            }
        }
        if (editable && s.type !== 'trigger') body.push(h('button', { class: 'btn sm danger fl-rm', type: 'button', onclick: function () { removeStep(s.id); } }, ['Elimina el pas']));
        panel.appendChild(h('div', { class: 'fl-pb' }, body));
    }

    function renderIntro() {
        var input = h('input', { class: 'input', id: 'fl-sim-email', type: 'email', placeholder: 'correu@exemple.cat' });
        var out = h('div', { class: 'fl-sim' });
        var run = function () {
            if (dirty) { mlToast('Desa primer el flux: la prova es fa amb la versió desada.', true); return; }
            mlApi(CFG.urls.simulate, { method: 'POST', json: { email: input.value } }).then(function (r) { return r.json(); }).then(function (d) {
                out.innerHTML = '';
                if (!d.ok) { mlToast(d.message || 'No s\'ha pogut fer la prova.', true); return; }
                path = {};
                if (d.status) out.appendChild(h('p', { class: 'alert warn small', text: d.status }));
                out.appendChild(h('ol', {}, d.path.map(function (p) {
                    if (p.step) path[p.step] = true;
                    return h('li', {}, [p.label, p.result ? h('span', { class: 'pill ' + (p.result === 'Sí' ? 'ok' : 'err'), text: ' ' + p.result }) : null]);
                })));
                render();
            });
        };
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(); } });
        panel.appendChild(h('div', { class: 'fl-pb' }, [
            h('p', { class: 'fl-intro', html: '<b>Com funciona</b>Cada contacte que compleix el disparador entra al flux i avança pas a pas: rep els correus, espera, i a les condicions segueix la branca que li toca. Si es dona de baixa, en surt.' }),
            editable ? h('p', { class: 'small muted', html: 'Clica un pas per configurar-lo, o el botó <b>+</b> d\'una línia per afegir-ne un.' }) : null,
            h('span', { class: 'field-label', text: 'Prova amb un contacte' }),
            h('div', { class: 'fl-simrow' }, [input, h('button', { class: 'btn sm secondary', type: 'button', onclick: run }, ['Prova'])]),
            h('p', { class: 'small muted', text: 'Mostra el camí que faria avui pel flux (sense esperes ni enviaments).' }),
            out
        ]));
    }

    // ---------------------------------------------------------------- save
    function save() {
        saveBtn.disabled = true;
        stateEl.textContent = 'Desant…';
        return mlApi(CFG.urls.save, { method: 'POST', json: { flow: flow } }).then(function (r) { return r.json().then(function (d) { return [r, d]; }); }).then(function (x) {
            var d = x[1];
            if (!x[0].ok || !d.ok) { mlToast(d.message || 'No s\'ha pogut desar.', true); dirty = true; render(); return false; }
            flow = d.flow;
            campaigns = d.campaigns || {};
            stats = d.stats || {};
            dirty = false;
            if (selected && !byId(selected)) selected = null;
            render();
            renderPanel();
            checks(d.checks || []);
            mlToast(d.message || 'Desat.');
            return true;
        }).catch(function () { mlToast('No s\'ha pogut desar: comprova la connexió.', true); dirty = true; render(); return false; });
    }
    function checks(list) {
        var box = document.getElementById('flow-checks');
        if (!box) return;
        var ul = box.querySelector('ul');
        ul.innerHTML = '';
        list.forEach(function (c) { ul.appendChild(h('li', { class: c.block ? 'block' : 'warn', text: c.label })); });
        box.hidden = !list.length;
    }
    function openEmail(id) {
        var go = function () {
            var s = byId(id), camp = s && campaigns[s.config.campaign_id];
            if (camp) location.href = camp.edit;
        };
        if (dirty || !campaigns[(byId(id) || {}).config.campaign_id]) save().then(function (ok) { if (ok) go(); });
        else go();
    }

    build();
    render();
    renderPanel();
})();
