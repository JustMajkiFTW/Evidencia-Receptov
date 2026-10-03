// Service worker pre appku "Evidencia receptov".
//
// DÔLEŽITÉ: index.php je dynamická stránka viazaná na session a CSRF token,
// preto sa NIKDY nesmie servovať z cache — vždy ide na sieť. Cachujeme len
// statické súbory (CSS/JS/ikony), aby appka rýchlejšie štartovala a aby sa
// dala pridať na plochu ako PWA. Bump CACHE_NAME pri každej zmene statických súborov.
const CACHE_NAME = 'recepty-static-v21';
const OFFLINE_URL = 'offline.html';

const STATIC_ASSETS = [
    'style.css',
    'script.js',
    'suroviny.js',
    'theme.js',
    'app-icons/apple-touch-icon.png',
    OFFLINE_URL,
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)).catch(() => {})
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    const url = new URL(req.url);

    // Nikdy necachovať a nezachytávať POST/AJAX volania na index.php (add/update/delete...).
    if (req.method !== 'GET') return;

    // Všetky PHP stránky (index.php, login.php, admin/…) idú vždy priamo na sieť —
    // obsahujú CSRF token a dáta viazané na aktuálnu session. Z cache by sa
    // zobrazovala stará verzia stránky (napr. admin so zastaraným zoznamom).
    // Výnimkou je /webauthn/, ktoré má vlastné pravidlo nižšie.
    const isAppShellPage = !url.pathname.includes('/webauthn/') && url.origin === self.location.origin
        && (req.mode === 'navigate' || url.pathname.endsWith('.php') || url.pathname.endsWith('/'));
    if (isAppShellPage) {
        event.respondWith(
            fetch(req).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    // /webauthn/*.php (napr. register_options.php) vracia jednorazový "challenge"
    // naviazaný na AKTUÁLNU session — NIKDY sa nesmie servovať z cache, inak sa
    // prehliadaču podstrčí starý challenge, ktorý už na serveri neplatí, a
    // registrácia/prihlásenie biometriou zlyhá s "Invalid challenge".
    const isWebauthnEndpoint = url.pathname.includes('/webauthn/');
    if (isWebauthnEndpoint) {
        event.respondWith(fetch(req));
        return;
    }

    // manifest.json a ikony: vždy najprv zo servera (network-first). Prehliadač
    // ich číta pri inštalácii PWA — keby dostal starú kópiu z cache, appka by sa
    // nainštalovala so zastaranou/inou ikonou. Cache slúži len ako záloha offline.
    const isManifestOrIcon = url.pathname.endsWith('/manifest.json') || url.pathname.includes('/app-icons/');
    if (isManifestOrIcon) {
        event.respondWith(
            fetch(req).then((res) => {
                if (res && res.ok) {
                    const copy = res.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(req, copy));
                }
                return res;
            }).catch(() => caches.match(req))
        );
        return;
    }

    // Statické súbory rovnakého pôvodu: cache-first, s aktualizáciou na pozadí.
    if (url.origin === self.location.origin) {
        event.respondWith(
            caches.match(req).then((cached) => {
                const network = fetch(req).then((res) => {
                    if (res && res.ok) {
                        const copy = res.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(req, copy));
                    }
                    return res;
                }).catch(() => cached);
                return cached || network;
            })
        );
    }
    // Cudzie domény (CDN Bootstrap a pod.) necháme ísť normálne cez sieť/prehliadačovú cache.
});

/* ========================================================================
   Push notifikácie — appka "Evidencia receptov". Zobrazí notifikáciu
   keď server pošle push (napr. pripomienka, zdieľanie receptu a pod.)
   ======================================================================== */
self.addEventListener('push', (event) => {
    let data = {};
    if (event.data) {
        try { data = event.data.json(); } catch (e) { data = { body: event.data.text() }; }
    }

    const title = data.title || 'Evidencia receptov';
    const options = {
        body: data.body || '',
        icon: 'app-icons/icon-192.png?v=4',
        badge: 'app-icons/icon-192.png?v=4',
        data: { url: data.url || './' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    // Adresa z upozornenia (napr. './?recept=12') vzhľadom na priečinok appky.
    const target = new URL((event.notification.data && event.notification.data.url) || './', self.registration.scope).href;

    event.waitUntil((async () => {
        const clientList = await clients.matchAll({ type: 'window', includeUncontrolled: true });
        const client = clientList.find((c) => c.url.startsWith(self.registration.scope));
        if (client) {
            // Appka je už otvorená: prepneme na ňu a načítame adresu z upozornenia,
            // aby sa otvoril správny recept (samotné focus() by nechalo starú stránku).
            try {
                if ('focus' in client) await client.focus();
                if ('navigate' in client) {
                    await client.navigate(target);
                    return;
                }
            } catch (e) {
                // navigate() nie je všade dostupné — skúsime nové okno nižšie
            }
        }
        if (clients.openWindow) await clients.openWindow(target);
    })());
});
