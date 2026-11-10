/* Plataforma de Mailing — comportaments comuns */
(function () {
    'use strict';
    window.mlToast = function (msg, err) {
        document.querySelectorAll('.toast').forEach(function (t) { t.remove(); });
        var t = document.createElement('div');
        t.className = 'toast' + (err ? ' err' : '');
        t.setAttribute('role', 'status');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, err ? 6000 : 3500);
    };
    window.mlCsrf = function () { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; };
    window.mlApi = function (url, opts) {
        opts = opts || {};
        var headers = Object.assign({ 'X-CSRF-TOKEN': mlCsrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, opts.headers || {});
        if (opts.json !== undefined) { headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(opts.json); }
        return fetch(url, { method: opts.method || 'GET', headers: headers, body: opts.body, credentials: 'same-origin' });
    };
    // Formularis que demanen confirmació.
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (f.dataset.confirm && !confirm(f.dataset.confirm)) { e.preventDefault(); return; }
        var b = f.querySelector('button[type="submit"][data-busy]');
        if (b) { setTimeout(function () { b.disabled = true; b.textContent = b.dataset.busy; }, 0); }
    });
    // Canvi de projecte.
    var ps = document.getElementById('proj-select');
    if (ps) ps.addEventListener('change', function () { ps.form.submit(); });
    // Tanca els menús desplegables en clicar fora.
    document.addEventListener('click', function (e) {
        document.querySelectorAll('details.ml-menu[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
    });
    // Marcar / desmarcar totes les files.
    document.querySelectorAll('[data-check-all]').forEach(function (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('input[name="' + all.dataset.checkAll + '"]').forEach(function (c) { c.checked = all.checked; });
        });
    });
    // Copiar al porta-retalls.
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-copy]'); if (!b) return;
        var el = document.getElementById(b.dataset.copy);
        var text = el ? (el.value || el.textContent) : b.dataset.copy;
        navigator.clipboard.writeText(text.trim()).then(function () { mlToast('Copiat.'); });
    });
    var fl = document.getElementById('flash-msg');
    if (fl) mlToast(fl.dataset.text, fl.dataset.type === 'err');

    // Full «Més» (telèfon i tauleta).
    var sheet = document.getElementById('nav-sheet');
    if (sheet && sheet.showModal) {
        document.querySelectorAll('[data-sheet-open]').forEach(function (b) { b.addEventListener('click', function () { sheet.showModal(); }); });
        sheet.addEventListener('click', function (e) { if (e.target === sheet || e.target.closest('[data-sheet-close]')) sheet.close(); });
    }

    // ---------------------------------------------------------------- PWA
    var store = {
        get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
    };
    var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var touch = window.matchMedia('(pointer: coarse)').matches;
    var keyMeta = document.querySelector('meta[name="push-key"]');
    var pushKey = keyMeta ? keyMeta.content : '';
    var swReady = null;
    if ('serviceWorker' in navigator) {
        swReady = navigator.serviceWorker.register('/sw.js').then(function () { return navigator.serviceWorker.ready; }).catch(function () { return null; });
    }
    var pushSupported = !!(swReady && 'PushManager' in window && 'Notification' in window);

    function b64ToBytes(s) {
        var raw = atob((s + '==='.slice((s.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }
    function deviceName() {
        var ua = navigator.userAgent, os = /iphone/i.test(ua) ? 'iPhone' : /ipad/i.test(ua) || (ios && !/iphone/i.test(ua)) ? 'iPad' : /android/i.test(ua) ? 'Android' : /mac/i.test(ua) ? 'Mac' : /windows/i.test(ua) ? 'Windows' : 'Navegador';
        return os + (standalone ? ' · instal·lada' : ' · navegador');
    }
    function currentSub() {
        if (!pushSupported) return Promise.resolve(null);
        return swReady.then(function (reg) { return reg ? reg.pushManager.getSubscription() : null; });
    }
    /** 'off' | 'on' | 'denied' | 'install' (iPhone sense instal·lar) | 'unsupported' | 'disabled' */
    function pushState() {
        if (!pushKey) return Promise.resolve('disabled');
        if (ios && !standalone && !pushSupported) return Promise.resolve('install');
        if (!pushSupported) return Promise.resolve('unsupported');
        if (Notification.permission === 'denied') return Promise.resolve('denied');
        return currentSub().then(function (s) { return s ? 'on' : 'off'; });
    }
    function enablePush() {
        return Notification.requestPermission().then(function (perm) {
            if (perm !== 'granted') throw new Error('Cal permetre els avisos a la configuració del navegador.');
            return swReady;
        }).then(function (reg) {
            return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(pushKey) });
        }).then(function (sub) {
            var j = sub.toJSON();
            j.device = deviceName();
            return mlApi('/push/subscripcio', { method: 'POST', json: j }).then(function (r) { return r.json(); });
        }).then(function (d) {
            if (!d.ok) throw new Error(d.message || 'No s\'ha pogut activar.');
            mlToast(d.message);
        });
    }
    function disablePush() {
        return currentSub().then(function (sub) {
            if (!sub) return null;
            var endpoint = sub.endpoint;
            return sub.unsubscribe().then(function () { return mlApi('/push/baixa', { method: 'POST', json: { endpoint: endpoint } }); });
        }).then(function () { mlToast('Avisos desactivats en aquest dispositiu.'); });
    }
    window.mlPush = { state: pushState, enable: enablePush, disable: disablePush };

    // Interruptor del perfil.
    document.querySelectorAll('[data-push-toggle]').forEach(function (box) {
        var status = box.querySelector('[data-push-status]'), btn = box.querySelector('[data-push-button]');
        if (!btn) return;
        var texts = {
            on: ['Activats en aquest dispositiu (' + deviceName() + ').', 'Desactiva'],
            off: ['Desactivats en aquest dispositiu.', 'Activa'],
            denied: ['Has bloquejat els avisos per a aquesta web: permet-los a la configuració del navegador o del telèfon.', ''],
            install: ['A l\'iPhone i l\'iPad, els avisos només funcionen amb Mailing instal·lada: Compartir → «Afegeix a la pantalla d\'inici».', ''],
            unsupported: ['Aquest navegador no admet avisos push.', ''],
            disabled: ['Els avisos push no estan activats en aquesta plataforma.', '']
        };
        function paint() {
            pushState().then(function (st) {
                status.textContent = texts[st][0];
                btn.hidden = !texts[st][1];
                btn.textContent = texts[st][1];
                btn.dataset.state = st;
            });
        }
        btn.addEventListener('click', function () {
            btn.disabled = true;
            (btn.dataset.state === 'on' ? disablePush() : enablePush()).catch(function (e) { mlToast(e.message, true); }).then(function () { btn.disabled = false; paint(); });
        });
        paint();
    });

    // En tancar la sessió, aquest dispositiu deixa de rebre avisos d'aquest usuari.
    document.querySelectorAll('form[data-logout]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (f.dataset.done) return;
            e.preventDefault();
            var go = function () { f.dataset.done = '1'; f.submit(); };
            currentSub().then(function (sub) {
                if (!sub) return go();
                var endpoint = sub.endpoint;
                sub.unsubscribe().catch(function () {}).then(function () { return mlApi('/push/baixa', { method: 'POST', json: { endpoint: endpoint } }); }).catch(function () {}).then(go);
            }).catch(go);
            setTimeout(go, 2500);
        });
    });

    // Targeta d'instal·lació i d'avisos (només als dispositius tàctils).
    var card = document.getElementById('install-card');
    function showCard(key, html, onAction) {
        if (!card || store.get('ml-card-' + key)) return;
        card.innerHTML = '<img src="/icons/icon-192.png" alt=""><div>' + html + '</div><button type="button" class="x" aria-label="Tanca">×</button>';
        card.hidden = false;
        card.querySelector('.x').addEventListener('click', function () { store.set('ml-card-' + key, '1'); card.hidden = true; });
        var later = card.querySelector('[data-later]');
        if (later) later.addEventListener('click', function () { store.set('ml-card-' + key, '1'); card.hidden = true; });
        var act = card.querySelector('[data-act]');
        if (act && onAction) act.addEventListener('click', onAction);
    }
    if (card && touch) {
        if (!standalone) {
            var deferred = null;
            window.addEventListener('beforeinstallprompt', function (e) {
                e.preventDefault();
                deferred = e;
                showCard('install', '<b>Instal·la Mailing</b><p>Obre-la des de la pantalla d\'inici, a pantalla completa, i rep avisos de les campanyes.</p><div class="btns"><button class="btn sm" type="button" data-act>Instal·la</button><button class="btn sm secondary" type="button" data-later>Ara no</button></div>', function () {
                    deferred.prompt();
                    deferred.userChoice.then(function () { card.hidden = true; deferred = null; });
                });
            });
            if (ios) {
                showCard('install-ios', '<b>Afegeix Mailing a la pantalla d\'inici</b><div class="install-steps">'
                    + '<span><em>1</em>Toca <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><use href="#i-share"/></svg> Compartir a Safari</span>'
                    + '<span><em>2</em>Tria <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><use href="#i-plus"/></svg> «Afegeix a la pantalla d\'inici»</span></div>'
                    + '<div class="btns"><button class="btn sm secondary" type="button" data-later>D\'acord</button></div>');
            }
        } else if (pushKey) {
            pushState().then(function (st) {
                if (st !== 'off') return;
                showCard('push', '<b>Avisos en aquest dispositiu</b><p>Rep un avís quan una campanya s\'enviï o falli. Pots triar quins al teu perfil.</p><div class="btns"><button class="btn sm" type="button" data-act>Activa els avisos</button><button class="btn sm secondary" type="button" data-later>Ara no</button></div>', function () {
                    enablePush().then(function () { card.hidden = true; }).catch(function (e) { mlToast(e.message, true); });
                });
            });
        }
    }
    if (navigator.clearAppBadge && standalone && document.visibilityState === 'visible' && location.pathname === '/avisos') navigator.clearAppBadge().catch(function () {});
})();
