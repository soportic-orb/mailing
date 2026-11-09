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
})();
