// Service worker: только push-уведомления. Страницы не кешируются — приложение работает как раньше.

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }
    event.waitUntil(self.registration.showNotification(data.title || 'Lift Fault Terminal', {
        body: data.body || '',
        tag: data.tag || undefined,
        renotify: !!data.tag,
        icon: '/assets/img/app-192.png',
        badge: '/assets/img/badge-96.png',
        lang: 'ru',
        data: { url: data.url || '#/' }
    }));
});

// Нажатие на уведомление: открытое приложение переходит на нужный экран, иначе открывается новое
self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var hash = (event.notification.data && event.notification.data.url) || '#/';
    var url = new URL('/' + hash, self.location.origin).href;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        for (var i = 0; i < list.length; i++) {
            if (new URL(list[i].url).origin === self.location.origin) {
                list[i].postMessage({ type: 'open', hash: hash });
                return list[i].focus();
            }
        }
        return self.clients.openWindow(url);
    }));
});
