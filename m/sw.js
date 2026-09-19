/* Service worker — يتيح تثبيت PWA؛ الصفحات دائماً من الشبكة */
'use strict';

var CACHE_VERSION = 'manager-mobile-v1';

self.addEventListener('install', function (event) {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (event) {
  if (event.request.method !== 'GET') {
    return;
  }
  event.respondWith(
    fetch(event.request).catch(function () {
      return new Response('لا اتصال بالخادم.', {
        status: 503,
        headers: { 'Content-Type': 'text/plain; charset=utf-8' },
      });
    })
  );
});
