const CACHE_NAME = 'rxtracker-v7';
const SHELL_URLS = [
  'index.php',
  'assets/css/styles.css',
  'assets/js/app.js',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
  'manifest.json',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_URLS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Only handle same-origin requests
  if (url.origin !== self.location.origin) return;

  // API / action requests: network-only (no stale data for doses/reminders)
  if (url.searchParams.has('action')) {
    event.respondWith(fetch(request).catch(() => Response.error()));
    return;
  }

  // Static assets (CSS, JS, images): cache-first
  const isStatic = /\.(css|js|png|jpg|jpeg|svg|ico|woff2?)$/.test(url.pathname);
  if (isStatic) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached;
        return fetch(request).then((response) => {
          if (response.ok) {
            const cloned = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, cloned));
          }
          return response;
        }).catch(() => Response.error());
      })
    );
    return;
  }

  // Non-GET requests (POST form submissions): always network-only —
  // never serve a cached response for mutations; let failures surface to the caller.
  if (request.method !== 'GET') {
    event.respondWith(fetch(request).catch(() => Response.error()));
    return;
  }

  // GET HTML pages (including index.php): network-first, fall back to cached shell
  event.respondWith(
    fetch(request).then((response) => {
      if (response.ok) {
        const cloned = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, cloned));
      }
      return response;
    }).catch(() => caches.match('index.php').then((cached) => cached ?? Response.error()))
  );
});

self.addEventListener('push', (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (error) {
    payload = {};
  }

  const title = payload.title || 'Medication reminder';
  const snoozeMins = payload.snoozeMins || 15;
  const options = {
    body: payload.body || 'A dose is due now.',
    tag: payload.tag || 'rx-reminder',
    renotify: true,
    requireInteraction: true,
    silent: false,
    vibrate: [400, 200, 400, 200, 400],
    icon: 'assets/icons/icon-192.png',
    badge: 'assets/icons/icon-192.png',
    actions: [
      { action: 'take', title: 'Take Now' },
      { action: 'snooze', title: `Snooze ${snoozeMins} min` },
    ],
    data: {
      url: payload.url || 'index.php',
      nonce: payload.nonce || null,
      snoozeMins,
    },
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const data = event.notification.data || {};
  const action = event.action;
  const nonce = data.nonce;
  const targetUrl = data.url || 'index.php';

  // Handle quick-action buttons (Take Now / Snooze) via background fetch
  if ((action === 'take' || action === 'snooze') && nonce) {
    const act = action === 'take' ? 'take' : 'snooze';
    const snoozeMins = data.snoozeMins || 15;
    const apiUrl = `index.php?action=push_action&act=${act}&nonce=${encodeURIComponent(nonce)}&minutes=${snoozeMins}`;
    event.waitUntil(
      fetch(apiUrl, { credentials: 'same-origin' })
        .then((r) => r.json())
        .then((json) => {
          const confirmBody = json.ok
            ? (action === 'take' ? 'Dose marked as taken ✓' : `Reminder snoozed ${snoozeMins} min ✓`)
            : (json.error || 'Action failed.');
          return self.registration.showNotification('RxTracker', {
            body: confirmBody,
            tag: 'rx-action-confirm',
            icon: 'assets/icons/icon-192.png',
            requireInteraction: false,
          });
        })
        .catch(() => {})
    );
    return;
  }

  // Default: focus existing window or open app
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      for (const client of windowClients) {
        if ('focus' in client) {
          client.navigate(targetUrl);
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
      return null;
    })
  );
});
