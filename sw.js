// Service worker pre appku "Evidencia receptov".
//
// Čo robí:
//  - Statické súbory (CSS, JS, ikony, Bootstrap z CDN) drží v cache, aby sa appka
//    rýchlo načítala a aby mala štýly aj bez internetu.
//  - Hlavnú stránku (index.php) vždy načíta zo servera. Každú úspešne načítanú
//    stránku prihláseného používateľa si uloží ako kópiu (index.php ju označí
//    hlavičkou X-Recepty-Offline). Keď server nie je dostupný, ukáže túto kópiu
//    s príznakom data-offline="kopia" na <html> — recepty sa dajú pozerať,
//    tlačidlá na zápis script.js zablokuje.
//  - Fotky receptov ukladá do vlastnej cache s limitom počtu.
//  - Pri odhlásení (alebo keď server pošle na prihlásenie) uloženú stránku aj fotky zmaže.
//
// DÔLEŽITÉ: kópia index.php sa nikdy nepoužije, keď server normálne odpovedá —
// obsahuje CSRF token a dáta viazané na session. Ostatné PHP stránky (login,
// admin, webauthn) idú vždy len na sieť. Bump CACHE_NAME pri každej zmene
// statických súborov.
const CACHE_NAME = 'recepty-static-v23';
const STRANKA_CACHE = 'recepty-stranka';   // posledná kópia index.php (prežije aktualizáciu appky)
const FOTKY_CACHE = 'recepty-fotky';       // fotky receptov (prežijú aktualizáciu appky)
const MAX_FOTIEK = 250;                    // ~200 kB na fotku → najviac okolo 50 MB
const LIMIT_SIETE_MS = 10000;              // dlhšie sa na server nečaká, ak je uložená kópia
const OFFLINE_URL = 'offline.html';

// Adresa, pod ktorou je uložená kópia hlavnej stránky (bez ?recept=…).
const STRANKA_KLUC = new URL('index.php', self.registration.scope).href;
const KOREN_APPKY = new URL(self.registration.scope).pathname;

const STATIC_ASSETS = [
    'style.css',
    'script.js',
    'suroviny.js',
    'theme.js',
    'app-icons/apple-touch-icon.png',
    OFFLINE_URL,
];

// Bootstrap a ikony z CDN — rovnaké adresy ako v index.php. Bez nich by uložená
// stránka offline nemala štýly ani funkčné okná (modaly).
const CDN_ORIGIN = 'https://cdn.jsdelivr.net';
const CDN_ASSETS = [
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2?dd67030699838ea613ee6dbda90effa6',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => Promise.all([
            // cache: 'reload' — vždy čerstvo zo servera, nie staršia kópia z cache prehliadača.
            cache.addAll(STATIC_ASSETS.map((subor) => new Request(subor, { cache: 'reload' }))).catch(() => {}),
            // Každý súbor z CDN zvlášť: keď jeden zlyhá, ostatné sa uložia.
            ...CDN_ASSETS.map((adresa) => cache.add(cdnPoziadavka(adresa)).catch(() => {})),
        ]))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    const ponechat = [CACHE_NAME, STRANKA_CACHE, FOTKY_CACHE];
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => !ponechat.includes(k)).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

// Stránka po prevzatí novým service workerom požiada o uloženie kópie (script.js,
// udalosť controllerchange). Prvé otvorenie po aktualizácii appky totiž ešte obslúžil
// starý service worker, ktorý stránku neukladal. Cez správu, nie pri aktivácii,
// aby pomalý server nezdržal spustenie service workera.
self.addEventListener('message', (event) => {
    if (event.data === 'uloz-stranku') {
        event.waitUntil(obnovUlozenuStranku());
    }
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Nikdy necachovať a nezachytávať POST/AJAX volania na index.php (add/update/delete...).
    if (req.method !== 'GET') return;

    const url = new URL(req.url);

    // Bootstrap a ikony z CDN: z cache, inak zo siete (adresy majú pevnú verziu).
    if (url.origin === CDN_ORIGIN) {
        event.respondWith(zCdn(req));
        return;
    }
    // Ostatné cudzie domény necháme ísť normálne cez sieť/prehliadačovú cache.
    if (url.origin !== self.location.origin) return;

    // /webauthn/*.php (napr. register_options.php) vracia jednorazový "challenge"
    // naviazaný na AKTUÁLNU session — NIKDY sa nesmie servovať z cache, inak sa
    // prehliadaču podstrčí starý challenge, ktorý už na serveri neplatí, a
    // registrácia/prihlásenie biometriou zlyhá s "Invalid challenge".
    if (url.pathname.includes('/webauthn/')) {
        event.respondWith(fetch(req));
        return;
    }

    // PHP stránky idú vždy na sieť — obsahujú CSRF token a dáta viazané na session.
    // Jediná výnimka je hlavná stránka: pri výpadku sa ukáže jej uložená kópia.
    const jeStranka = req.mode === 'navigate' || url.pathname.endsWith('.php') || url.pathname.endsWith('/');
    if (jeStranka) {
        if (req.mode === 'navigate' && jeHlavnaStranka(url)) {
            hlavnaStranka(event);
            return;
        }
        if (url.pathname.endsWith('/logout.php')) {
            // Odhlásenie: uložená stránka a fotky na zariadení ostať nesmú.
            event.waitUntil(zmazUlozeneUdaje());
        }
        event.respondWith(
            fetch(req).catch(() => caches.match(OFFLINE_URL))
        );
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

    // Fotky receptov: názov súboru je jedinečný a obsah sa nemení, preto stačí
    // cache-first. Vlastná cache s limitom, aby fotky nezaplnili telefón.
    if (url.pathname.includes('/uploads/')) {
        fotka(event);
        return;
    }

    // Statické súbory rovnakého pôvodu: cache-first, s aktualizáciou na pozadí.
    // Offline sa skúsi aj súbor bez ?v=…: uložená stránka môže byť zo staršej
    // verzie appky a odkazovať na staršie číslo, než aké je teraz v cache.
    event.respondWith(
        caches.match(req).then((cached) => {
            const network = fetch(req).then((res) => {
                if (res && res.ok) {
                    const copy = res.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(req, copy));
                }
                return res;
            }).catch(() => cached || caches.match(req, { ignoreSearch: true }));
            return cached || network;
        })
    );
});

/* ========================================================================
   Hlavná stránka a jej uložená kópia
   ======================================================================== */

/** index.php alebo koreň appky (aj s ?recept=… z upozornenia). */
function jeHlavnaStranka(url) {
    return url.pathname === KOREN_APPKY || url.pathname === KOREN_APPKY + 'index.php';
}

/**
 * Hlavná stránka: najprv server. Keď neodpovie (bez internetu, výpadok hostingu)
 * alebo odpovedá dlhšie ako LIMIT_SIETE_MS, ukáže sa uložená kópia. Odpoveď
 * servera sa uloží aj vtedy, keď príde až po limite.
 */
function hlavnaStranka(event) {
    let hotovo;
    event.waitUntil(new Promise((resolve) => { hotovo = resolve; }));

    const zoSiete = fetch(event.request).then((res) => {
        hotovo(spracujStranku(res.clone()).catch(() => {}));
        return res;
    }, (chyba) => {
        hotovo();
        throw chyba;
    });

    event.respondWith(odpovedHlavnejStranky(zoSiete));
}

async function odpovedHlavnejStranky(zoSiete) {
    const kopia = await caches.open(STRANKA_CACHE).then((cache) => cache.match(STRANKA_KLUC));
    if (!kopia) {
        return zoSiete.catch(() => caches.match(OFFLINE_URL));
    }
    try {
        const res = await Promise.race([zoSiete, casovyLimit(LIMIT_SIETE_MS)]);
        // Hosting je dočasne mimo prevádzky — kópia poslúži lepšie ako chybová stránka.
        if ([502, 503, 504].includes(res.status)) {
            return offlineVerzia(kopia);
        }
        return res;
    } catch (e) {
        return offlineVerzia(kopia);
    }
}

function casovyLimit(ms) {
    return new Promise((_, reject) => setTimeout(() => reject(new Error('Server neodpovedal včas')), ms));
}

/**
 * Uloží stránku prihláseného používateľa. Keď server namiesto stránky presmeruje
 * na prihlásenie (odhlásený alebo vypršané prihlásenie), uložené dáta zmaže.
 */
async function spracujStranku(res) {
    if (res.ok && !res.redirected && res.headers.get('X-Recepty-Offline') === '1') {
        const telo = await res.arrayBuffer();
        const cache = await caches.open(STRANKA_CACHE);
        await cache.put(STRANKA_KLUC, new Response(telo, {
            headers: {
                'Content-Type': 'text/html; charset=utf-8',
                'X-Ulozene': new Date().toISOString(),
            },
        }));
        return;
    }
    // Navigácia dostane presmerovanie ako 'opaqueredirect' (cieľ nevidno). index.php
    // presmerúva len na prihlásenie, takže aj to znamená „nie si prihlásený".
    const naPrihlasenie = res.type === 'opaqueredirect'
        || (res.redirected && new URL(res.url).pathname.endsWith('/login.php'));
    if (naPrihlasenie) {
        await zmazUlozeneUdaje();
    }
}

/** Uložená kópia s príznakom offline a časom uloženia (číta ich script.js). */
async function offlineVerzia(kopia) {
    const ulozene = kopia.headers.get('X-Ulozene') || '';
    const cas = /^[0-9T:.Z-]+$/.test(ulozene) ? ulozene : '';
    const html = (await kopia.text()).replace(/<html\b/i, `<html data-offline="kopia" data-offline-ulozene="${cas}"`);
    return new Response(html, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

/** Načíta hlavnú stránku zo servera a uloží ju (na žiadosť stránky, pozri udalosť 'message'). */
function obnovUlozenuStranku() {
    return fetch(STRANKA_KLUC, { credentials: 'same-origin', cache: 'no-store' })
        .then(spracujStranku)
        .catch(() => {});
}

function zmazUlozeneUdaje() {
    return Promise.all([caches.delete(STRANKA_CACHE), caches.delete(FOTKY_CACHE)]);
}

/* ========================================================================
   Fotky receptov a knižnice z CDN
   ======================================================================== */

function fotka(event) {
    event.respondWith((async () => {
        const cache = await caches.open(FOTKY_CACHE);
        const ulozena = await cache.match(event.request);
        if (ulozena) return ulozena;
        const res = await fetch(event.request);
        if (res && res.ok) {
            event.waitUntil(ulozFotku(cache, event.request, res.clone()).catch(() => {}));
        }
        return res;
    })());
}

/** Uloží fotku a najstaršie uložené zmaže, keď ich je viac ako MAX_FOTIEK. */
async function ulozFotku(cache, req, res) {
    await cache.put(req, res);
    const kluce = await cache.keys();
    for (let i = 0; i < kluce.length - MAX_FOTIEK; i++) {
        await cache.delete(kluce[i]);
    }
}

/** CDN posiela hlavičky CORS — vďaka nim dostaneme čitateľnú odpoveď, ktorú možno uložiť. */
function cdnPoziadavka(adresa) {
    return new Request(adresa, { mode: 'cors', credentials: 'omit' });
}

async function zCdn(req) {
    const cache = await caches.open(CACHE_NAME);
    const ulozene = await cache.match(req.url, { ignoreVary: true });
    if (ulozene) return ulozene;
    try {
        const res = await fetch(cdnPoziadavka(req.url));
        if (res.ok) {
            await cache.put(req.url, res.clone());
        }
        return res;
    } catch (e) {
        return fetch(req);
    }
}

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
