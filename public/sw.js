/*
 * Service worker de Mailing: mostra els avisos push, obre la pàgina en
 * tocar-los i ensenya una pàgina «Sense connexió» si no hi ha xarxa.
 * No desa cap dada de la plataforma al dispositiu.
 */
var OFFLINE = '/offline.html';
var CACHE = 'mailing-offline-1';

self.addEventListener('install', function (e) {
    e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll([OFFLINE, '/icons/icon-192.png']); }));
    self.skipWaiting();
});

self.addEventListener('activate', function (e) {
    e.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
    if (e.request.mode !== 'navigate') return;
    e.respondWith(fetch(e.request).catch(function () { return caches.match(OFFLINE); }));
});

self.addEventListener('push', function (e) {
    var d = {};
    try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
    var jobs = [self.registration.showNotification(d.title || 'Mailing', {
        body: d.body || '',
        tag: d.tag || undefined,
        renotify: !!d.tag,
        icon: '/icons/icon-192.png',
        badge: '/icons/badge-96.png',
        lang: 'ca',
        data: { url: d.url || '/avisos' }
    })];
    if (self.navigator && self.navigator.setAppBadge && d.badge) jobs.push(self.navigator.setAppBadge(d.badge).catch(function () {}));
    e.waitUntil(Promise.all(jobs));
});

self.addEventListener('notificationclick', function (e) {
    e.notification.close();
    var url = new URL((e.notification.data && e.notification.data.url) || '/avisos', self.location.origin).href;
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        for (var i = 0; i < list.length; i++) {
            var c = list[i];
            if (c.url.indexOf(self.location.origin) === 0 && 'focus' in c) {
                return c.focus().then(function (w) { return w.navigate ? w.navigate(url) : null; });
            }
        }
        return self.clients.openWindow(url);
    }));
});
