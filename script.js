/**
 * script.js — prepínanie svetlej/tmavej témy a klientská logika okolo
 * modalu (pridanie AJ úprava receptu), "uvarené dnes" a mazania.
 */

/* ========================================================================
   PWA — registrácia service workera (viď sw.js). Cesta je relatívna,
   nech appka funguje aj keď nebeží priamo v koreni domény.
   ======================================================================== */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js').catch(() => {
            // Ticho ignorujeme — appka funguje aj bez service workera,
            // len nepôjde nainštalovať ako PWA / offline fallback nebude dostupný.
        });
    });
    // Nový service worker (prvá návšteva alebo aktualizácia appky) prevzal stránku:
    // požiadame ho, nech si hneď uloží kópiu receptov pre offline režim.
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!document.documentElement.dataset.offline) {
            navigator.serviceWorker.controller?.postMessage('uloz-stranku');
        }
    });
}

/* ========================================================================
   Push notifikácie — požiadanie o súhlas a registrácia subscription.
   Zavolaj enablePushNotifications() z tlačidla "Povoliť notifikácie".
   ======================================================================== */
// Verejný VAPID kľúč dodáva index.php z includes/.env (window.APP_VAPID_PUBLIC_KEY),
// takže pri výmene kľúčov sa mení len .env, nie tento súbor.
const VAPID_PUBLIC_KEY = window.APP_VAPID_PUBLIC_KEY || '';

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    return Uint8Array.from([...rawData].map(char => char.charCodeAt(0)));
}

function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); // iPad s "desktop" režimom
}

function isStandalonePWA() {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
}

async function isBrave() {
    try { return !!(navigator.brave && await navigator.brave.isBrave()); } catch (e) { return false; }
}

/** Zobrazí vysvetlenie v modálnom okne (dlhší text sa do toastu nezmestí). */
function showPushHelp(title, html) {
    const modalEl = document.getElementById('pushHelpModal');
    if (!modalEl) { alert(title + '\n\n' + html.replace(/<[^>]+>/g, '')); return; }
    document.getElementById('pushHelpTitle').textContent = title;
    document.getElementById('pushHelpBody').innerHTML = html;
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

async function enablePushNotifications() {
    try {
        const hasPushApi = ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);

        if (!hasPushApi) {
            if (isIOS() && !isStandalonePWA()) {
                showPushHelp('Najprv pridaj appku na plochu',
                    '<p>Na iPhone a iPade fungujú notifikácie <b>len v appke pridanej na plochu</b> (iOS 16.4 alebo novší), nie v bežnej karte prehliadača.</p>' +
                    '<ol class="mb-2"><li>V Safari klikni dole na tlačidlo <b>Zdieľať</b> <i class="bi bi-box-arrow-up"></i>.</li>' +
                    '<li>Zvoľ <b>Pridať na plochu</b>.</li>' +
                    '<li>Appku otvor z ikony na ploche a klikni znova na <b>Notifikácie</b>.</li></ol>' +
                    '<p class="small text-secondary mb-0">Ak máš iOS starší než 16.4, notifikácie na tomto zariadení nie sú dostupné.</p>');
            } else if (isIOS()) {
                showPushHelp('Notifikácie nie sú dostupné',
                    '<p>Tento iPhone/iPad web notifikácie nepodporuje. Potrebný je <b>iOS 16.4 alebo novší</b> — skontroluj aktualizácie v Nastavenia → Všeobecné → Aktualizácia softvéru.</p>');
            } else {
                showToast('Tvoj prehliadač nepodporuje push notifikácie.', true);
            }
            return;
        }

        if (!VAPID_PUBLIC_KEY) {
            showToast('Chýba verejný VAPID kľúč (includes/.env).', true);
            return;
        }

        // Na iOS musí byť requestPermission() zavolané priamo z kliknutia — je to prvý await.
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            if (permission === 'denied') {
                showPushHelp('Notifikácie sú zablokované',
                    '<p>Notifikácie pre túto stránku si v minulosti zamietol, takže sa prehliadač už nepýta znova.</p>' +
                    '<p class="mb-0"><b>PC:</b> klikni na zámok vedľa adresy → Notifikácie → Povoliť, a stránku obnov.<br>' +
                    '<b>iPhone:</b> Nastavenia → Notifikácie → Recepty → Povoliť notifikácie.</p>');
            } else {
                showToast('Notifikácie neboli povolené.', true);
            }
            return;
        }

        const registration = await navigator.serviceWorker.ready;
        let subscription;
        try {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY),
            });
        } catch (subErr) {
            if (await isBrave()) {
                showPushHelp('V Brave treba zapnúť push',
                    '<p>Brave má push notifikácie v predvolenom nastavení vypnuté.</p>' +
                    '<ol class="mb-2"><li>Do adresného riadku napíš <code>brave://settings/privacy</code>.</li>' +
                    '<li>Zapni <b>Use Google services for push messaging</b> (Používať služby Google na push správy).</li>' +
                    '<li>Reštartuj Brave a klikni znova na <b>Notifikácie</b>.</li></ol>');
                return;
            }
            throw subErr;
        }

        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        const formData = new FormData();
        formData.append('action', 'save_push_subscription');
        formData.append('subscription', JSON.stringify(subscription));
        formData.append('csrf_token', csrfToken);

        const res = await fetch('index.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            showToast('Notifikácie boli povolené!');
        } else {
            showToast(data.error || 'Nepodarilo sa uložiť notifikácie.', true);
        }
    } catch (err) {
        console.error(err);
        showPushHelp('Notifikácie sa nepodarilo zapnúť',
            '<p class="mb-0">Chyba: <code>' + String(err.name || '') + ' — ' + String(err.message || err).replace(/</g, '&lt;') + '</code></p>');
    }
}

/* Prepínanie témy (dark/light) je v theme.js — spoločné pre všetky stránky. */

/* ========================================================================
   Malý toast helper (Bootstrap Toast) na spätnú väzbu po AJAX akciách.
   ======================================================================== */
function showToast(message, isError = false, action = null) {
    const toastEl = document.getElementById('appToast');
    const bodyEl = document.getElementById('appToastBody');
    if (!toastEl || !bodyEl) { alert(message); return; }

    bodyEl.textContent = message;
    toastEl.classList.remove('text-bg-success', 'text-bg-danger');
    toastEl.classList.add(isError ? 'text-bg-danger' : 'text-bg-success');

    // Staré oznámenie zahodíme, aby sa nové zobrazilo s vlastným časom.
    bootstrap.Toast.getInstance(toastEl)?.dispose();
    const toast = new bootstrap.Toast(toastEl, { delay: action ? 7000 : 3500 });

    // Voliteľné tlačidlo priamo v oznámení: action = { label, onClick }
    if (action) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-light btn-sm fw-semibold toast-action';
        button.textContent = action.label;
        button.addEventListener('click', () => {
            toast.hide();
            action.onClick();
        });
        bodyEl.appendChild(button);
    }

    toast.show();
}

/* ========================================================================
   Offline režim — recepty sa dajú pozerať aj bez internetu.

   sw.js pri výpadku servera ukáže uloženú kópiu stránky a označí ju
   <html data-offline="kopia" data-offline-ulozene="čas uloženia">. Keď sa
   pripojenie stratí počas používania, nastavíme data-offline="bez-siete".
   V oboch prípadoch tlačidlá s atribútom data-vyzaduje-siet (pridanie,
   úprava, Uvarené, hodnotenie, nákup…) namiesto akcie ukážu vysvetlenie.
   V uloženej kópii ostanú zablokované aj po návrate internetu, kým sa
   stránka nenačíta znova — jej bezpečnostný token (CSRF) môže byť neplatný.
   ======================================================================== */
const OfflineRezim = (() => {
    const html = document.documentElement;
    const jeKopia = () => html.dataset.offline === 'kopia';
    const jeAktivny = () => Boolean(html.dataset.offline);
    let internetSpat = false; // v kópii: prišla udalosť 'online', stránku treba načítať znova

    // Náhrada za fotku, ktorá nie je uložená v zariadení (priehľadná, ladí s oboma motívmi).
    const NAHRADA_FOTKY = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300">' +
        '<g fill="none" stroke="#d97706" stroke-width="10" stroke-linecap="round" stroke-linejoin="round">' +
        '<rect x="150" y="80" width="100" height="78" rx="12"/><circle cx="200" cy="119" r="20"/>' +
        '<path d="M138 70l124 98"/></g>' +
        '<text x="200" y="215" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,sans-serif" ' +
        'font-size="22" font-weight="600" fill="#d97706">Fotka nie je uložená v zariadení</text></svg>');

    /** Kedy bola kópia uložená: „dnes o 21:30", „včera o 8:05", „12. 10. o 18:00". */
    function casUlozenia() {
        const datum = new Date(html.dataset.offlineUlozene || '');
        if (isNaN(datum.getTime())) return '';
        const cas = datum.getHours() + ':' + String(datum.getMinutes()).padStart(2, '0');
        const den = new Date(datum);
        den.setHours(0, 0, 0, 0);
        const dnes = new Date();
        dnes.setHours(0, 0, 0, 0);
        const dniDozadu = Math.round((dnes - den) / 86400000);
        if (dniDozadu === 0) return 'dnes o ' + cas;
        if (dniDozadu === 1) return 'včera o ' + cas;
        const rok = datum.getFullYear() !== dnes.getFullYear() ? ' ' + datum.getFullYear() : '';
        return datum.getDate() + '. ' + (datum.getMonth() + 1) + '.' + rok + ' o ' + cas;
    }

    function aktualizujPasik() {
        const pasik = document.getElementById('offlinePasik');
        if (!pasik || !jeAktivny()) return;
        const nadpis = document.getElementById('offlinePasikNadpis');
        const popis = document.getElementById('offlinePasikPopis');
        const tlacidloText = document.getElementById('offlinePasikTlacidloText');
        const ikona = pasik.querySelector('.offline-pasik-ikona');
        const spat = jeKopia() && internetSpat;

        pasik.classList.toggle('je-online', spat);
        ikona?.classList.toggle('bi-wifi-off', !spat);
        ikona?.classList.toggle('bi-wifi', spat);

        if (spat) {
            nadpis.textContent = 'Internet je späť';
            popis.textContent = 'Načítaj aktuálne recepty, potom môžeš znova pridávať a upravovať.';
            tlacidloText.textContent = 'Načítať aktuálne recepty';
        } else if (jeKopia()) {
            const kedy = casUlozenia();
            nadpis.textContent = 'Bez pripojenia';
            popis.textContent = 'Pozeráš recepty uložené v zariadení' + (kedy ? ' (' + kedy + ')' : '') +
                '. Pridávať a upravovať sa dá, až keď bude internet.';
            tlacidloText.textContent = 'Skúsiť znova';
        } else {
            nadpis.textContent = 'Bez pripojenia';
            popis.textContent = 'Recepty si môžeš pozerať ďalej. Pridávať a upravovať sa dá, až keď sa pripojenie vráti.';
        }
    }

    // Klik na tlačidlo, ktoré potrebuje server, zachytíme ešte pred jeho vlastnou akciou
    // (fáza capture na dokumente). Formuláre offline neodošleme vôbec.
    function zablokuj(event) {
        if (!jeAktivny()) return;
        if (event.type === 'click') {
            if (!(event.target instanceof Element) || !event.target.closest('[data-vyzaduje-siet]')) return;
        }
        event.preventDefault();
        event.stopPropagation();
        showToast(jeKopia() && internetSpat
            ? 'Internet je späť. Najprv ťukni hore na „Načítať aktuálne recepty“.'
            : 'Bez internetu sa to nedá. Recepty si teraz môžeš len pozerať.', true);
    }
    document.addEventListener('click', zablokuj, true);
    document.addEventListener('submit', zablokuj, true);

    function nahradFotku(img) {
        if (!jeAktivny() || img.dataset.offlineNahrada || !img.getAttribute('src')) return;
        img.dataset.offlineNahrada = '1';
        img.removeAttribute('srcset');
        img.src = NAHRADA_FOTKY;
        img.alt = 'Fotka nie je uložená v zariadení';
    }
    document.addEventListener('error', (event) => {
        if (event.target instanceof HTMLImageElement) nahradFotku(event.target);
    }, true);
    // Fotky, ktoré zlyhali skôr, než sa načítal tento skript (zachytil ich skript v hlavičke index.php).
    (window.APP_CHYBNE_FOTKY || []).forEach(nahradFotku);

    window.addEventListener('online', () => {
        if (html.dataset.offline === 'bez-siete') {
            delete html.dataset.offline;
            showToast('Pripojenie je späť.');
        } else if (jeKopia()) {
            internetSpat = true;
        }
        aktualizujPasik();
    });
    window.addEventListener('offline', () => {
        internetSpat = false;
        if (!jeKopia()) html.dataset.offline = 'bez-siete';
        aktualizujPasik();
    });

    document.getElementById('offlinePasikTlacidlo')?.addEventListener('click', () => location.reload());
    aktualizujPasik();

    // Pri obnovení stránky prehliadač vráti pozíciu podľa hornej lišty, takže pásik
    // (ktorý online stránka nemala) ostane nad okrajom obrazovky. Kto bol hore,
    // vráti sa hore, aby pásik videl. Hlbšie odscrollovanú pozíciu ani vlastné
    // posúvanie používateľa nemeníme. Sledujeme len chvíľu po načítaní.
    if (jeKopia()) {
        let posuvalPouzivatel = false;
        ['wheel', 'touchstart', 'keydown', 'pointerdown'].forEach((typ) =>
            window.addEventListener(typ, () => { posuvalPouzivatel = true; }, { once: true, passive: true }));
        const ukazPasik = () => {
            const pasik = document.getElementById('offlinePasik');
            if (posuvalPouzivatel || !pasik) return;
            // Pásik je (aspoň čiastočne) nad obrazovkou, ale stránka nie je posunutá oveľa ďalej.
            const r = pasik.getBoundingClientRect();
            if (r.top < 0 && r.bottom > -60) {
                window.scrollTo(0, 0);
            }
        };
        window.addEventListener('scroll', ukazPasik, { passive: true });
        window.addEventListener('load', () => {
            ukazPasik();
            setTimeout(() => window.removeEventListener('scroll', ukazPasik), 1500);
        });
    }

    return { jeAktivny, jeKopia };
})();

/* ========================================================================
   Drobné spoločné pomôcky: dátumy, escapovanie, odoslanie akcie na server.
   ======================================================================== */

/** Dnešný dátum v tvare 2026-10-05 (pre polia typu date). */
function todayISO() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

/** 2026-10-05 → „5. 10. 2026". */
function formatSkDate(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
    return m ? Number(m[3]) + '. ' + Number(m[2]) + '. ' + m[1] : '';
}

function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function csrfToken() {
    return document.querySelector('input[name="csrf_token"]')?.value || '';
}

/** POST akcia na index.php s CSRF tokenom, vráti JSON odpoveď. */
async function appPost(action, params) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('csrf_token', csrfToken());
    Object.entries(params || {}).forEach(([key, value]) => formData.append(key, value));
    const res = await fetch('index.php', { method: 'POST', body: formData });
    return res.json();
}

/**
 * Výber fotky s náhľadom a tlačidlom na zrušenie výberu (formulár receptu
 * aj okno „Uvarené"). onChange(hasFile) sa volá pri každej zmene.
 */
function bindPhotoPicker({ input, wrap, img, clearBtn, onChange }) {
    let objectUrl = null;

    function show(file) {
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }
        if (file) {
            objectUrl = URL.createObjectURL(file);
            img.src = objectUrl;
        } else {
            img.removeAttribute('src');
        }
        wrap.classList.toggle('d-none', !file);
        if (onChange) onChange(!!file);
    }

    input.addEventListener('change', () => show(input.files && input.files[0] ? input.files[0] : null));
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            input.value = '';
            show(null);
            input.focus();
        });
    }

    return {
        reset() {
            input.value = '';
            show(null);
        },
        file: () => (input.files && input.files[0]) || null,
    };
}

/** Fotky receptu od najnovšej. Bez galérie (stará databáza) aspoň titulná fotka bez dátumu. */
function recipePhotoList(data) {
    if (data && Array.isArray(data.photos) && data.photos.length) return data.photos;
    return data && data.imagePath ? [{ id: null, path: data.imagePath, date: null, by: '' }] : [];
}

/** Telefón (rozloženie pod 768 px) — podľa toho istého zlomu ako CSS. */
function jeTelefon() {
    return window.matchMedia('(max-width: 767.98px)').matches;
}

/* ========================================================================
   Suroviny vo formulári receptu — vyhľadávacie pole pridáva riadky do
   zoznamu; každý riadok má nepovinné množstvo, jednotku a poznámku.
   Pri odoslaní sa zoznam zapíše ako JSON do skrytého poľa suroviny_json.
   Na telefóne (verzia 1.9) je riadok zbalený na názov a množstvo, polia
   sa ukážu ceruzkou (Upraviť) a zbalia tlačidlom Hotovo.
   ======================================================================== */
const surovinyPicker = (function initSurovinyPicker() {
    const field = document.getElementById('surovinyField');
    if (!field || !window.Suroviny) return null;

    const S = window.Suroviny;
    const input = document.getElementById('surovinaSearch');
    const list = document.getElementById('surovinyList');
    const hidden = field.querySelector('input[name="suroviny_json"]');
    const jednotky = window.APP_JEDNOTKY || [];

    function rowElements() {
        return Array.from(list.querySelectorAll('.surovina-row'));
    }

    function hasKey(key) {
        return rowElements().some((el) => el.dataset.key === key);
    }

    function ikonaTlacidlo(trieda, ikona, popis) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = trieda;
        btn.setAttribute('aria-label', popis);
        btn.innerHTML = '<i class="bi ' + ikona + '" aria-hidden="true"></i>';
        return btn;
    }

    /** Zbalený riadok na telefóne: „300 g · nastrúhané", alebo „bez množstva". */
    function suhrnRiadka(el) {
        const polia = S.readDetailFields(el);
        return [S.formatDavka(polia), polia.poznamka].filter(Boolean).join(' · ') || 'bez množstva';
    }

    function zbalRiadky(okrem) {
        rowElements().forEach((el) => {
            if (el !== okrem && el.classList.contains('je-upravovana')) zbal(el);
        });
    }

    function zbal(el) {
        el.classList.remove('je-upravovana');
        el.querySelector('.surovina-row-suhrn').textContent = suhrnRiadka(el);
        el.querySelector('.surovina-row-upravit').setAttribute('aria-expanded', 'false');
    }

    function rozbal(el) {
        zbalRiadky(el);
        el.classList.add('je-upravovana');
        el.querySelector('.surovina-row-upravit').setAttribute('aria-expanded', 'true');
        el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        el.querySelector('.sur-mnozstvo').focus({ preventScroll: true });
    }

    function vytvorRiadok(row) {
        const nazov = S.displayName(row.nazov);
        const key = S.normKey(nazov);

        const el = document.createElement('div');
        el.className = 'surovina-row' + (row.id ? '' : ' je-nova');
        el.dataset.id = row.id || '';
        el.dataset.nazov = nazov;
        el.dataset.key = key;

        const head = document.createElement('div');
        head.className = 'surovina-row-head';

        const name = document.createElement('span');
        name.className = 'surovina-row-name';
        name.textContent = nazov;
        head.appendChild(name);

        if (!row.id) {
            const badge = document.createElement('span');
            badge.className = 'badge text-bg-warning';
            badge.textContent = 'nová';
            head.appendChild(badge);
        }

        // Ceruzka len na telefóne (na PC sú polia stále viditeľné).
        const upravit = ikonaTlacidlo('surovina-row-upravit d-md-none', 'bi-pencil', 'Upraviť množstvo: ' + nazov);
        upravit.setAttribute('aria-expanded', 'false');
        upravit.addEventListener('click', () => (el.classList.contains('je-upravovana') ? zbal(el) : rozbal(el)));
        head.appendChild(upravit);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm surovina-row-remove';
        remove.setAttribute('aria-label', 'Odstrániť surovinu ' + nazov);
        remove.title = 'Odstrániť';
        remove.innerHTML = '<i class="bi bi-x-lg"></i>';
        remove.addEventListener('click', () => {
            el.remove();
            // Na telefóne by fokus vyskočil klávesnicu, preto len na PC.
            if (!jeTelefon()) input.focus();
        });
        head.appendChild(remove);
        el.appendChild(head);

        const suhrn = document.createElement('div');
        suhrn.className = 'surovina-row-suhrn d-md-none';
        el.appendChild(suhrn);

        // Recept z fotky (AI): surovina nie je v číselníku, ale podobná áno.
        if (row.navrh && row.navrh.nazov) {
            const navrh = document.createElement('button');
            navrh.type = 'button';
            navrh.className = 'surovina-row-navrh';
            navrh.textContent = 'Nemysleli ste ' + row.navrh.nazov + '?';
            navrh.addEventListener('click', () => {
                const novy = Object.assign({}, row, S.readDetailFields(el), { id: row.navrh.id, nazov: row.navrh.nazov, navrh: null });
                // Ak už rovnaká surovina v zozname je, druhú netreba.
                const novyKluc = S.normKey(S.displayName(novy.nazov));
                rowElements().forEach((ine) => { if (ine !== el && ine.dataset.key === novyKluc) ine.remove(); });
                el.replaceWith(vytvorRiadok(novy));
            });
            el.appendChild(navrh);
        }

        const polia = S.createDetailFields(row, jednotky, nazov);
        const hotovo = document.createElement('button');
        hotovo.type = 'button';
        hotovo.className = 'btn btn-primary surovina-row-hotovo d-md-none';
        hotovo.textContent = 'Hotovo';
        hotovo.addEventListener('click', () => {
            zbal(el);
            upravit.focus({ preventScroll: true });
        });
        polia.appendChild(hotovo);
        polia.addEventListener('input', () => { suhrn.textContent = suhrnRiadka(el); });
        polia.addEventListener('change', () => { suhrn.textContent = suhrnRiadka(el); });
        // Enter v poli množstva/poznámky na telefóne = Hotovo (nie odoslanie formulára).
        polia.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && jeTelefon() && e.target.tagName === 'INPUT') {
                e.preventDefault();
                hotovo.click();
            }
        });
        el.appendChild(polia);
        suhrn.textContent = suhrnRiadka(el);
        return el;
    }

    function addRow(row) {
        const key = S.normKey(S.displayName(row.nazov));
        if (!key || hasKey(key)) return null;
        const el = vytvorRiadok(row);
        list.appendChild(el);
        return el;
    }

    function getRows() {
        return rowElements().map((el) => Object.assign(
            { id: el.dataset.id ? Number(el.dataset.id) : null, nazov: el.dataset.nazov },
            S.readDetailFields(el)
        ));
    }

    S.attachCombobox(input, {
        items: window.APP_SUROVINY || [],
        isTaken: (item) => hasKey(item.key),
        onPick: (item) => {
            const el = addRow({ id: item.id, nazov: item.nazov });
            input.value = '';
            // Telefón: nová surovina sa rovno otvorí na zadanie množstva; PC: pokračuje sa v hľadaní.
            if (el && jeTelefon()) rozbal(el);
            else input.focus();
        },
    });

    return {
        setRows(rows) {
            list.replaceChildren();
            input.value = '';
            (rows || []).forEach(addRow);
        },
        getRows,
        /** Text rozpísaný vo vyhľadávacom poli, ktorý ešte nebol pridaný do zoznamu. */
        pendingText: () => input.value.trim(),
        focusSearch: () => input.focus(),
        sync() { hidden.value = JSON.stringify(getRows()); },
    };
})();

/* ========================================================================
   Nákupný zoznam — spoločný pre všetkých používateľov. Zoznam sa načíta zo
   servera pri každom otvorení a každá akcia vráti jeho čerstvý stav.
   ======================================================================== */
const shopping = (function initShopping() {
    const modalEl = document.getElementById('shoppingModal');
    if (!modalEl || !window.Suroviny) return null;

    const S = window.Suroviny;
    const listEl = document.getElementById('shoppingList');
    const countEl = document.getElementById('shoppingCount');
    const input = document.getElementById('shoppingAddInput');
    let items = [];

    // Obnovovanie zoznamu, aby bolo vidno aj zmeny z iných zariadení (napr. dvaja
    // nakupujú naraz). Kým je okno Nákup otvorené a appka na obrazovke, zoznam sa
    // stiahne každých OBNOVA_MS. Pri návrate do appky alebo internetu hneď.
    // Na pozadí sa nerobí nič, nemíňa to batériu ani dáta.
    const OBNOVA_MS = 5000;
    let casovac = null;
    let poradie = 0;        // poradové číslo každej požiadavky
    let aplikovane = 0;     // poradie poslednej odpovede, ktorá sa ukázala
    let generacia = 0;      // zvýši sa pri každej zmene od používateľa (odškrtnutie, pridanie…)
    let akcieBezia = 0;     // zmeny, na ktorých odpoveď sa ešte čaká
    let prihlasenieVyprsalo = false;
    // Stav okna podľa udalostí Bootstrapu — trieda 'show' pribudne až po animácii,
    // čiže často neskôr, než príde prvá odpoveď zo servera.
    let otvorene = false;

    const oknoOtvorene = () => otvorene;
    const mozeObnovovat = () => !prihlasenieVyprsalo && document.visibilityState === 'visible'
        && navigator.onLine !== false && !OfflineRezim.jeAktivny();

    function naplanujObnovu() {
        clearTimeout(casovac);
        casovac = null;
        if (oknoOtvorene() && mozeObnovovat()) {
            casovac = setTimeout(async () => {
                await obnov();
                naplanujObnovu();
            }, OBNOVA_MS);
        }
    }

    /** Tiché načítanie zoznamu zo servera (bez hlásenia chýb pripojenia). */
    function obnov() {
        if (!mozeObnovovat()) return Promise.resolve();
        return call('shopping_get', null, { ticho: true });
    }

    // Návrat do appky (prepnutie z inej appky, odomknutie telefónu) alebo návrat internetu:
    // obnovíme hneď, aj odznak s počtom, keď okno nie je otvorené.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            obnov().then(naplanujObnovu);
        } else {
            naplanujObnovu(); // zastaví obnovovanie
        }
    });
    window.addEventListener('online', () => setTimeout(() => obnov().then(naplanujObnovu), 500));
    modalEl.addEventListener('show.bs.modal', () => { otvorene = true; });
    modalEl.addEventListener('hidden.bs.modal', () => { otvorene = false; naplanujObnovu(); });

    function openModal() {
        const show = () => {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            call('shopping_get').then(naplanujObnovu);
        };
        // Ak je otvorené iné okno (napr. náhľad receptu), najprv ho zavrieme —
        // dve okná naraz Bootstrap nezvláda.
        const other = Array.from(document.querySelectorAll('.modal.show')).find((el) => el !== modalEl);
        if (other) {
            other.addEventListener('hidden.bs.modal', show, { once: true });
            bootstrap.Modal.getInstance(other)?.hide();
        } else {
            show();
        }
    }

    /**
     * Odoslanie akcie na server. Každá odpoveď obsahuje celý aktuálny zoznam.
     * Odpoveď sa ukáže, len ak je novšia než posledná ukázaná. Obnova (shopping_get),
     * počas ktorej používateľ niečo zmenil, sa zahodí — inak by napr. práve
     * odškrtnutá položka na chvíľu skočila späť.
     * opts.ticho: pri obnove nehlásime chyby pripojenia (skúsi sa o chvíľu znova).
     */
    async function call(action, params, opts = {}) {
        const jeObnova = action === 'shopping_get';
        const moje = ++poradie;
        const gen = jeObnova ? generacia : ++generacia;
        if (!jeObnova) akcieBezia++;

        const formData = new FormData();
        formData.append('action', action);
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
        Object.entries(params || {}).forEach(([key, value]) => formData.append(key, value));
        try {
            const res = await fetch('index.php', { method: 'POST', body: formData });
            // Vypršané prihlásenie: server presmeruje na prihlasovaciu stránku namiesto JSON.
            const jeJson = (res.headers.get('Content-Type') || '').includes('application/json');
            if ((res.redirected && /login\.php/.test(res.url)) || !jeJson) {
                vyprsaloPrihlasenie(opts.ticho);
                return { success: false };
            }
            const data = await res.json();
            if (res.status === 403 && jeObnova) {
                // Neplatný bezpečnostný token (napr. prihlásenie v inom okne) — stránku treba obnoviť.
                vyprsaloPrihlasenie(opts.ticho);
                return data;
            }
            const aktualna = moje > aplikovane && (!jeObnova || (gen === generacia && akcieBezia === (jeObnova ? 0 : 1)));
            if (data.items && aktualna) {
                aplikovane = moje;
                nastavPolozky(data.items);
            }
            if (!data.success && !(jeObnova && opts.ticho)) showToast(data.error || 'Akcia zlyhala.', true);
            return data;
        } catch (err) {
            if (!opts.ticho) showToast('Chyba pripojenia k serveru.', true);
            return { success: false };
        } finally {
            if (!jeObnova) akcieBezia--;
        }
    }

    /** Prekreslí zoznam, len ak sa naozaj zmenil (inak by blikal pri každej obnove). */
    function nastavPolozky(nove) {
        if (JSON.stringify(nove) === JSON.stringify(items) && listEl.childElementCount) return;
        items = nove;
        render();
    }

    /** Automatická obnova to oznámi len raz, akcia používateľa (ťuknutie) vždy. */
    function vyprsaloPrihlasenie(ticho) {
        if (prihlasenieVyprsalo && ticho) return;
        prihlasenieVyprsalo = true;
        naplanujObnovu();
        showToast('Prihlásenie vypršalo. Obnov stránku, aby sa nákupný zoznam znova aktualizoval.', true,
            { label: 'Obnoviť stránku', onClick: () => location.reload() });
    }

    /**
     * „1,5 kg Múka hladká", „1½ ČL Soľ" — množstvo v najvhodnejšej jednotke
     * (Suroviny.prevedDavku). Kusy sa zaokrúhľujú nahor, aby sa nekúpilo menej.
     */
    function itemLabel(item) {
        const davka = S.prevedDavku(item.mnozstvo, item.jednotka, { nakup: true }).text;
        return { davka, text: (davka ? davka + ' ' : '') + item.nazov };
    }

    function itemRow(item) {
        const row = document.createElement('div');
        row.className = 'shopping-item' + (item.kupene ? ' is-bought' : '');
        row.dataset.itemId = item.id;

        const label = document.createElement('label');
        label.className = 'shopping-item-label';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'form-check-input shopping-check';
        checkbox.checked = item.kupene;
        checkbox.addEventListener('change', () => {
            row.classList.toggle('is-bought', checkbox.checked);
            call('shopping_toggle', { item_id: item.id, kupene: checkbox.checked ? 1 : 0 });
        });

        const text = document.createElement('span');
        text.className = 'shopping-item-text';
        const { davka } = itemLabel(item);
        if (davka) {
            const qty = document.createElement('span');
            qty.className = 'shopping-item-qty';
            qty.textContent = davka + ' ';
            text.appendChild(qty);
        }
        text.appendChild(document.createTextNode(item.nazov));
        if (item.zdroj && !item.kupene) {
            const zdroj = document.createElement('span');
            zdroj.className = 'shopping-item-zdroj';
            zdroj.textContent = item.zdroj;
            text.appendChild(zdroj);
        }
        label.append(checkbox, text);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm surovina-row-remove';
        remove.title = 'Odstrániť zo zoznamu';
        remove.setAttribute('aria-label', 'Odstrániť zo zoznamu: ' + item.nazov);
        remove.innerHTML = '<i class="bi bi-x-lg"></i>';
        remove.addEventListener('click', () => call('shopping_delete', { item_id: item.id }));

        row.append(label, remove);
        return row;
    }

    function groupHeading(text) {
        const heading = document.createElement('div');
        heading.className = 'shopping-group';
        heading.textContent = text;
        return heading;
    }

    function render() {
        const open = items.filter((item) => !item.kupene);
        const bought = items.filter((item) => item.kupene);

        // Odznak pri Nákupnom zozname v bočnej ponuke (PC).
        if (countEl) {
            countEl.textContent = open.length;
            countEl.setAttribute('aria-label', 'Položiek na kúpenie: ' + open.length);
            countEl.classList.toggle('d-none', open.length === 0);
        }
        // Odznak pri Nákupe v spodnej lište (telefón).
        const listaPocetEl = document.getElementById('spodnaListaPocet');
        if (listaPocetEl) {
            listaPocetEl.textContent = open.length;
            listaPocetEl.classList.toggle('d-none', open.length === 0);
        }

        // Pri prekreslení (aj pri obnove z iného zariadenia) zachováme posun okna
        // a fokus na políčku tej istej položky.
        // Posúva sa celé okno (.modal), na menších dialógoch môže aj jeho telo.
        const posuvne = [modalEl, listEl.closest('.modal-body')].filter(Boolean);
        const posuny = posuvne.map((el) => el.scrollTop);
        const fokusId = listEl.contains(document.activeElement)
            ? document.activeElement.closest('[data-item-id]')?.dataset.itemId : null;
        const fokusTrieda = fokusId && document.activeElement.classList.contains('shopping-check') ? '.shopping-check' : 'button';
        try {
            vykresliZoznam(open, bought);
        } finally {
            posuvne.forEach((el, i) => { el.scrollTop = posuny[i]; });
            if (fokusId) listEl.querySelector('[data-item-id="' + fokusId + '"] ' + fokusTrieda)?.focus({ preventScroll: true });
        }
    }

    function vykresliZoznam(open, bought) {
        listEl.replaceChildren();
        if (!items.length) {
            const empty = document.createElement('p');
            empty.className = 'text-secondary small mb-0';
            empty.textContent = 'Zoznam je prázdny. Suroviny pridáš tlačidlom s košíkom pri recepte, alebo napíš položku do poľa hore.';
            listEl.appendChild(empty);
            return;
        }

        // Nekúpené podľa kategórie (poradie určuje server), aby sa v obchode išlo po oddeleniach.
        let lastGroup = null;
        open.forEach((item) => {
            const group = item.kategoria || 'Ostatné';
            if (group !== lastGroup) {
                listEl.appendChild(groupHeading(group));
                lastGroup = group;
            }
            listEl.appendChild(itemRow(item));
        });

        if (bought.length) {
            listEl.appendChild(groupHeading('Kúpené (' + bought.length + ')'));
            bought.forEach((item) => listEl.appendChild(itemRow(item)));
        }
    }

    S.attachCombobox(input, {
        items: window.APP_SUROVINY || [],
        newLabel: (nazov) => 'Pridať „' + nazov + '“ na zoznam',
        onPick: (item) => {
            input.value = '';
            input.focus();
            call('shopping_add_item', { surovina_id: item.id || '', nazov: item.nazov });
        },
    });

    /**
     * Názov poznámky v Keepe = recepty, z ktorých zoznam vznikol („Lievance, Chlieb").
     * Pri viac než troch receptoch sa zvyšok skráti; bez receptov ostáva „Nákupný zoznam".
     */
    function shareTitle(open) {
        const names = [];
        open.forEach((item) => (item.zdroj || '').split(',').forEach((part) => {
            const name = part.trim();
            if (name && !names.includes(name)) names.push(name);
        }));
        if (!names.length) return 'Nákupný zoznam';
        return names.length > 3
            ? names.slice(0, 3).join(', ') + ' a ďalšie (' + (names.length - 3) + ')'
            : names.join(', ');
    }

    document.getElementById('shoppingClearBought').addEventListener('click', () => call('shopping_clear', { mode: 'bought' }));
    document.getElementById('shoppingClearAll').addEventListener('click', () => {
        if (confirm('Naozaj vymazať celý nákupný zoznam?')) call('shopping_clear', { mode: 'all' });
    });

    // Zdieľanie: jedna položka na riadok bez odrážok — Google Keep z toho po zapnutí
    // začiarkavacích políčok spraví odškrtávací zoznam.
    document.getElementById('shoppingShareBtn').addEventListener('click', async () => {
        const open = items.filter((item) => !item.kupene);
        if (!open.length) {
            showToast('Na zozname nie je nič na kúpenie.', true);
            return;
        }
        const text = open.map((item) => itemLabel(item).text).join('\n');
        const title = shareTitle(open);
        if (navigator.share) {
            try {
                await navigator.share({ title, text });
            } catch (err) {
                if (err.name !== 'AbortError') showToast('Zdieľanie sa nepodarilo.', true);
            }
            return;
        }
        try {
            await navigator.clipboard.writeText(text);
            showToast('Zoznam je skopírovaný do schránky, vlož ho do Keepu.');
        } catch (err) {
            showToast('Zdieľanie nie je v tomto prehliadači podporované.', true);
        }
    });

    return {
        open: openModal,
        async addRecipe(recipeId, servings, button) {
            if (button) button.disabled = true;
            const data = await call('shopping_add_recipe', servings ? { recipe_id: recipeId, servings } : { recipe_id: recipeId });
            if (button) button.disabled = false;
            if (data.success) {
                showToast('Pridané do nákupu (' + data.added + ').', false, { label: 'Zobraziť zoznam', onClick: openModal });
            }
        },
    };
})();

function openShoppingModal() {
    if (shopping) shopping.open();
}

/* ========================================================================
   Objednané jedlo (verzia 2.2) — Wolt, Bolt Food… zvlášť od receptov.
   Od verzie 2.3 je to samostatná stránka na celú obrazovku namiesto receptov
   (#strankaObjednane), nie okno. Na stránke sú dve časti: zoznam a formulár.

   Ktorá stránka a časť sa ukazuje, určuje adresa:
     index.php                       → Recepty
     index.php#objednane             → Objednané jedlo, zoznam
     index.php#objednane-formular    → Objednané jedlo, formulár (pridať / upraviť)
   Každý prechod pridá záznam do histórie prehliadača, takže tlačidlo Späť
   (aj systémové na Androide) vráti z formulára na zoznam a zo zoznamu na recepty.
   V history.state.hlbka je, koľko takých záznamov je nad receptami.

   Zoznam je priamo v stránke (window.APP_OBJEDNAVKY), takže sa dá pozrieť
   aj bez internetu. Pri príchode na stránku sa potichu stiahne čerstvý zo
   servera a každá akcia (uloženie, zmazanie) vráti celý aktuálny zoznam.
   ======================================================================== */
const objednavky = (function initObjednavky() {
    const strankaEl = document.getElementById('strankaObjednane');
    if (!strankaEl) return null;

    const SLUZBY = window.APP_OBJEDNAVKY_SLUZBY || {};
    const MESIACE = ['Január', 'Február', 'Marec', 'Apríl', 'Máj', 'Jún', 'Júl', 'August', 'September', 'Október', 'November', 'December'];
    const DNI = ['nedeľa', 'pondelok', 'utorok', 'streda', 'štvrtok', 'piatok', 'sobota'];

    const $ = (id) => document.getElementById(id);
    const zoznamEl = $('objednavkyZoznam');
    const naposledyEl = $('objednavkyNaposledy');
    const hladajEl = $('objednavkyHladaj');
    const cipy = Array.from(strankaEl.querySelectorAll('.objednavky-cip'));
    const form = $('objednavkaForm');
    const chybaEl = $('objednavkaChyba');
    const tipyEl = $('objednavkaTipy');
    const zmazatBtn = $('objednavkaZmazat');

    let polozky = Array.isArray(window.APP_OBJEDNAVKY) ? window.APP_OBJEDNAVKY : [];
    let filterSluzba = '';
    let upravovana = null; // objednávka vo formulári (null = nová)
    let formularPripraveny = false; // formulár bol v tejto návšteve otvorený (inak #objednane-formular = zoznam)
    let strankaTeraz = 'recepty';

    /* ── Pomôcky na text a dátumy ── */
    const normalizuj = (text) => (window.Suroviny ? window.Suroviny.normKey(text) : String(text || '').toLowerCase());

    /** Počet dní medzi dvoma dátumami Y-m-d (bez vplyvu časového pásma). */
    function dniMedzi(od, po) {
        const utc = (iso) => {
            const [r, m, d] = iso.split('-').map(Number);
            return Date.UTC(r, m - 1, d);
        };
        return Math.round((utc(po) - utc(od)) / 86400000);
    }

    /** „dnes", „včera", „pred 5 dňami", „pred 3 mesiacmi", „pred rokom". */
    function predKolko(iso) {
        const dni = dniMedzi(iso, todayISO());
        if (dni <= 0) return 'dnes';
        if (dni === 1) return 'včera';
        if (dni <= 60) return 'pred ' + dni + ' dňami';
        if (dni < 365) return 'pred ' + Math.round(dni / 30) + ' mesiacmi';
        const roky = Math.floor(dni / 365);
        return roky === 1 ? 'pred rokom' : 'pred ' + roky + ' rokmi';
    }

    /** „sobota 7. 11." (rok len pri inom než tomto roku). */
    function denADatum(iso) {
        const [r, m, d] = iso.split('-').map(Number);
        const den = DNI[new Date(Date.UTC(r, m - 1, d)).getUTCDay()];
        return den + ' ' + d + '. ' + m + '.' + (r !== new Date().getFullYear() ? ' ' + r : '');
    }

    const formatCena = (cena) => cena.toFixed(2).replace('.', ',') + ' €';
    const pocetObjednavok = (n) => n + ' ' + (n === 1 ? 'objednávka' : (n >= 2 && n <= 4 ? 'objednávky' : 'objednávok'));
    const nazovSluzby = (kluc) => SLUZBY[kluc] || SLUZBY.ine || 'Iné';

    /** Farebný krúžok služby: „W", „B", „f", pri Iné ikona obchodu. */
    function znak(sluzba) {
        const el = document.createElement('span');
        el.className = 'objednavka-znak';
        el.dataset.sluzba = sluzba;
        el.setAttribute('aria-hidden', 'true');
        if (sluzba === 'ine' || !SLUZBY[sluzba]) el.innerHTML = '<i class="bi bi-shop"></i>';
        else el.textContent = nazovSluzby(sluzba).charAt(0);
        return el;
    }

    /** Hlavný názov objednávky: reštaurácia, inak prvý riadok toho, čo sme mali. */
    const nadpisPolozky = (o) => o.restauracia || (o.jedlo || '').split('\n')[0];

    /* ── Komunikácia so serverom ── */
    async function call(action, params, opts = {}) {
        const formData = params instanceof FormData ? params : new FormData();
        if (!(params instanceof FormData)) Object.entries(params || {}).forEach(([k, v]) => formData.append(k, v));
        formData.set('action', action);
        formData.set('csrf_token', csrfToken());
        try {
            const res = await fetch('index.php', { method: 'POST', body: formData });
            // Vypršané prihlásenie: server presmeruje na prihlasovaciu stránku namiesto JSON.
            if (!(res.headers.get('Content-Type') || '').includes('application/json')) {
                if (!opts.ticho) {
                    showToast('Prihlásenie vypršalo. Obnov stránku a skús to znova.', true,
                        { label: 'Obnoviť stránku', onClick: () => location.reload() });
                }
                return { success: false, error: '' };
            }
            const data = await res.json();
            if (Array.isArray(data.polozky)) {
                polozky = data.polozky;
                vykresli();
            }
            return data;
        } catch (err) {
            if (!opts.ticho) showToast('Chyba pripojenia k serveru.', true);
            return { success: false, error: '' };
        }
    }

    /* ── Zoznam ── */
    function vyfiltrovane() {
        const hladane = normalizuj(hladajEl.value).trim();
        return polozky.filter((o) => (!filterSluzba || o.sluzba === filterSluzba)
            && (!hladane || normalizuj(o.restauracia + ' ' + o.jedlo).includes(hladane)));
    }

    function vykresliNaposledy(zobrazene) {
        const hladane = hladajEl.value.trim();
        if (!polozky.length) {
            naposledyEl.classList.add('d-none');
            return;
        }
        naposledyEl.classList.remove('d-none');
        naposledyEl.replaceChildren();

        const ikona = document.createElement('span');
        ikona.className = 'objednavky-naposledy-ikona';
        ikona.setAttribute('aria-hidden', 'true');
        ikona.innerHTML = '<i class="bi bi-clock-history"></i>';
        const text = document.createElement('div');
        text.className = 'min-w-0';
        const popis = document.createElement('div');
        popis.className = 'objednavky-naposledy-popis';
        popis.textContent = hladane ? 'Naposledy „' + hladane + '“'
            : filterSluzba ? 'Naposledy cez ' + nazovSluzby(filterSluzba) : 'Naposledy objednané';
        const kedy = document.createElement('div');
        kedy.className = 'objednavky-naposledy-kedy';
        const co = document.createElement('div');
        co.className = 'objednavky-naposledy-co';

        const prva = zobrazene[0];
        if (prva) {
            kedy.textContent = predKolko(prva.datum);
            co.textContent = [nadpisPolozky(prva), nazovSluzby(prva.sluzba), denADatum(prva.datum)].filter(Boolean).join(' · ');
        } else {
            kedy.textContent = 'Nič sme nenašli';
            co.textContent = 'Skús iné slovo alebo ťukni na Všetko.';
        }
        text.append(popis, kedy, co);
        naposledyEl.append(ikona, text);
    }

    function riadok(o) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'objednavka';
        btn.dataset.id = o.id;

        const text = document.createElement('span');
        text.className = 'objednavka-text';
        const nazov = document.createElement('span');
        nazov.className = 'objednavka-nazov';
        nazov.textContent = nadpisPolozky(o);
        text.appendChild(nazov);

        // Čo sme mali (ak nie je už v nadpise namiesto reštaurácie)
        if (o.restauracia && o.jedlo) {
            const jedlo = document.createElement('span');
            jedlo.className = 'objednavka-jedlo';
            jedlo.textContent = o.jedlo;
            text.appendChild(jedlo);
        }

        const meta = document.createElement('span');
        meta.className = 'objednavka-meta';
        meta.textContent = [denADatum(o.datum), predKolko(o.datum), nazovSluzby(o.sluzba),
            o.cena !== null ? formatCena(o.cena) : '', o.pridal].filter(Boolean).join(' · ');
        text.appendChild(meta);

        const sipka = document.createElement('i');
        sipka.className = 'bi bi-chevron-right objednavka-sipka';
        sipka.setAttribute('aria-hidden', 'true');

        btn.append(znak(o.sluzba), text, sipka);
        btn.setAttribute('aria-label', 'Upraviť: ' + meta.textContent.split(' · ')[0] + ', ' + nazov.textContent);
        btn.addEventListener('click', () => {
            if (OfflineRezim.jeAktivny()) {
                showToast('Bez internetu sa objednávka nedá upraviť. Teraz si ju môžeš len pozrieť.', true);
                return;
            }
            otvorFormular(o);
        });
        return btn;
    }

    function vykresli() {
        const zobrazene = vyfiltrovane();
        vykresliNaposledy(zobrazene);
        zoznamEl.replaceChildren();

        if (!polozky.length) {
            const prazdne = document.createElement('div');
            prazdne.className = 'objednavky-prazdne';
            prazdne.innerHTML = '<i class="bi bi-bag" aria-hidden="true"></i>' +
                '<p>Zatiaľ tu nič nie je.<br>Keď si niečo objednáte, zapíš to tlačidlom <b>Pridať objednávku</b>.</p>';
            zoznamEl.appendChild(prazdne);
            return;
        }

        // Po mesiacoch, pri mesiaci počet objednávok a súčet cien (ak sú zapísané).
        const mesiace = [];
        zobrazene.forEach((o) => {
            const kluc = o.datum.slice(0, 7);
            let mesiac = mesiace[mesiace.length - 1];
            if (!mesiac || mesiac.kluc !== kluc) {
                mesiac = { kluc, polozky: [] };
                mesiace.push(mesiac);
            }
            mesiac.polozky.push(o);
        });
        mesiace.forEach((mesiac) => {
            const [rok, m] = mesiac.kluc.split('-').map(Number);
            const sucet = mesiac.polozky.reduce((s, o) => s + (o.cena || 0), 0);
            const nadpis = document.createElement('div');
            nadpis.className = 'objednavky-mesiac';
            nadpis.textContent = MESIACE[m - 1] + ' ' + rok + ' · ' + pocetObjednavok(mesiac.polozky.length)
                + (sucet > 0 ? ' · ' + formatCena(sucet) : '');
            zoznamEl.appendChild(nadpis);
            mesiac.polozky.forEach((o) => zoznamEl.appendChild(riadok(o)));
        });
    }

    hladajEl.addEventListener('input', vykresli);
    cipy.forEach((cip) => cip.addEventListener('click', () => {
        filterSluzba = cip.dataset.sluzba;
        cipy.forEach((c) => {
            const aktivny = c === cip;
            c.classList.toggle('is-active', aktivny);
            c.setAttribute('aria-pressed', aktivny ? 'true' : 'false');
        });
        vykresli();
    }));

    /* ── Stránky a časti podľa adresy (pozri popis vyššie) ── */
    const hlbka = () => (history.state && history.state.hlbka) || 0;
    const adresaReceptov = () => location.pathname + location.search;

    function zobraz() {
        let stranka = 'recepty';
        let cast = 'zoznam';
        if (location.hash === '#objednane-formular' && formularPripraveny) {
            stranka = 'objednane';
            cast = 'formular';
        } else if (location.hash.startsWith('#objednane')) {
            stranka = 'objednane';
            // Formulár po obnovení stránky nemá čo ukázať — adresa sa vráti na zoznam.
            if (location.hash !== '#objednane') history.replaceState(history.state, '', '#objednane');
        }
        if (cast !== 'formular') {
            formularPripraveny = false;
            upravovana = null;
        }

        const prisiel = stranka === 'objednane' && strankaTeraz !== 'objednane';
        strankaTeraz = stranka;
        document.querySelectorAll('.stranka[data-stranka]').forEach((el) => { el.hidden = el.dataset.stranka !== stranka; });
        strankaEl.querySelectorAll('.objednavky-cast').forEach((el) => { el.hidden = el.dataset.cast !== cast; });
        // Bočná ponuka na PC: svieti stránka, na ktorej si.
        document.querySelectorAll('.app-bocna-polozka[data-stranka]').forEach((btn) => {
            const aktivna = btn.dataset.stranka === stranka;
            btn.classList.toggle('is-active', aktivna);
            if (aktivna) btn.setAttribute('aria-current', 'page');
            else btn.removeAttribute('aria-current');
        });
        document.dispatchEvent(new CustomEvent('stranka-zmenena', { detail: { stranka } }));
        window.scrollTo(0, 0);

        if (prisiel) {
            vykresli();
            // Čerstvý zoznam zo servera (zmeny od ostatných). Bez internetu ostane ten zo stránky.
            if (!OfflineRezim.jeAktivny() && navigator.onLine !== false) call('objednavky_get', null, { ticho: true });
        }
        if (cast === 'formular') {
            // Na PC rovno do poľa reštaurácie; na telefóne nie, klávesnica by zakryla polovicu obrazovky.
            if (jeTelefon()) $('objednavkaNadpis').focus({ preventScroll: true });
            else form.restauracia.focus({ preventScroll: true });
        }
    }
    window.addEventListener('popstate', zobraz);

    /** Prejde na stránku 'recepty' alebo 'objednane' (bočná ponuka, spodná lišta). */
    function prejdi(stranka) {
        if (stranka === 'objednane') {
            if (location.hash === '#objednane-formular' && hlbka() > 0) {
                history.back(); // z formulára späť na zoznam (zobrazí popstate)
                return;
            }
            if (location.hash !== '#objednane') {
                history.pushState({ hlbka: hlbka() + 1 }, '', '#objednane');
            } else {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        } else {
            if (!location.hash.startsWith('#objednane')) {
                window.scrollTo({ top: 0, behavior: 'smooth' }); // už si na receptoch
                return;
            }
            if (hlbka() > 0) {
                history.go(-hlbka()); // späť na recepty bez zbytočných záznamov v histórii
                return;
            }
            history.pushState({ hlbka: 0 }, '', adresaReceptov());
        }
        zobraz();
    }

    function otvorFormular(o = null) {
        upravovana = o;
        form.reset();
        chybaEl.classList.add('d-none');
        form.objednavka_id.value = o ? o.id : '';
        $('objednavkaNadpis').textContent = o ? 'Upraviť objednávku' : 'Nová objednávka';
        // Nová objednávka: služba podľa poslednej objednávky (väčšinou sa objednáva z tej istej).
        const sluzba = o ? o.sluzba : (polozky[0]?.sluzba || 'wolt');
        const radio = form.querySelector('input[name="sluzba"][value="' + sluzba + '"]')
            || form.querySelector('input[name="sluzba"][value="ine"]');
        if (radio) radio.checked = true;
        form.restauracia.value = o ? o.restauracia : '';
        form.jedlo.value = o ? o.jedlo : '';
        form.datum.value = o ? o.datum : todayISO();
        form.datum.max = todayISO();
        form.cena.value = o && o.cena !== null ? o.cena.toFixed(2).replace('.', ',') : '';
        zmazatBtn.classList.toggle('d-none', !(o && o.mozeZmazat));
        vykresliTipy();
        formularPripraveny = true;
        history.pushState({ hlbka: hlbka() + 1 }, '', '#objednane-formular');
        zobraz();
    }

    /** Späť, Zrušiť a po uložení: z formulára na zoznam (ako tlačidlo Späť v prehliadači). */
    function spatNaZoznam() {
        if (location.hash === '#objednane-formular' && hlbka() > 0) {
            history.back();
        } else {
            history.replaceState(history.state, '', '#objednane');
            zobraz();
        }
    }

    /** Reštaurácie, z ktorých sa už objednávalo (najnovšie prvé), zúžené podľa napísaného textu. */
    function vykresliTipy() {
        const napisane = normalizuj(form.restauracia.value).trim();
        const videne = new Set();
        const tipy = [];
        for (const o of polozky) {
            const kluc = normalizuj(o.restauracia);
            if (!kluc || videne.has(kluc)) continue;
            videne.add(kluc);
            if (kluc === napisane || (napisane && !kluc.includes(napisane))) continue;
            tipy.push(o);
            if (tipy.length === 6) break;
        }
        tipyEl.replaceChildren(...tipy.map((o) => {
            const tip = document.createElement('button');
            tip.type = 'button';
            tip.className = 'objednavky-tip';
            tip.append(znak(o.sluzba), document.createTextNode(o.restauracia));
            tip.addEventListener('click', () => {
                form.restauracia.value = o.restauracia;
                const radio = form.querySelector('input[name="sluzba"][value="' + o.sluzba + '"]');
                if (radio) radio.checked = true;
                vykresliTipy();
                if (!form.jedlo.value) form.jedlo.focus();
            });
            return tip;
        }));
        tipyEl.classList.toggle('d-none', tipy.length === 0);
    }
    form.restauracia.addEventListener('input', vykresliTipy);

    /** Chyba je na začiatku formulára — posunieme sa hore, aby ju bolo vidno. */
    function ukazChybu(text, pole = null) {
        chybaEl.textContent = text;
        chybaEl.classList.remove('d-none');
        window.scrollTo(0, 0);
        if (pole) pole.focus({ preventScroll: true });
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        chybaEl.classList.add('d-none');
        if (!form.restauracia.value.trim() && !form.jedlo.value.trim()) {
            ukazChybu('Napíš reštauráciu alebo čo ste mali.', form.restauracia);
            return;
        }
        if (!form.datum.value) {
            ukazChybu('Vyber, kedy ste jedlo mali.', form.datum);
            return;
        }
        const ulozitBtn = $('objednavkaUlozit');
        ulozitBtn.disabled = true;
        const nova = !upravovana;
        const data = await call('objednavka_ulozit', new FormData(form));
        ulozitBtn.disabled = false;
        if (data.success) {
            spatNaZoznam();
            showToast(nova ? 'Objednávka je zapísaná.' : 'Objednávka je upravená.');
        } else if (data.error) {
            ukazChybu(data.error);
        }
    });

    zmazatBtn.addEventListener('click', async () => {
        if (!upravovana || !confirm('Naozaj zmazať túto objednávku?')) return;
        zmazatBtn.disabled = true;
        const data = await call('objednavka_zmazat', { objednavka_id: upravovana.id });
        zmazatBtn.disabled = false;
        if (data.success) {
            spatNaZoznam();
            showToast('Objednávka je zmazaná.');
        } else if (data.error) {
            ukazChybu(data.error);
        }
    });

    $('objednavkaSpat').addEventListener('click', spatNaZoznam);
    $('objednavkaZrusit').addEventListener('click', spatNaZoznam);
    strankaEl.querySelectorAll('[data-objednavka-pridat]').forEach((btn) => btn.addEventListener('click', () => otvorFormular()));
    // Bočná ponuka na PC: Recepty / Objednané jedlo
    document.querySelectorAll('.app-bocna-polozka[data-stranka]').forEach((btn) =>
        btn.addEventListener('click', () => prejdi(btn.dataset.stranka)));

    vykresli();
    zobraz(); // príchod s adresou index.php#objednane (obnovenie stránky)
    return { prejdi, aktualna: () => strankaTeraz };
})();

/* Bez Objednaného jedla (pred migráciou 2.2) je Recepty v bočnej ponuke len posun hore. */
if (!objednavky) {
    document.querySelectorAll('.app-bocna-polozka[data-stranka="recepty"]').forEach((btn) =>
        btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' })));
}

/* ========================================================================
   Fotky vo formulári receptu (galéria) — doterajšie fotky ako miniatúry,
   admin ich môže jednotlivo mazať; nová fotka sa pri uložení pridá
   k ostatným s dátumom z poľa „Dátum novej fotky".
   ======================================================================== */
const recipePhotosForm = (function initRecipePhotosForm() {
    const field = document.getElementById('recipePhotosField');
    if (!field) return null;

    const form = document.getElementById('recipeForm');
    const modalEl = document.getElementById('recipeModal');
    const list = document.getElementById('recipePhotosList');
    const dateWrap = document.getElementById('recipePhotoDateWrap');
    const dateInput = document.getElementById('recipePhotoDate');
    let photos = [];
    let isNewRecipe = true;
    let deletedSomething = false; // fotka zmazaná → po zavretí okna treba obnoviť stránku

    const picker = bindPhotoPicker({
        input: document.getElementById('recipeImageInput'),
        wrap: document.getElementById('recipeImagePickWrap'),
        img: document.getElementById('recipeImagePick'),
        clearBtn: document.getElementById('recipeImagePickClear'),
        onChange(hasFile) {
            dateWrap.classList.toggle('d-none', !hasFile);
            if (hasFile && !dateInput.value) {
                // Pri novom recepte fotka zvyčajne patrí k „naposledy varené", inak je z dneška.
                const lastMade = form.querySelector('[name="last_made_date"]').value;
                dateInput.value = (isNewRecipe && lastMade) || todayISO();
            }
        },
    });

    async function removePhoto(photo, button) {
        if (!confirm('Naozaj natrvalo zmazať fotku z ' + formatSkDate(photo.date) + '?')) return;
        button.disabled = true;
        try {
            const data = await appPost('delete_photo', { photo_id: photo.id });
            if (data.success) {
                photos = data.photos || [];
                deletedSomething = true;
                render();
                showToast('Fotka bola zmazaná.');
            } else {
                showToast(data.error || 'Fotku sa nepodarilo zmazať.', true);
                button.disabled = false;
            }
        } catch (err) {
            showToast('Chyba pripojenia k serveru.', true);
            button.disabled = false;
        }
    }

    function render() {
        list.replaceChildren(...photos.map((photo) => {
            const label = photo.date ? formatSkDate(photo.date) : 'pôvodná fotka';
            const tile = document.createElement('div');
            tile.className = 'photo-thumb';

            const img = document.createElement('img');
            img.src = photo.path;
            img.alt = 'Fotka: ' + label;
            img.loading = 'lazy';

            const date = document.createElement('span');
            date.className = 'photo-thumb-date';
            date.textContent = label;
            tile.append(img, date);

            if (window.APP_IS_ADMIN && photo.id) {
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'photo-thumb-delete';
                del.title = 'Zmazať fotku';
                del.setAttribute('aria-label', 'Zmazať fotku z ' + label);
                del.innerHTML = '<i class="bi bi-trash"></i>';
                del.addEventListener('click', () => removePhoto(photo, del));
                tile.appendChild(del);
            }
            return tile;
        }));
    }

    modalEl.addEventListener('hidden.bs.modal', () => {
        if (deletedSomething) location.reload();
    });

    return {
        /** Pripraví pole pre nový recept (data = null) alebo úpravu existujúceho. */
        reset(data) {
            photos = recipePhotoList(data);
            isNewRecipe = !data;
            deletedSomething = false;
            dateInput.value = '';
            dateInput.max = todayISO();
            picker.reset();
            render();
        },
        /** Recept sa uložil — stránka sa obnoví sama, netreba to robiť pri zavretí okna. */
        saved() {
            deletedSomething = false;
        },
    };
})();

/* ========================================================================
   Galéria v náhľade receptu — fotky od najnovšej, listovanie šípkami,
   posúvaním prstom alebo klikom na miniatúru; pod fotkou dátum a autor.
   ======================================================================== */
const recipeGallery = (function initRecipeGallery() {
    const root = document.getElementById('viewRecipeGallery');
    if (!root) return null;

    const carouselEl = document.getElementById('viewRecipeCarousel');
    const slides = document.getElementById('viewRecipeSlides');
    const caption = document.getElementById('viewRecipePhotoCaption');
    const thumbs = document.getElementById('viewRecipeThumbs');
    let photos = [];

    function span(className, text) {
        const el = document.createElement('span');
        el.className = className;
        el.textContent = text;
        return el;
    }

    function select(index) {
        const photo = photos[index];
        caption.replaceChildren();
        if (!photo) return;

        if (photo.date) {
            const date = span('gallery-date', formatSkDate(photo.date));
            const icon = document.createElement('i');
            icon.className = 'bi bi-calendar3 me-1';
            date.prepend(icon);
            const left = document.createElement('span');
            left.append(date);
            if (photo.by) left.append(' ', span('gallery-by', '· pridal(a) ' + photo.by));
            caption.append(left);
        }
        if (photos.length > 1) {
            caption.append(span('gallery-index', (index + 1) + ' / ' + photos.length + (index === 0 ? ' · najnovšia' : '')));
        }

        root.classList.toggle('at-start', index === 0);
        root.classList.toggle('at-end', index === photos.length - 1);

        thumbs.querySelectorAll('.gallery-thumb').forEach((thumb, i) => {
            const active = i === index;
            thumb.classList.toggle('is-active', active);
            thumb.setAttribute('aria-pressed', active ? 'true' : 'false');
            if (active) {
                // Posunúť len pásik miniatúr, nie celé okno.
                thumbs.scrollTo({ left: thumb.offsetLeft - (thumbs.clientWidth - thumb.clientWidth) / 2, behavior: 'smooth' });
            }
        });
    }

    carouselEl.addEventListener('slid.bs.carousel', (event) => select(event.to));

    return {
        show(data) {
            photos = recipePhotoList(data);
            bootstrap.Carousel.getInstance(carouselEl)?.dispose();

            root.classList.toggle('d-none', photos.length === 0);
            root.classList.toggle('is-single', photos.length < 2);

            slides.replaceChildren(...photos.map((photo, i) => {
                const item = document.createElement('div');
                item.className = 'carousel-item' + (i === 0 ? ' active' : '');
                const img = document.createElement('img');
                img.src = photo.path;
                img.alt = (data.name || 'Recept') + (photo.date ? ' — ' + formatSkDate(photo.date) : '');
                item.appendChild(img);
                return item;
            }));

            thumbs.replaceChildren(...(photos.length < 2 ? [] : photos.map((photo, i) => {
                const thumb = document.createElement('button');
                thumb.type = 'button';
                thumb.className = 'gallery-thumb';
                thumb.setAttribute('aria-label', 'Fotka ' + (photo.date ? 'z ' + formatSkDate(photo.date) : (i + 1)));
                const img = document.createElement('img');
                img.src = photo.path;
                img.alt = '';
                img.loading = 'lazy';
                thumb.append(img, span('', photo.date ? formatSkDate(photo.date) : 'pôvodná'));
                thumb.addEventListener('click', () => bootstrap.Carousel.getInstance(carouselEl)?.to(i));
                return thumb;
            })));

            if (photos.length > 1) {
                bootstrap.Carousel.getOrCreateInstance(carouselEl, { interval: false, ride: false, touch: true, wrap: false });
            }
            select(0);
        },
    };
})();

/* ========================================================================
   Okno „Uvarené" — deň varenia (predvolene dnes) a nepovinná fotka, ktorá
   sa pridá do galérie receptu s týmto dátumom.
   ======================================================================== */
const madeDialog = (function initMadeDialog() {
    const modalEl = document.getElementById('madeModal');
    const form = document.getElementById('madeForm');
    if (!modalEl || !form) return null;

    const nameEl = document.getElementById('madeRecipeName');
    const dateInput = document.getElementById('madeDate');
    const errorBox = document.getElementById('madeFormError');
    const submitBtn = document.getElementById('madeFormSubmit');
    const fileInput = document.getElementById('madeImageInput');
    const picker = fileInput ? bindPhotoPicker({
        input: fileInput,
        wrap: document.getElementById('madeImagePickWrap'),
        img: document.getElementById('madeImagePick'),
        clearBtn: document.getElementById('madeImagePickClear'),
    }) : null;

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        errorBox.classList.add('d-none');

        const today = todayISO();
        if (!dateInput.value || dateInput.value > today) {
            showError('Vyber deň, kedy sa varilo (nie v budúcnosti).');
            dateInput.focus();
            return;
        }

        const formData = new FormData(form);
        formData.append('action', 'mark_made');
        formData.append('csrf_token', csrfToken());

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Ukladám…';

        const file = picker ? picker.file() : null;
        formData.delete('recipe_image');
        if (file) {
            try {
                formData.set('recipe_image', await downscaleImageFile(file));
            } catch (err) {
                formData.set('recipe_image', file); // zmenšenie zlyhalo — server si poradí sám
            }
        }

        try {
            const res = await fetch('index.php', { method: 'POST', body: formData });
            const data = await res.json().catch(() => null);
            if (data && data.success) {
                bootstrap.Modal.getInstance(modalEl)?.hide();
                showToast(file ? 'Uložené aj s fotkou. Chuť do jedla! 🍽️' : 'Skvelé, chuť do jedla! 🍽️');
                setTimeout(() => location.reload(), 600);
                return;
            }
            showError((data && data.error) || 'Uloženie sa nepodarilo (stav ' + res.status + '). Skús to znova.');
        } catch (err) {
            showError('Chyba pripojenia k serveru. Skús to znova.');
        }
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Uložiť';
    });

    return {
        open(recipeId, recipeName) {
            form.reset();
            form.querySelector('[name="recipe_id"]').value = recipeId;
            nameEl.textContent = recipeName || '';
            const today = todayISO();
            dateInput.value = today;
            dateInput.max = today;
            errorBox.classList.add('d-none');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Uložiť';
            if (picker) picker.reset();
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        },
    };
})();

/* ========================================================================
   Modal "Pridať / upraviť recept" — jeden modal pre obe akcie.
   Volaj openRecipeModal() bez parametra pre pridanie nového receptu,
   alebo openRecipeModal({...}) s dátami existujúceho receptu pre úpravu.
   ======================================================================== */
function openRecipeModal(data = null) {
    const form = document.getElementById('recipeForm');
    const errorBox = document.getElementById('recipeFormError');
    const title = document.getElementById('recipeModalTitle');
    const previewWrap = document.getElementById('recipeImagePreviewWrap');
    const previewImg = document.getElementById('recipeImagePreview');
    const removeCheckbox = document.getElementById('recipeImageRemove');

    form.reset();
    form.classList.remove('was-validated');
    errorBox.classList.add('d-none');
    errorBox.textContent = '';

    // Fotky: s galériou miniatúry + výber novej fotky (recipePhotosForm),
    // bez nej (stará databáza) pôvodný náhľad jednej fotky s „Odstrániť fotku".
    if (recipePhotosForm) {
        recipePhotosForm.reset(data);
    } else if (previewWrap) {
        previewWrap.classList.add('d-none');
        previewImg.src = '';
        if (removeCheckbox) removeCheckbox.checked = false;
    }

    const recipeIdField = form.querySelector('input[name="recipe_id"]');

    if (surovinyPicker) surovinyPicker.setRows(data && data.suroviny ? data.suroviny : []);

    if (data) {
        title.innerHTML = '<i class="bi bi-pencil-square me-2"></i>Upraviť recept';
        recipeIdField.value = data.id;
        form.querySelector('[name="name"]').value = data.name || '';
        form.querySelector('[name="last_made_date"]').value = data.lastMadeDate || '';
        
        // NOVÉ POLIA pre úpravu:
        form.querySelector('[name="category"]').value = data.category || '';
        form.querySelector('[name="prep_time"]').value = data.prep_time || '';
        const servingsField = form.querySelector('[name="servings"]');
        if (servingsField) servingsField.value = data.servings || '';
        form.querySelector('[name="url"]').value = data.url || '';
        form.querySelector('[name="ingredients"]').value = data.ingredients || '';

        if (!recipePhotosForm && previewWrap && data.imagePath) {
            previewImg.src = data.imagePath;
            previewWrap.classList.remove('d-none');
        }
    } else {
        title.innerHTML = '<i class="bi bi-journal-plus me-2"></i>Pridať recept';
        recipeIdField.value = '';
    }

    // Recept z fotky (verzia 1.8): tlačidlo „Vyplniť z fotky" len pri novom recepte, banner preč.
    if (window.aiRecept) window.aiRecept.pripravFormular(!data);

    // Telefón (verzia 1.9): sprievodca začína krokom 1; pri úprave sa dá uložiť z každého kroku.
    if (formularKroky) formularKroky.reset(!!data);

    bootstrap.Modal.getOrCreateInstance(document.getElementById('recipeModal')).show();
}

/* ========================================================================
   Modal "Náhľad receptu" — zobrazí detail po kliknutí na názov receptu.
   Z náhľadu sa dá jedným klikom prejsť rovno do editácie.
   ======================================================================== */
function openRecipeViewModal(data) {
    // Názov je pod fotkou (telefón) alebo vpravo vedľa fotiek (PC), v hlavičke sú tlačidlá.
    const nazovMobil = document.getElementById('viewRecipeNameMobil');
    if (nazovMobil) nazovMobil.textContent = data.name || '';

    if (recipeGallery) recipeGallery.show(data);
    // PC: recept bez fotky má užšie okno v jednom stĺpci (verzia 2.0).
    const galeria = document.getElementById('viewRecipeGallery');
    document.querySelector('#recipeViewModal .recipe-view-dialog')
        ?.classList.toggle('bez-fotky', !galeria || galeria.classList.contains('d-none'));

    const categoryBadge = document.getElementById('viewRecipeCategory');
    if (data.category) {
        categoryBadge.textContent = data.categoryLabel || data.category;
        categoryBadge.classList.remove('d-none');
    } else {
        categoryBadge.classList.add('d-none');
    }

    const prepBadge = document.getElementById('viewRecipePrepTime');
    if (data.prep_time) {
        prepBadge.innerHTML = `<i class="bi bi-clock me-1"></i>${data.prep_time} min`;
        prepBadge.classList.remove('d-none');
    } else {
        prepBadge.classList.add('d-none');
    }

    const lastMadeBadge = document.getElementById('viewRecipeLastMade');
    lastMadeBadge.textContent = data.badgeText || '';
    lastMadeBadge.className = 'badge ' + (data.badgeClass || 'text-bg-secondary');

    const urlLink = document.getElementById('viewRecipeUrl');
    if (data.url) {
        urlLink.href = data.url;
        urlLink.classList.remove('d-none');
    } else {
        urlLink.classList.add('d-none');
    }

    const servingsBadge = document.getElementById('viewRecipeServings');
    if (servingsBadge) {
        if (data.servings) {
            servingsBadge.innerHTML = '<i class="bi bi-people me-1"></i>';
            servingsBadge.append(porcieLabel(data.servings));
        }
        servingsBadge.classList.toggle('d-none', !data.servings);
    }

    const suroviny = data.suroviny || [];
    const surovinyWrap = document.getElementById('viewRecipeSurovinyWrap');
    if (surovinyWrap) {
        surovinyWrap.classList.toggle('d-none', suroviny.length === 0);
        // Náhľad sa vždy otvára s pôvodným počtom porcií.
        renderViewSuroviny(data, data.servings || null);
    }

    // Kým recept nemá suroviny vybrané zo zoznamu, sú stále len v tomto texte.
    const ingredientsLabel = document.getElementById('viewRecipeIngredientsLabel');
    if (ingredientsLabel) {
        ingredientsLabel.textContent = suroviny.length ? 'Poznámky / postup' : 'Suroviny / Poznámky';
    }

    const ingredientsEl = document.getElementById('viewRecipeIngredients');
    const ingredientsWrap = document.getElementById('viewRecipeIngredientsWrap');
    if (data.ingredients) {
        ingredientsEl.textContent = data.ingredients;
        ingredientsWrap.classList.remove('d-none');
    } else {
        ingredientsWrap.classList.add('d-none');
    }

    document.getElementById('viewRecipeCreatedBy').textContent = data.createdBy ? `Pridal(a): ${data.createdBy}` : '';

    mobilneNahlad(data);

    bootstrap.Modal.getOrCreateInstance(document.getElementById('recipeViewModal')).show();
}

/**
 * Tlačidlá náhľadu receptu a hodnotenie hviezdičkami pod názvom.
 * Telefón (verzia 1.7): Uvarené dole, Zdieľať / Upraviť / … hore.
 * PC (verzia 2.0): Upraviť, História, Zdieľať, Zmazať hore a Uvarené pod hodnotením.
 * Obe sady volajú to isté, v stránke je vždy viditeľná len jedna.
 */
function mobilneNahlad(data) {
    const modalEl = document.getElementById('recipeViewModal');
    // Najprv zavrieť náhľad, potom otvoriť ďalšie okno — dve okná naraz Bootstrap nezvláda.
    const poZavreti = (akcia) => () => {
        modalEl.addEventListener('hidden.bs.modal', akcia, { once: true });
        bootstrap.Modal.getInstance(modalEl)?.hide();
    };
    const nastav = (id, onclick) => {
        const el = document.getElementById(id);
        if (el) el.onclick = onclick;
    };

    const zdielat = () => shareRecipe(data);
    const upravit = poZavreti(() => openRecipeModal(data));
    const uvarene = poZavreti(() => madeDialog && madeDialog.open(data.id, data.name));
    const historia = poZavreti(() => openHistoryModal(data.id, data.name));
    // Zmazanie použije tlačidlo na karte receptu (rovnaké potvrdenie aj odstránenie karty).
    const zmazat = poZavreti(() => {
        document.querySelector('.recipe-row[data-recipe-id="' + data.id + '"] .btn-delete-recipe')?.click();
    });
    nastav('viewMobilZdielat', zdielat);
    nastav('viewMobilUpravit', upravit);
    nastav('viewMobilUvarene', uvarene);
    nastav('viewMobilHistoria', historia);
    nastav('viewMobilZmazat', zmazat);
    nastav('viewPcZdielat', zdielat);
    nastav('viewPcUpravit', upravit);
    nastav('viewPcUvarene', uvarene);
    nastav('viewPcHistoria', historia);
    nastav('viewPcZmazat', zmazat);

    const hodnotenie = document.getElementById('viewRecipeRating');
    if (hodnotenie && typeof hodnotenieReceptov !== 'undefined') {
        hodnotenie.classList.toggle('d-none', !hodnotenieReceptov.skopirujStav(data.id, hodnotenie));
    }
}

/** „200 g" / „2" / „" — množstvo s jednotkou pre náhľad a zdieľanie. */
function formatSurovinaDavka(row) {
    return window.Suroviny ? window.Suroviny.formatDavka(row) : '';
}

/** 1 porcia, 2–4 porcie, 5 a viac porcií. */
function porcieLabel(count) {
    const word = count === 1 ? 'porcia' : (count >= 2 && count <= 4 ? 'porcie' : 'porcií');
    return count + ' ' + word;
}

/**
 * Dávka suroviny pre zvolený počet porcií. Pri pôvodnom počte presne tak, ako je
 * zapísaná v recepte; po prepočte prevedená na jednotku, ktorá sa dá odmerať
 * (2000 g → 2 kg, 0,5 PL → 1½ ČL, 0,13 l → 125 ml), pozri Suroviny.prevedDavku.
 */
function prepocitanaDavka(row, factor) {
    if (factor === 1 || !row.mnozstvo || !window.Suroviny) return formatSurovinaDavka(row);
    return window.Suroviny.prevedDavku(Number(row.mnozstvo) * factor, row.jednotka).text;
}

/* ========================================================================
   Suroviny v náhľade receptu + prepočet porcií. Prepočet je len zobrazenie:
   v databáze ostávajú množstvá pre pôvodný počet porcií (data.servings).
   ======================================================================== */
function renderViewSuroviny(data, targetServings) {
    const list = document.getElementById('viewRecipeSuroviny');
    if (!list) return;

    const suroviny = data.suroviny || [];
    const base = data.servings || null;
    const hasQuantities = suroviny.some((row) => row.mnozstvo);
    const canScale = !!base && hasQuantities;
    const target = canScale ? Math.min(99, Math.max(1, targetServings || base)) : base;
    const factor = canScale ? target / base : 1;

    list.replaceChildren(...suroviny.map((row) => {
        const li = document.createElement('li');
        const davka = prepocitanaDavka(row, factor);
        if (davka) {
            const qty = document.createElement('span');
            qty.className = 'suroviny-view-qty';
            qty.textContent = davka + ' ';
            li.appendChild(qty);
        }
        li.appendChild(document.createTextNode(row.nazov));
        if (row.poznamka) {
            const note = document.createElement('span');
            note.className = 'suroviny-view-note';
            note.textContent = ' — ' + row.poznamka;
            li.appendChild(note);
        }
        return li;
    }));

    // „Do nákupu" pridá suroviny pre práve zobrazený počet porcií (pätička na telefóne, tlačidlo pod hodnotením na PC).
    ['viewRecipeShoppingBtn', 'viewPcNakup'].forEach((id) => {
        const shoppingBtn = document.getElementById(id);
        if (!shoppingBtn) return;
        shoppingBtn.classList.toggle('d-none', suroviny.length === 0);
        shoppingBtn.onclick = () => shopping && shopping.addRecipe(data.id, canScale ? target : null, shoppingBtn);
    });

    const stepper = document.getElementById('viewServings');
    const note = document.getElementById('viewServingsNote');
    if (!stepper || !note) return;

    stepper.classList.toggle('d-none', !canScale);
    note.replaceChildren();
    note.classList.add('d-none');

    if (canScale) {
        const minus = document.getElementById('viewServingsMinus');
        const plus = document.getElementById('viewServingsPlus');
        document.getElementById('viewServingsValue').textContent = porcieLabel(target);
        minus.disabled = target <= 1;
        plus.disabled = target >= 99;
        minus.onclick = () => renderViewSuroviny(data, target - 1);
        plus.onclick = () => renderViewSuroviny(data, target + 1);

        if (target !== base) {
            const reset = document.createElement('button');
            reset.type = 'button';
            reset.className = 'btn btn-link btn-sm p-0 align-baseline';
            reset.textContent = 'Vrátiť';
            reset.onclick = () => renderViewSuroviny(data, base);
            note.append('Pôvodne: ' + porcieLabel(base) + '. ', reset);
            note.classList.remove('d-none');
        }
    } else if (hasQuantities && !base && document.getElementById('recipeServings')) {
        note.textContent = 'Na prepočet porcií doplň pri recepte počet porcií (Upraviť recept).';
        note.classList.remove('d-none');
    }
}

/**
 * Zdieľanie receptu (názov, kategória, čas, suroviny, odkaz + fotka, ak je k
 * dispozícii) cez natívne systémové menu (WhatsApp, mail, SMS, sociálne
 * siete...). Na prehliadačoch bez podpory Web Share API to skopíruje text
 * do schránky ako náhradné riešenie.
 */
async function shareRecipe(data) {
    const lines = [`🍽️ ${data.name || 'Recept'}`];
    if (data.category) lines.push(`Kategória: ${data.categoryLabel || data.category}`);
    if (data.prep_time) lines.push(`Čas prípravy: ${data.prep_time} min`);
    if (data.servings) lines.push(`Porcie: ${data.servings}`);
    const suroviny = data.suroviny || [];
    if (suroviny.length) {
        lines.push('\nSuroviny:');
        suroviny.forEach((row) => {
            const davka = formatSurovinaDavka(row);
            lines.push('• ' + (davka ? davka + ' ' : '') + row.nazov + (row.poznamka ? ` (${row.poznamka})` : ''));
        });
    }
    if (data.ingredients) lines.push(`\n${suroviny.length ? 'Poznámky' : 'Suroviny / Poznámky'}:\n${data.ingredients}`);
    if (data.url) lines.push(`\nOdkaz na recept: ${data.url}`);

    const shareText = lines.join('\n');
    const shareData = {
        title: data.name || 'Recept',
        text: shareText,
    };

    // Skúsime priložiť aj fotku receptu, ak ju prehliadač vie zdieľať ako súbor
    try {
        if (data.imagePath && navigator.canShare) {
            const response = await fetch(data.imagePath);
            const blob = await response.blob();
            const file = new File([blob], 'recept.jpg', { type: blob.type || 'image/jpeg' });
            if (navigator.canShare({ files: [file] })) {
                shareData.files = [file];
            }
        }
    } catch (err) {
        // Fotku sa nepodarilo pripraviť — zdieľame aspoň text, nič sa nedeje
    }

    if (navigator.share) {
        try {
            await navigator.share(shareData);
        } catch (err) {
            // AbortError = používateľ dialóg jednoducho zavrel, to nehlásime ako chybu
            if (err.name !== 'AbortError') {
                showToast('Zdieľanie sa nepodarilo.', true);
            }
        }
        return;
    }

    // Prehliadač nepodporuje Web Share API (typicky staršie desktopové prehliadače)
    try {
        await navigator.clipboard.writeText(shareText);
        showToast('Prehliadač nepodporuje priame zdieľanie — recept bol skopírovaný do schránky.');
    } catch (err) {
        showToast('Zdieľanie nie je v tomto prehliadači podporované.', true);
    }
}

/**
 * Zmenší a skomprimuje obrázok v prehliadači ešte pred odoslaním na server.
 * Mobilné fotky (najmä z iPhonu) bývajú niekoľko MB / vysoké rozlíšenie a
 * ľahko presiahnu 'post_max_size' na hostingu — to spôsobovalo "Chyba
 * pripojenia k serveru". Server aj tak obrázok zmenšuje na max 1200×800,
 * takže odoslanie väčšieho rozlíšenia je zbytočné.
 */
async function downscaleImageFile(file, maxDim = 1600, quality = 0.82) {
    if (!file || !file.type.startsWith('image/')) return file;

    const bitmap = await createImageBitmap(file).catch(() => null);
    if (!bitmap) return file; // fallback — pošleme originál, server to skúsi spracovať sám

    const scale = Math.min(1, maxDim / Math.max(bitmap.width, bitmap.height));
    const w = Math.max(1, Math.round(bitmap.width * scale));
    const h = Math.max(1, Math.round(bitmap.height * scale));

    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(bitmap, 0, 0, w, h);

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
    if (!blob) return file;

    return new File([blob], (file.name || 'photo').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
}

/* ========================================================================
   Formulár receptu na telefóne (verzia 1.9) — sprievodca v troch krokoch:
   Základ → Suroviny → Postup a fotka. Stále je to ten istý formulár
   #recipeForm; krok sa len zapíše do form[data-krok] a CSS pre telefón
   ukáže iba jeho .formular-krok. Na PC sú všetky polia naraz ako doteraz.
   ======================================================================== */
const formularKroky = (function initFormularKroky() {
    const form = document.getElementById('recipeForm');
    const modalEl = document.getElementById('recipeModal');
    if (!form || !modalEl) return null;

    const $ = (id) => document.getElementById(id);
    const kroky = Array.from(form.querySelectorAll('.formular-krok[data-nadpis]'))
        .sort((a, b) => Number(a.dataset.krok) - Number(b.dataset.krok));
    const pocet = kroky.length;
    if (!pocet) return null;

    const telo = form.querySelector('.modal-body');
    const spat = $('recipeKrokSpat');
    const spatIkona = spat.querySelector('i');
    const nazovPole = form.querySelector('[name="name"]');
    const kategoria = form.querySelector('[name="category"]');
    const cipy = Array.from(form.querySelectorAll('.formular-cip'));
    const dieliky = Array.from(form.querySelectorAll('.formular-postup-dielik'));
    const viacBtn = $('recipeViacPrepinac');
    const zoznamSurovin = $('surovinyList');
    let aktualny = 1;

    const sklonuj = (n, jedna, dve, pat) => (n === 1 ? jedna : (n >= 2 && n <= 4 ? dve : pat));

    function popisKroku(n) {
        const popis = kroky[n - 1].dataset.popis || '';
        if (popis !== 'pocet-surovin') return popis;
        const m = zoznamSurovin ? zoznamSurovin.querySelectorAll('.surovina-row').length : 0;
        return m ? 'Pridané: ' + m + ' ' + sklonuj(m, 'surovina', 'suroviny', 'surovín') : 'Začni písať a vyber zo zoznamu';
    }

    function nastav(n, opts = {}) {
        aktualny = Math.min(Math.max(1, n), pocet);
        form.dataset.krok = String(aktualny);
        $('recipeKrokCislo').textContent = 'Krok ' + aktualny + ' z ' + pocet;
        $('recipeKrokNadpis').textContent = kroky[aktualny - 1].dataset.nadpis;
        $('recipeKrokPopis').textContent = popisKroku(aktualny);

        // Hlavička: v prvom kroku Zrušiť, ďalej návrat na predchádzajúci krok.
        if (aktualny === 1) {
            spatIkona.className = 'bi bi-x-lg';
            $('recipeKrokSpatText').textContent = 'Zrušiť';
            spat.setAttribute('aria-label', 'Zrušiť a zavrieť');
        } else {
            const predosly = kroky[aktualny - 2].dataset.nadpis;
            spatIkona.className = 'bi bi-chevron-left';
            $('recipeKrokSpatText').textContent = predosly;
            spat.setAttribute('aria-label', 'Späť na krok ' + predosly);
        }
        if (aktualny < pocet) {
            // Vedľa tlačidla Uložiť sa dlhý text nezmestí, preto kratší (ak ho krok má).
            const dalsi = kroky[aktualny].dataset;
            $('recipeKrokDalejText').textContent = form.classList.contains('moze-ulozit') && dalsi.dalejKratko
                ? dalsi.dalejKratko : dalsi.dalej;
        }
        dieliky.forEach((d, i) => {
            d.classList.toggle('je-hotovy', i < aktualny);
            if (i === aktualny - 1) d.setAttribute('aria-current', 'step');
            else d.removeAttribute('aria-current');
        });

        if (opts.hore !== false) telo.scrollTop = 0;
        if (opts.fokus && jeTelefon()) $('recipeKrokNadpis').focus({ preventScroll: true });
    }

    /** Prechod na iný krok. Z prvého kroku ďalej len s vyplneným názvom. */
    function chodNa(ciel) {
        if (ciel > aktualny && aktualny === 1 && !nazovPole.value.trim()) {
            nazovPole.value = '';
            nazovPole.classList.add('is-invalid');
            nazovPole.focus();
            return;
        }
        nastav(ciel, { fokus: true });
    }
    nazovPole.addEventListener('input', () => nazovPole.classList.remove('is-invalid'));

    function krokPola(el) {
        const obal = el && el.closest('.formular-krok');
        return obal ? Number(obal.dataset.krok) : aktualny;
    }

    function nastavViac(otvorene) {
        form.classList.toggle('viac-otvorene', otvorene);
        if (!viacBtn) return;
        viacBtn.setAttribute('aria-expanded', otvorene ? 'true' : 'false');
        $('recipeViacNazov').textContent = otvorene ? 'Menej možností' : 'Viac možností';
    }

    function oznacCipy() {
        cipy.forEach((cip) => {
            const aktivny = cip.dataset.kategoria === kategoria.value;
            cip.classList.toggle('is-active', aktivny);
            cip.setAttribute('aria-pressed', aktivny ? 'true' : 'false');
        });
    }

    $('recipeKrokDalej').addEventListener('click', () => chodNa(aktualny + 1));
    spat.addEventListener('click', () => {
        if (aktualny === 1) bootstrap.Modal.getInstance(modalEl)?.hide();
        else nastav(aktualny - 1, { fokus: true });
    });
    dieliky.forEach((d) => d.addEventListener('click', () => chodNa(Number(d.dataset.ciel))));
    viacBtn?.addEventListener('click', () => nastavViac(!form.classList.contains('viac-otvorene')));

    // Enter v poli na telefóne = Ďalej (do posledného kroku), nie uloženie receptu.
    form.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' || e.defaultPrevented || !jeTelefon() || aktualny >= pocet) return;
        const t = e.target;
        if (t.tagName !== 'INPUT' || ['checkbox', 'radio', 'file', 'button', 'submit'].includes(t.type)) return;
        e.preventDefault();
        chodNa(aktualny + 1);
    });

    // Kategória ako tlačidlá — opätovné ťuknutie na vybranú kategóriu ju zruší.
    cipy.forEach((cip) => cip.addEventListener('click', () => {
        kategoria.value = kategoria.value === cip.dataset.kategoria ? '' : cip.dataset.kategoria;
        kategoria.dispatchEvent(new Event('change', { bubbles: true }));
    }));
    kategoria.addEventListener('change', oznacCipy);

    // Porcie a čas prípravy: − a +. Prázdne pole prvým + dostane bežnú hodnotu,
    // pod najmenšiu hodnotu sa pole vyprázdni (= nevyplnené).
    form.addEventListener('click', (e) => {
        const btn = e.target.closest('.krokovac-tlacidlo');
        if (!btn) return;
        const obal = btn.closest('.krokovac');
        const pole = obal.querySelector('input');
        const krok = Number(obal.dataset.krokO) || 1;
        const min = Number(pole.min) || 1;
        const max = Number(pole.max) || 999;
        const smer = Number(btn.dataset.smer);
        const teraz = parseInt(pole.value, 10);
        let nova;
        if (isNaN(teraz)) {
            if (smer < 0) return;
            nova = Number(obal.dataset.predvolene) || min;
        } else {
            nova = smer > 0 ? Math.floor(teraz / krok) * krok + krok : Math.ceil(teraz / krok) * krok - krok;
        }
        pole.value = nova < min ? '' : String(Math.min(nova, max));
        pole.dispatchEvent(new Event('input', { bubbles: true }));
    });

    // Počet surovín v podnadpise kroku 2 sa mení s riadkami zoznamu.
    if (zoznamSurovin) {
        new MutationObserver(() => {
            $('recipeKrokPopis').textContent = popisKroku(aktualny);
        }).observe(zoznamSurovin, { childList: true });
    }

    // Fotka z fotoaparátu: samostatné pole s capture, súbor sa presunie do recipe_image,
    // aby ho spracoval ten istý náhľad aj ukladanie. Bez DataTransfer ostane len galéria.
    const kamera = $('recipeImageKamera');
    const obrazok = $('recipeImageInput');
    if (kamera && obrazok) {
        let mozeKamera = false;
        try {
            mozeKamera = typeof DataTransfer === 'function' && !!new DataTransfer().items;
        } catch (err) {
            mozeKamera = false;
        }
        if (!mozeKamera) {
            $('recipeKameraDlazdica')?.remove();
            kamera.remove();
            $('recipeFotoDlazdice')?.classList.add('len-galeria');
            const text = $('recipeGaleriaDlazdica')?.querySelector('span');
            if (text) text.textContent = 'Pridať fotku';
        } else {
            kamera.addEventListener('change', () => {
                const subor = kamera.files && kamera.files[0];
                kamera.value = '';
                if (!subor) return;
                const prenos = new DataTransfer();
                prenos.items.add(subor);
                obrazok.files = prenos.files;
                obrazok.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }
    }

    return {
        /** Pri každom otvorení formulára (openRecipeModal). */
        reset(jeUprava) {
            nazovPole.classList.remove('is-invalid');
            form.classList.toggle('moze-ulozit', jeUprava);
            $('recipeKrokRezim').textContent = jeUprava ? 'Úprava receptu' : 'Nový recept';
            nastavViac(!!(form.querySelector('[name="url"]').value || form.querySelector('[name="last_made_date"]').value));
            $('recipeOdhad')?.classList.add('d-none');
            oznacCipy();
            nastav(1);
        },
        /** Formulár sa predvyplnil z fotky (AI) — uložiť sa dá hneď z každého kroku. */
        poVyplneni(odhad) {
            form.classList.add('moze-ulozit');
            $('recipeKrokRezim').textContent = 'Nový recept z fotky';
            $('recipeOdhad')?.classList.toggle('d-none', !odhad);
            oznacCipy();
            nastav(aktualny, { hore: false });
        },
        /** Neplatný formulár pri uložení: ukáže krok s prvým chybným poľom. */
        ukazChybu() {
            const pole = form.querySelector(':invalid');
            if (!pole) return;
            if (pole.closest('.formular-viac')) nastavViac(true);
            nastav(krokPola(pole));
            pole.focus();
        },
        /** Ukáže krok, v ktorom je dané pole. */
        ukazPole(el) {
            nastav(krokPola(el));
        },
        /** Chybové hlásenie je pod poliami — na telefóne sa k nemu posunie. */
        ukazHlasenie() {
            $('recipeFormError').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        },
    };
})();

(function initRecipeForm() {
    const form = document.getElementById('recipeForm');
    if (!form) return;

    const modalEl = document.getElementById('recipeModal');
    const submitBtn = document.getElementById('recipeFormSubmit');
    const errorBox = document.getElementById('recipeFormError');
    const recipeIdField = form.querySelector('input[name="recipe_id"]');
    const imageInput = form.querySelector('input[name="recipe_image"]');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        errorBox.classList.add('d-none');

        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            if (formularKroky) formularKroky.ukazChybu();
            return;
        }

        if (surovinyPicker) {
            // Rozpísaná, ale nepotvrdená surovina by sa inak potichu stratila.
            const pending = surovinyPicker.pendingText();
            if (pending) {
                errorBox.textContent = 'Surovinu „' + pending + '“ ešte potvrď výberom zo zoznamu, alebo pole vymaž.';
                errorBox.classList.remove('d-none');
                if (formularKroky) formularKroky.ukazPole(document.getElementById('surovinaSearch'));
                surovinyPicker.focusSearch();
                return;
            }
            surovinyPicker.sync();
        }

        const isEdit = !!recipeIdField.value;
        const formData = new FormData(form);
        formData.append('action', isEdit ? 'update_recipe' : 'add_recipe');

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Ukladám…';

        // Ak je priložená fotka, zmenšíme ju pred odoslaním (viď downscaleImageFile vyššie).
        if (imageInput && imageInput.files && imageInput.files[0]) {
            try {
                const smaller = await downscaleImageFile(imageInput.files[0]);
                formData.set('recipe_image', smaller);
            } catch (err) {
                // Zmenšenie zlyhalo (napr. veľmi starý prehliadač) — pošleme originál,
                // server má vlastnú kontrolu veľkosti a formátu.
            }
        } else if (window.aiRecept && window.aiRecept.fotkaNaUlozenie()) {
            // Fotka, z ktorej sa recept vyplnil (políčko „Pridať fotku k receptu"). Je už zmenšená.
            formData.set('recipe_image', window.aiRecept.fotkaNaUlozenie());
        }

        try {
            const res = await fetch('index.php', { method: 'POST', body: formData });
            const rawText = await res.text();

            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseErr) {
                // Odpoveď nebola platný JSON — zobrazíme aspoň jej začiatok,
                // nech vidíme, čo server skutočne poslal (DOČASNÉ LADENIE).
                errorBox.textContent = 'Neplatná odpoveď servera (status ' + res.status + '): '
                    + rawText.slice(0, 300);
                errorBox.classList.remove('d-none');
                if (formularKroky) formularKroky.ukazHlasenie();
                return;
            }

            if (data.success) {
                if (recipePhotosForm) recipePhotosForm.saved();
                bootstrap.Modal.getInstance(modalEl).hide();
                showToast(isEdit ? 'Recept bol upravený.' : 'Recept bol pridaný.');
                // Najjednoduchší spoľahlivý spôsob ako zobraziť správne
                // zoradenú/aktualizovanú tabuľku je znova načítať stránku.
                setTimeout(() => location.reload(), 400);
            } else {
                errorBox.textContent = data.error || 'Niečo sa pokazilo, skús to znova.';
                errorBox.classList.remove('d-none');
                if (formularKroky) formularKroky.ukazHlasenie();
            }
        } catch (err) {
            errorBox.textContent = 'Chyba pripojenia k serveru (' + (err.message || err) + '). Skús to znova.';
            errorBox.classList.remove('d-none');
            if (formularKroky) formularKroky.ukazHlasenie();
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Uložiť<span class="ulozit-slovo-recept"> recept</span>';
        }
    });
})();

/* ========================================================================
   Tabuľka receptov — "Uvariť dnes" a "Zmazať" (event delegation).
   ======================================================================== */
(function initRecipeTableActions() {
    const table = document.getElementById('recipesTable');
    if (!table) return;

    const csrfToken = document.querySelector('#recipeForm input[name="csrf_token"]')?.value || '';

    async function postAction(action, recipeId) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('recipe_id', recipeId);
        formData.append('csrf_token', csrfToken);

        const res = await fetch('index.php', { method: 'POST', body: formData });
        return res.json();
    }

    table.addEventListener('click', async (e) => {
        const shoppingBtn = e.target.closest('.btn-add-shopping');
        if (shoppingBtn && shopping) {
            shopping.addRecipe(shoppingBtn.dataset.id, null, shoppingBtn);
            return;
        }

        const madeBtn = e.target.closest('.btn-mark-made');
        const deleteBtn = e.target.closest('.btn-delete-recipe');

        if (madeBtn) {
            // Okno „Uvarené": deň varenia + nepovinná fotka do galérie.
            if (madeDialog) madeDialog.open(madeBtn.dataset.id, madeBtn.dataset.name);
            return;
        }

        if (deleteBtn) {
            const name = deleteBtn.dataset.name || 'tento recept';
            if (!confirm(`Naozaj natrvalo vymazať „${name}“?`)) return;

            deleteBtn.disabled = true;
            try {
                const data = await postAction('delete_recipe', deleteBtn.dataset.id);
                if (data.success) {
                    showToast('Recept bol vymazaný.');
                    deleteBtn.closest('.recipe-row')?.remove();
                } else {
                    showToast(data.error || 'Mazanie zlyhalo.', true);
                    deleteBtn.disabled = false;
                }
            } catch (err) {
                showToast('Chyba pripojenia k serveru.', true);
                deleteBtn.disabled = false;
            }
        }
    });
})();	
/* ========================================================================
   Hviezdičkové hodnotenie receptov — každý používateľ má vlastný hlas,
   v tabuľke sa zobrazuje priemer + počet hodnotení.
   ======================================================================== */
/* Hviezdičky sú na karte receptu (PC) aj v náhľade receptu (telefón, verzia 1.7).
   Po hodnotení sa prekreslia všetky miesta s tým istým receptom, preto stav
   (data-user-rating) a súhrn nesie každý prvok .recipe-rating sám. */
const hodnotenieReceptov = (function initRecipeRatings() {
    const csrfToken = document.querySelector('#recipeForm input[name="csrf_token"]')?.value || '';
    const odosiela = new Set(); // recepty, ktorých hodnotenie sa práve ukladá

    async function postRating(recipeId, rating) {
        const formData = new FormData();
        formData.append('action', 'rate_recipe');
        formData.append('recipe_id', recipeId);
        formData.append('rating', rating);
        formData.append('csrf_token', csrfToken);

        const res = await fetch('index.php', { method: 'POST', body: formData });
        return res.json();
    }

    function paintStars(starsEl, count) {
        starsEl.querySelectorAll('.rating-star').forEach(starEl => {
            const value = parseInt(starEl.dataset.value, 10);
            starEl.classList.toggle('bi-star-fill', value <= count);
            starEl.classList.toggle('bi-star', value > count);
        });
    }

    const ulozene = (widget) => parseInt(widget.dataset.userRating, 10) || 0;
    const widgetyReceptu = (recipeId) =>
        document.querySelectorAll('.recipe-rating[data-recipe-id="' + CSS.escape(String(recipeId)) + '"]');

    async function ohodnot(recipeId, value) {
        if (!recipeId || odosiela.has(recipeId)) return;
        odosiela.add(recipeId);
        try {
            const data = await postRating(recipeId, value);
            if (data.success) {
                const suhrn = `${data.average.toFixed(1).replace('.', ',')} ★ (${data.count})`;
                widgetyReceptu(recipeId).forEach(widget => {
                    widget.dataset.userRating = data.yourRating;
                    widget.dataset.summary = suhrn;
                    paintStars(widget.querySelector('.rating-stars'), data.yourRating);
                    const summaryEl = widget.querySelector('.rating-summary');
                    if (summaryEl) summaryEl.textContent = suhrn;
                });
                showToast('Vďaka za hodnotenie!');
            } else {
                showToast(data.error || 'Hodnotenie sa nepodarilo uložiť.', true);
            }
        } catch (err) {
            showToast('Chyba pripojenia k serveru.', true);
        } finally {
            odosiela.delete(recipeId);
        }
    }

    // Offline klik zachytí OfflineRezim ešte predtým (hviezdičky majú data-vyzaduje-siet).
    document.addEventListener('click', (e) => {
        const star = e.target.closest('.recipe-rating .rating-star');
        if (!star) return;
        ohodnot(star.closest('.recipe-rating').dataset.recipeId, parseInt(star.dataset.value, 10));
    });
    // Hviezdičky v náhľade sa dajú vybrať aj klávesnicou (Enter / medzerník).
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const star = e.target.closest?.('.recipe-rating .rating-star');
        if (!star) return;
        e.preventDefault();
        star.click();
    });

    // Náhľad pri prejdení myšou, po odídení sa vráti na uložené hodnotenie.
    // Offline sa hodnotiť nedá, preto ani náhľad neukazujeme.
    document.addEventListener('mouseover', (e) => {
        const star = e.target.closest('.recipe-rating .rating-star');
        if (!star || OfflineRezim.jeAktivny()) return;
        paintStars(star.closest('.rating-stars'), parseInt(star.dataset.value, 10));
    });
    document.addEventListener('mouseout', (e) => {
        const star = e.target.closest('.recipe-rating .rating-star');
        if (!star) return;
        paintStars(star.closest('.rating-stars'), ulozene(star.closest('.recipe-rating')));
    });

    return {
        /** Prenesie hodnotenie z karty receptu do iného prvku (náhľad receptu). */
        skopirujStav(recipeId, ciel) {
            const karta = document.querySelector('.recipe-card .recipe-rating[data-recipe-id="' + CSS.escape(String(recipeId)) + '"]');
            if (!karta || !ciel) return false;
            ciel.dataset.recipeId = recipeId;
            ciel.dataset.userRating = ulozene(karta);
            paintStars(ciel.querySelector('.rating-stars'), ulozene(karta));
            const suhrn = karta.dataset.summary || karta.querySelector('.rating-summary')?.textContent.trim() || '';
            const summaryEl = ciel.querySelector('.rating-summary');
            if (summaryEl) summaryEl.textContent = suhrn;
            return true;
        },
    };
})();

/* ========================================================================
   Vyhľadávanie + filter podľa kategórie + zoradenie tabuľky.
   Hľadanie a filter kategórie sa kombinujú (riadok sa zobrazí, len ak
   vyhovuje obom), zoradenie iba prehadzuje poradie riadkov v DOM.
   ======================================================================== */
(function initFilterAndSort() {
    const searchInput = document.getElementById('recipeSearch');
    const categorySelect = document.getElementById('recipeCategoryFilter');
    const sortSelect = document.getElementById('recipeSortSelect');
    const tbody = document.getElementById('recipeTableBody');
    if (!tbody) return;

    function getRows() {
        return Array.from(tbody.querySelectorAll('.recipe-row'));
    }

    function applyFilters() {
        // Bez diakritiky a veľkých písmen — rovnako sú pripravené aj data-search-* atribúty.
        const rawQuery = searchInput?.value || '';
        const query = window.Suroviny ? window.Suroviny.normKey(rawQuery) : rawQuery.toLowerCase().trim();
        const category = categorySelect ? categorySelect.value : '';

        getRows().forEach(row => {
            const name = row.dataset.searchName || '';
            const ingredients = row.dataset.searchIngredients || '';
            const rowCategory = row.dataset.category || '';

            const matchesSearch = !query || name.includes(query) || ingredients.includes(query);
            const matchesCategory = !category || rowCategory === category;

            row.classList.toggle('d-none', !(matchesSearch && matchesCategory));
        });
        ukazPocet();
    }

    // PC (verzia 2.0): „6 receptov · klikni na recept…", po hľadaní „Nájdené: 2 z 6 receptov".
    function ukazPocet() {
        const pocetEl = document.getElementById('receptyPocet');
        if (!pocetEl) return;
        const rows = getRows();
        const vidno = rows.filter((row) => !row.classList.contains('d-none')).length;
        const slovo = (n) => (n === 1 ? 'recept' : (n >= 2 && n <= 4 ? 'recepty' : 'receptov'));
        if (!rows.length) {
            pocetEl.textContent = 'Zatiaľ žiadne recepty';
        } else if (vidno === rows.length) {
            pocetEl.textContent = rows.length + ' ' + slovo(rows.length) + ' · klikni na recept pre postup, suroviny a fotky';
        } else {
            pocetEl.textContent = 'Nájdené: ' + vidno + ' z ' + rows.length + ' ' + slovo(rows.length)
                + (vidno ? '' : ' — skús iné slovo alebo kategóriu Všetko');
        }
    }

    function applySort() {
        // „default" je to isté poradie, v akom stránku posiela server (naposledy
        // varené prvé). Triedime ho aj tu, aby sa dalo vrátiť z iného zoradenia.
        const selected = sortSelect ? sortSelect.value : 'default';
        const sortBy = selected === 'default' ? 'last_made_desc' : selected;

        const rows = getRows();
        const newestFirst = (a, b) => Number(b.dataset.recipeId) - Number(a.dataset.recipeId);

        rows.sort((a, b) => {
            switch (sortBy) {
                case 'prep_time_asc': {
                    // Recepty bez uvedeného času prípravy pôjdu na koniec
                    const pa = parseInt(a.dataset.prepTime, 10);
                    const pb = parseInt(b.dataset.prepTime, 10);
                    const va = isNaN(pa) ? Infinity : pa;
                    const vb = isNaN(pb) ? Infinity : pb;
                    return va - vb;
                }
                case 'added_desc':
                    // Od najnovšie pridaného receptu (číslo receptu rastie s časom pridania)
                    return newestFirst(a, b);
                case 'last_made_desc': {
                    // Od najnovšie uvarených; nikdy neuvarené pôjdu na koniec
                    const da = a.dataset.lastMade || '';
                    const db = b.dataset.lastMade || '';
                    if (!da && !db) return newestFirst(a, b);
                    if (!da) return 1;
                    if (!db) return -1;
                    return db.localeCompare(da) || newestFirst(a, b);
                }
                case 'last_made_asc': {
                    // Od najdávnejšie uvarených; nikdy neuvarené pôjdu navrch (najviac "staré")
                    const da = a.dataset.lastMade || '';
                    const db = b.dataset.lastMade || '';
                    if (!da && !db) return 0;
                    if (!da) return -1;
                    if (!db) return 1;
                    return da.localeCompare(db);
                }
                default:
                    return 0;
            }
        });

        rows.forEach(row => tbody.appendChild(row));
    }

    searchInput?.addEventListener('input', applyFilters);
    categorySelect?.addEventListener('change', applyFilters);
    sortSelect?.addEventListener('change', applySort);
    // Zmazaný recept zmizne zo zoznamu — počet receptov sa prepočíta.
    new MutationObserver(ukazPocet).observe(tbody, { childList: true });
})();
/* ========================================================================
   Modal "História receptu" — načítanie logov cez AJAX
   ======================================================================== */
async function openHistoryModal(recipeId, recipeName) {
    // Nastavíme názov receptu a vyčistíme modal
    document.getElementById('historyRecipeName').textContent = recipeName;
    const bodyEl = document.getElementById('historyModalBody');
    bodyEl.innerHTML = '<div class="text-center text-secondary py-3"><span class="spinner-border spinner-border-sm me-2"></span>Načítavam dáta z databázy...</div>';

    // Zobrazíme modal
    bootstrap.Modal.getOrCreateInstance(document.getElementById('historyModal')).show();

    // Pripravíme dáta na odoslanie
    const formData = new FormData();
    formData.append('action', 'get_logs');
    formData.append('recipe_id', recipeId);
    
    // Zoberieme CSRF token z hlavného formulára kvôli bezpečnosti
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    formData.append('csrf_token', csrfToken);

    try {
        const res = await fetch('index.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            if (data.logs.length === 0) {
                bodyEl.innerHTML = '<div class="text-center text-secondary py-4"><i class="bi bi-clipboard-x fs-3 d-block mb-2"></i>Pre tento recept zatiaľ neexistuje žiadna história.</div>';
                return;
            }

            // Vygenerujeme krásny zoznam z logov
            let html = '<ul class="list-group list-group-flush">';
            data.logs.forEach(log => {
                let actionText = log.action;
                let icon = 'bi-record-circle';
                let color = 'text-secondary';

                // Preklad akcií do slovenčiny a nastavenie farieb
                if (log.action === 'created') { actionText = 'Pridal(a) recept'; icon = 'bi-plus-circle-fill'; color = 'text-success'; }
                else if (log.action === 'updated') { actionText = 'Upravil(a) recept'; icon = 'bi-pencil-fill'; color = 'text-primary'; }
                else if (log.action === 'made') { actionText = 'Uvarené'; icon = 'bi-check-circle-fill'; color = 'text-warning'; }
                else if (log.action === 'rated') { actionText = 'Ohodnotil(a) recept'; icon = 'bi-star-fill'; color = 'text-warning'; }
                else if (log.action === 'photo') { actionText = 'Pridal(a) fotku'; icon = 'bi-camera-fill'; color = 'text-info'; }
                else if (log.action === 'photo_deleted') { actionText = 'Zmazal(a) fotku'; icon = 'bi-camera'; color = 'text-danger'; }
                
                const dateObj = new Date(log.created_at);
                const dateStr = dateObj.toLocaleDateString('sk-SK') + ' ' + dateObj.toLocaleTimeString('sk-SK', {hour: '2-digit', minute:'2-digit'});
                const noteHtml = log.note ? `<div class="small text-muted mt-1 fst-italic px-3 py-1 border-start border-2 border-secondary-subtle">"${escapeHtml(log.note)}"</div>` : '';

                html += `
                    <li class="list-group-item bg-transparent px-0 py-3">
                        <div class="d-flex w-100 justify-content-between align-items-start">
                            <div>
                                <i class="bi ${icon} ${color} me-2"></i>
                                <strong>${escapeHtml(log.username || 'Neznámy')}</strong> <span class="text-secondary">${actionText}</span>
                                ${noteHtml}
                            </div>
                            <small class="text-secondary ms-3 text-end text-nowrap">${dateStr}</small>
                        </div>
                    </li>
                `;
            });
            html += '</ul>';
            bodyEl.innerHTML = html;
        } else {
            bodyEl.innerHTML = `<div class="alert alert-danger m-3">${escapeHtml(data.error || 'Chyba načítania.')}</div>`;
        }
    } catch (err) {
        bodyEl.innerHTML = '<div class="alert alert-danger m-3">Chyba pripojenia k serveru.</div>';
    }
}

/* ========================================================================
   Klikateľný riadok tabuľky — klik kdekoľvek v riadku otvorí náhľad
   receptu. Tlačidlá (Uvarené, História, Upraviť, Zmazať), odkaz na
   pôvodný recept aj hviezdičkové hodnotenie zostávajú plne funkčné —
   klik na ne náhľad neotvára.
   ======================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const tableBody = document.getElementById('recipeTableBody');
    if (!tableBody) return;

    // Príchod z upozornenia (index.php?recept=12): otvoríme náhľad daného receptu
    // a parameter z adresy odstránime, aby sa pri obnovení stránky neotváral znova.
    // V uloženej kópii (offline) je APP_OPEN_RECIPE z času uloženia, preto číslo berieme z adresy.
    const openRecipeId = OfflineRezim.jeKopia()
        ? (parseInt(new URLSearchParams(location.search).get('recept') || '', 10) || 0)
        : window.APP_OPEN_RECIPE;
    if (openRecipeId) {
        const card = tableBody.querySelector('.recipe-row[data-recipe-id="' + openRecipeId + '"]');
        if (card) {
            try {
                card.scrollIntoView({ block: 'center' });
                openRecipeViewModal(JSON.parse(card.getAttribute('data-payload')));
            } catch (e) {
                console.error('Recept z upozornenia sa nepodarilo otvoriť:', e);
            }
        } else {
            showToast('Recept z upozornenia už neexistuje.', true);
        }
        history.replaceState(null, '', location.pathname);
    }

    tableBody.addEventListener('click', (event) => {
        const row = event.target.closest('.recipe-row');
        if (!row) return;

        // Klik na tlačidlo, odkaz alebo hviezdičku hodnotenia necháme
        // fungovať podľa seba a náhľad neotvárame.
        if (event.target.closest('button, a, .rating-star, .recipe-rating')) {
            return;
        }

        const payloadJson = row.getAttribute('data-payload');
        if (!payloadJson) return;

        try {
            const recipeData = JSON.parse(payloadJson);
            openRecipeViewModal(recipeData);
        } catch (e) {
            console.error('Chyba pri spracovaní dát receptu:', e);
        }
    });
});
/* ========================================================================
   Recept z fotky cez AI (verzia 1.8). Server (akcia ai_rozpoznaj, includes/ai.php)
   pošle fotku AI a vráti návrh receptu. Na telefóne okno #aiModal (výber fotky →
   rozpoznanie) a potom formulár receptu v troch krokoch, už predvyplnený
   (od verzie 1.9 bez samostatnej kontroly surovín); na PC tlačidlo „Vyplniť
   z fotky" priamo vo formulári. Uloží sa až tlačidlom Uložiť recept.
   ======================================================================== */
window.aiRecept = (function initAiRecept() {
    if (!window.APP_AI || !window.Suroviny) return null;

    const S = window.Suroviny;
    const modalEl = document.getElementById('aiModal');
    const $ = (id) => document.getElementById(id);

    let fotkaFormulara = null;  // fotka, z ktorej sa vyplnil formulár (môže ísť do galérie)
    let ziadost = null;         // AbortController bežiaceho rozpoznania
    const url = { sprievodca: null, banner: null };

    function nastavUrl(kluc, subor) {
        if (url[kluc]) URL.revokeObjectURL(url[kluc]);
        url[kluc] = subor ? URL.createObjectURL(subor) : null;
        return url[kluc] || '';
    }

    const sklonuj = (n, jedna, dve, pat) => n === 1 ? jedna : (n >= 2 && n <= 4 ? dve : pat);

    /** Pošle fotku na server. Vráti odpoveď so .vysledok, inak vyhodí chybu so zrozumiteľným textom. */
    async function rozpoznaj(subor, signal) {
        let mala = subor;
        try {
            mala = await downscaleImageFile(subor, 1600, 0.85);
        } catch (err) {
            // starý prehliadač — server si fotku zmenší sám
        }
        const formData = new FormData();
        formData.append('action', 'ai_rozpoznaj');
        formData.append('csrf_token', csrfToken());
        formData.append('fotka', mala);

        const res = await fetch('index.php', { method: 'POST', body: formData, signal });
        if (!(res.headers.get('Content-Type') || '').includes('application/json')) {
            throw new Error(res.redirected && /login\.php/.test(res.url)
                ? 'Prihlásenie vypršalo. Obnov stránku a skús to znova.'
                : 'Server odpovedal neočakávane (stav ' + res.status + '). Skús to znova.');
        }
        const data = await res.json();
        if (data.zostava !== undefined) ukazZostava(data.zostava);
        if (!data.success) {
            const chyba = new Error(data.error || 'Fotku sa nepodarilo rozpoznať.');
            chyba.detail = data.detail || '';
            throw chyba;
        }
        if (data.vysledok.typ === 'ine') {
            throw new Error('Na fotke som nenašiel jedlo ani recept. Skús inú fotku, alebo vyplň recept ručne.');
        }
        data.fotka = mala;
        return data;
    }

    function ukazZostava(zostava) {
        const el = $('aiZostava');
        if (!el) return;
        if (zostava === null || zostava === '' || zostava === undefined) {
            el.textContent = '';
        } else if (Number(zostava) <= 0) {
            el.textContent = 'Dnešný počet fotiek je minutý, zajtra to pôjde znova. Recept zatiaľ vyplň ručne.';
        } else if (Number(zostava) <= 5) {
            const n = Number(zostava);
            el.textContent = 'Dnes môžeš poslať ešte ' + n + ' ' + sklonuj(n, 'fotku', 'fotky', 'fotiek') + '.';
        } else {
            el.textContent = '';
        }
    }

    /* ---------- Formulár receptu ---------- */

    /** Vyplní formulár návrhom od AI. opts.dopln = nechať, čo už je vyplnené (PC, formulár je otvorený). */
    function vyplnFormular(navrh, subor, opts = {}) {
        const form = $('recipeForm');
        const nastav = (meno, hodnota) => {
            const el = form.querySelector('[name="' + meno + '"]');
            if (!el || hodnota === null || hodnota === undefined || hodnota === '') return;
            if (opts.dopln && el.value.trim()) return;
            el.value = hodnota;
        };
        nastav('name', navrh.nazov);
        nastav('category', navrh.kategoria);
        nastav('prep_time', navrh.cas_pripravy);
        nastav('servings', navrh.porcie);

        // navrh = podobná surovina z číselníka („Nemysleli ste…?" v riadku suroviny).
        const suroviny = (navrh.suroviny || []).map((r) => ({
            id: r.id || null, nazov: r.nazov, mnozstvo: r.mnozstvo, jednotka: r.jednotka || '', poznamka: r.poznamka || '',
            navrh: r.navrh || null,
        }));
        if (surovinyPicker) {
            surovinyPicker.setRows(opts.dopln ? surovinyPicker.getRows().concat(suroviny) : suroviny);
            nastav('ingredients', navrh.postup);
        } else {
            // Stará databáza bez číselníka surovín: suroviny idú do textu spolu s postupom.
            const text = suroviny.map((r) => [S.formatDavka(r), r.nazov].filter(Boolean).join(' ')).join('\n');
            nastav('ingredients', [text, navrh.postup].filter(Boolean).join('\n\n'));
        }

        fotkaFormulara = subor || null;
        const jeRecept = navrh.typ === 'recept';
        $('aiBannerFoto').src = nastavUrl('banner', fotkaFormulara);
        $('aiBannerNadpis').textContent = jeRecept ? 'Vyplnené z fotky receptu' : 'Vyplnené z fotky jedla';
        $('aiBannerPopis').textContent = jeRecept
            ? 'Skontroluj, či sa všetko prepísalo správne.'
            : 'Suroviny a množstvá sú odhad, skontroluj ich a uprav.';
        // Fotka jedla patrí do galérie, strana z kuchárky predvolene nie.
        $('aiBannerUlozitFotku').checked = !jeRecept;
        $('aiBanner').classList.toggle('d-none', !fotkaFormulara);
        $('aiVyplnitWrap')?.classList.toggle('d-none', !!fotkaFormulara);
        // Telefón: uložiť sa dá z každého kroku, v kroku Suroviny upozornenie na odhad.
        if (formularKroky) formularKroky.poVyplneni(!jeRecept);
    }

    // PC: „Vyplniť z fotky" vo formulári (na telefóne je skryté, tam fotka ide cez okno Nový recept).
    const vstupFormular = $('aiFotkaFormular');
    vstupFormular?.addEventListener('change', async () => {
        const subor = vstupFormular.files && vstupFormular.files[0];
        vstupFormular.value = '';
        if (!subor) return;
        const errorBox = $('recipeFormError');
        errorBox.classList.add('d-none');
        $('aiVyplnitWrap').classList.add('d-none');
        $('aiStavFormular').classList.remove('d-none');
        try {
            const data = await rozpoznaj(subor);
            // Preklep v názve suroviny opravíme rovno, inú podobnú surovinu ponúkne riadok.
            data.vysledok.suroviny.forEach((r) => {
                if (r.navrh && r.navrh.druh === 'preklep') Object.assign(r, { id: r.navrh.id, nazov: r.navrh.nazov, navrh: null });
            });
            vyplnFormular(data.vysledok, data.fotka, { dopln: true });
        } catch (err) {
            errorBox.textContent = err.message + (err.detail ? ' (' + err.detail + ')' : '');
            errorBox.classList.remove('d-none');
            $('aiVyplnitWrap').classList.remove('d-none');
        } finally {
            $('aiStavFormular').classList.add('d-none');
        }
    });

    /* ---------- Okno Nový recept na telefóne ---------- */

    function krok(nazov) {
        modalEl.querySelectorAll('.ai-krok').forEach((el) => el.classList.toggle('d-none', el.dataset.krok !== nazov));
        modalEl.querySelector('.ai-krok[data-krok="' + nazov + '"] .modal-body')?.scrollTo(0, 0);
    }

    /** Zavrie okno a otvorí formulár receptu (prázdny, alebo vyplnený návrhom od AI). */
    function doFormulara(navrh, subor) {
        modalEl.addEventListener('hidden.bs.modal', () => {
            openRecipeModal();
            if (navrh) vyplnFormular(navrh, subor);
        }, { once: true });
        bootstrap.Modal.getInstance(modalEl)?.hide();
    }

    async function spustiRozpoznanie(subor) {
        $('aiNahlad').src = nastavUrl('sprievodca', subor);
        krok('rozpoznavam');
        ziadost = new AbortController();
        try {
            const data = await rozpoznaj(subor, ziadost.signal);
            doFormulara(data.vysledok, data.fotka);
        } catch (err) {
            if (err.name === 'AbortError') {
                krok('vyber');
                return;
            }
            $('aiChybaText').textContent = err.message;
            $('aiChybaDetail').textContent = err.detail || '';
            $('aiChybaDetail').classList.toggle('d-none', !err.detail);
            krok('chyba');
        } finally {
            ziadost = null;
        }
    }

    if (modalEl) {
        ['aiFotkaKamera', 'aiFotkaGaleria'].forEach((id) => {
            const vstup = $(id);
            vstup?.addEventListener('change', () => {
                const subor = vstup.files && vstup.files[0];
                vstup.value = '';
                if (subor) spustiRozpoznanie(subor);
            });
        });
        $('aiRucne').addEventListener('click', () => doFormulara(null));
        $('aiChybaRucne').addEventListener('click', () => doFormulara(null));
        $('aiChybaZnova').addEventListener('click', () => krok('vyber'));
        $('aiZrusit').addEventListener('click', () => {
            if (ziadost) ziadost.abort();
            krok('vyber');
        });
        modalEl.addEventListener('hidden.bs.modal', () => {
            if (ziadost) ziadost.abort();
        });
        ukazZostava($('aiZostava')?.dataset.zostava);
    }

    return {
        /** Pridať v spodnej lište: sprievodca od výberu fotky. */
        otvor() {
            krok('vyber');
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        },
        /** Pri každom otvorení formulára receptu (openRecipeModal). */
        pripravFormular(jeNovy) {
            fotkaFormulara = null;
            $('aiBanner').classList.add('d-none');
            $('aiBannerFoto').src = nastavUrl('banner', null);
            $('aiVyplnitWrap')?.classList.toggle('d-none', !jeNovy);
            $('aiStavFormular')?.classList.add('d-none');
        },
        /** Fotka, ktorá sa má pri uložení pridať do galérie receptu (alebo null). */
        fotkaNaUlozenie: () => (fotkaFormulara && $('aiBannerUlozitFotku').checked ? fotkaFormulara : null),
    };
})();

/* ========================================================================
   Rozloženie pre telefón (verzia 1.7): spodná lišta, okno Viac a zoradenie
   v hlavičke (na PC skryté, d-md-none). Kategórie ako tlačidlá a stav
   prepínača motívu sú od verzie 2.0 spoločné pre telefón aj PC.
   ======================================================================== */
(function initMobilneRozlozenie() {
    const lista = document.getElementById('spodnaLista');
    if (!lista) return;

    const viacEl = document.getElementById('viacModal');
    const shoppingEl = document.getElementById('shoppingModal');

    /** Zavrie otvorené okno (ak nejaké je) a až potom spustí akciu. */
    function poZatvoreniOkien(akcia) {
        const otvorene = Array.from(document.querySelectorAll('.modal.show'));
        if (!otvorene.length) {
            akcia();
            return;
        }
        otvorene[0].addEventListener('hidden.bs.modal', () => poZatvoreniOkien(akcia), { once: true });
        bootstrap.Modal.getInstance(otvorene[0])?.hide();
    }

    // ── Ktorá položka lišty svieti a kedy sa lišta skryje ──
    // Nákup a Viac sú „záložky" (lišta ostane), ostatné okná lištu zakryjú.
    // Bez otvoreného okna svieti stránka, na ktorej si: Recepty alebo Objednané (2.3).
    function oznacPolozku(nazov) {
        lista.querySelectorAll('.spodna-lista-polozka').forEach((btn) => {
            const aktivna = btn.dataset.lista === nazov;
            btn.classList.toggle('is-active', aktivna);
            if (aktivna) btn.setAttribute('aria-current', 'page');
            else btn.removeAttribute('aria-current');
        });
    }
    function aktualizujListu() {
        const otvorene = Array.from(document.querySelectorAll('.modal.show, .modal.je-otvarane'));
        document.body.classList.toggle('lista-skryta', otvorene.some((el) => !el.classList.contains('modal-s-listou')));
        const zalozka = otvorene.find((el) => el.classList.contains('modal-s-listou'));
        oznacPolozku(zalozka === shoppingEl ? 'nakup' : zalozka === viacEl ? 'viac'
            : (objednavky ? objednavky.aktualna() : 'recepty'));
    }
    document.addEventListener('show.bs.modal', (e) => {
        e.target.classList.add('je-otvarane');
        aktualizujListu();
    });
    document.addEventListener('shown.bs.modal', (e) => e.target.classList.remove('je-otvarane'));
    document.addEventListener('hidden.bs.modal', (e) => {
        e.target.classList.remove('je-otvarane');
        aktualizujListu();
    });
    document.addEventListener('stranka-zmenena', aktualizujListu);
    aktualizujListu(); // príchod rovno na index.php#objednane

    // ── Akcie položiek lišty ──
    lista.addEventListener('click', (e) => {
        const btn = e.target.closest('.spodna-lista-polozka');
        if (!btn) return;
        switch (btn.dataset.lista) {
            case 'recepty':
            case 'objednane': {
                // Otvorené okno (Nákup, Viac) najprv zavrieme, potom stránka (alebo posun hore, ak už na nej si).
                const stranka = btn.dataset.lista;
                if (document.querySelector('.modal.show')) {
                    poZatvoreniOkien(() => { if (objednavky && objednavky.aktualna() !== stranka) objednavky.prejdi(stranka); });
                } else if (objednavky) {
                    objednavky.prejdi(stranka);
                } else {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
                break;
            }
            case 'pridat':
                poZatvoreniOkien(() => (window.aiRecept ? window.aiRecept.otvor() : openRecipeModal()));
                break;
            case 'nakup':
                if (!shoppingEl?.classList.contains('show')) openShoppingModal(); // sám zavrie iné okno
                break;
            case 'viac':
                if (viacEl && !viacEl.classList.contains('show')) {
                    poZatvoreniOkien(() => bootstrap.Modal.getOrCreateInstance(viacEl).show());
                }
                break;
        }
    });

    // ── Okno Viac: prepínač motívu ovláda to isté tlačidlo ako bočná ponuka na PC (theme.js) ──
    const temaPrepinac = document.getElementById('viacTemaPrepinac');
    const temaTlacidlo = document.getElementById('themeToggle');
    const nastavPrepinac = () => {
        const tmavy = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        if (temaPrepinac) temaPrepinac.checked = tmavy;
        temaTlacidlo?.setAttribute('aria-pressed', tmavy ? 'true' : 'false');
    };
    nastavPrepinac();
    // theme.js prepne motív vo vlastnom poslucháčovi, stav prepínača sa nastaví až po ňom.
    temaTlacidlo?.addEventListener('click', () => setTimeout(nastavPrepinac));
    viacEl?.addEventListener('show.bs.modal', nastavPrepinac);
    temaPrepinac?.addEventListener('change', () => {
        document.getElementById('themeToggle')?.click();
        nastavPrepinac();
    });

    // ── Kategórie ako tlačidlá: nastavia výber kategórie a spustia ten istý filter ──
    const kategoriaSelect = document.getElementById('recipeCategoryFilter');
    const cipy = Array.from(document.querySelectorAll('.kategoria-cip'));
    function oznacCip(hodnota) {
        cipy.forEach((cip) => {
            const aktivny = cip.dataset.kategoria === hodnota;
            cip.classList.toggle('is-active', aktivny);
            cip.setAttribute('aria-pressed', aktivny ? 'true' : 'false');
        });
    }
    cipy.forEach((cip) => cip.addEventListener('click', () => {
        if (!kategoriaSelect) return;
        kategoriaSelect.value = cip.dataset.kategoria;
        kategoriaSelect.dispatchEvent(new Event('change'));
    }));
    kategoriaSelect?.addEventListener('change', () => oznacCip(kategoriaSelect.value));

    // ── Zoradenie v hlavičke telefónu = ten istý výber ako na PC ──
    const zoradenie = document.getElementById('recipeSortSelect');
    const zoradenieMobil = document.getElementById('recipeSortMobil');
    if (zoradenie && zoradenieMobil) {
        zoradenieMobil.value = zoradenie.value;
        zoradenieMobil.addEventListener('change', () => {
            zoradenie.value = zoradenieMobil.value;
            zoradenie.dispatchEvent(new Event('change'));
        });
        zoradenie.addEventListener('change', () => { zoradenieMobil.value = zoradenie.value; });
    }

    // ── Príchod zo Správy (verzia 2.1): spodná lišta a bočná ponuka v admin/ vedú na
    //    index.php?otvor=pridat | nakup | viac a tu sa otvorí to isté okno ako z lišty.
    //    ?otvor=objednavky prejde na stránku Objednané jedlo (2.3, predtým okno). ──
    function otvorZAdresy() {
        const otvor = new URLSearchParams(location.search).get('otvor');
        if (!otvor) return;
        history.replaceState(null, '', location.pathname);
        // Bez internetu (uložená kópia stránky) sa pridať ani nakupovať nedá (objednávky si pozrieť dá).
        if (document.documentElement.hasAttribute('data-offline') && otvor !== 'viac' && otvor !== 'objednavky') return;
        const telefon = window.matchMedia('(max-width: 767.98px)').matches;
        if (otvor === 'pridat') {
            // Na telefóne ako tlačidlo Pridať v lište (recept z fotky, ak je zapnutý), na PC formulár.
            if (telefon && window.aiRecept) window.aiRecept.otvor();
            else openRecipeModal();
        } else if (otvor === 'nakup' && shoppingEl) {
            openShoppingModal();
        } else if (otvor === 'viac' && telefon && viacEl) {
            bootstrap.Modal.getOrCreateInstance(viacEl).show();
        } else if (otvor === 'objednavky' && objednavky) {
            objednavky.prejdi('objednane');
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', otvorZAdresy);
    else otvorZAdresy();
})();
