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
   Suroviny vo formulári receptu — vyhľadávacie pole pridáva riadky do
   zoznamu; každý riadok má nepovinné množstvo, jednotku a poznámku.
   Pri odoslaní sa zoznam zapíše ako JSON do skrytého poľa suroviny_json.
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

    function addRow(row) {
        const nazov = S.displayName(row.nazov);
        const key = S.normKey(nazov);
        if (!key || hasKey(key)) return;

        const el = document.createElement('div');
        el.className = 'surovina-row';
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

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm surovina-row-remove';
        remove.setAttribute('aria-label', 'Odstrániť surovinu ' + nazov);
        remove.title = 'Odstrániť';
        remove.innerHTML = '<i class="bi bi-x-lg"></i>';
        remove.addEventListener('click', () => {
            el.remove();
            input.focus();
        });
        head.appendChild(remove);

        el.append(head, S.createDetailFields(row, jednotky, nazov));
        list.appendChild(el);
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
            addRow({ id: item.id, nazov: item.nazov });
            input.value = '';
            input.focus();
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
    const fabEl = document.getElementById('shoppingFab');
    let items = [];

    function openModal() {
        const show = () => {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            call('shopping_get');
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

    async function call(action, params) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
        Object.entries(params || {}).forEach(([key, value]) => formData.append(key, value));
        try {
            const res = await fetch('index.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.items) {
                items = data.items;
                render();
            }
            if (!data.success) showToast(data.error || 'Akcia zlyhala.', true);
            return data;
        } catch (err) {
            showToast('Chyba pripojenia k serveru.', true);
            return { success: false };
        }
    }

    /** „1,5 kg Múka hladká" — gramy a mililitre od 1000 vyššie ukážeme v kg a l. */
    function itemLabel(item) {
        let mnozstvo = item.mnozstvo;
        let jednotka = item.jednotka;
        if (mnozstvo && mnozstvo >= 1000 && (jednotka === 'g' || jednotka === 'ml')) {
            mnozstvo = mnozstvo / 1000;
            jednotka = jednotka === 'g' ? 'kg' : 'l';
        }
        const davka = S.formatDavka({ mnozstvo, jednotka });
        return { davka, text: (davka ? davka + ' ' : '') + item.nazov };
    }

    function itemRow(item) {
        const row = document.createElement('div');
        row.className = 'shopping-item' + (item.kupene ? ' is-bought' : '');

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

        if (countEl) {
            countEl.textContent = open.length;
            countEl.classList.toggle('d-none', open.length === 0);
        }
        // Plávajúce tlačidlo košíka je vidno len vtedy, keď je čo kúpiť.
        if (fabEl) {
            fabEl.classList.toggle('d-none', open.length === 0);
            fabEl.querySelector('.shopping-fab-count').textContent = open.length;
        }

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

    // Reset náhľadu fotky (predvolene skrytý, kým nezistíme, či recept fotku má)
    previewWrap.classList.add('d-none');
    previewImg.src = '';
    if (removeCheckbox) removeCheckbox.checked = false;

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

        if (data.imagePath) {
            previewImg.src = data.imagePath;
            previewWrap.classList.remove('d-none');
        }
    } else {
        title.innerHTML = '<i class="bi bi-journal-plus me-2"></i>Pridať recept';
        recipeIdField.value = '';
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('recipeModal')).show();
}

/* ========================================================================
   Modal "Náhľad receptu" — zobrazí detail po kliknutí na názov receptu.
   Z náhľadu sa dá jedným klikom prejsť rovno do editácie.
   ======================================================================== */
function openRecipeViewModal(data) {
    document.getElementById('viewRecipeName').textContent = data.name || '';

    const imgEl = document.getElementById('viewRecipeImage');
    const imgWrap = document.getElementById('viewRecipeImageWrap');
    if (data.imagePath) {
        imgEl.src = data.imagePath;
        imgWrap.classList.remove('d-none');
    } else {
        imgEl.src = '';
        imgWrap.classList.add('d-none');
    }

    const categoryBadge = document.getElementById('viewRecipeCategory');
    if (data.category) {
        categoryBadge.textContent = data.category;
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

    const editBtn = document.getElementById('viewRecipeEditBtn');
    editBtn.onclick = () => {
        bootstrap.Modal.getInstance(document.getElementById('recipeViewModal'))?.hide();
        openRecipeModal(data);
    };

    const shareBtn = document.getElementById('viewRecipeShareBtn');
    shareBtn.onclick = () => shareRecipe(data);

    bootstrap.Modal.getOrCreateInstance(document.getElementById('recipeViewModal')).show();
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
 * Prepočítané množstvo zaokrúhlené tak, aby sa dalo odmerať: veľké hodnoty
 * na celé čísla (313 g), stredné na jedno desatinné miesto (12,5), malé na dve (0,63).
 */
function scaleMnozstvo(value, factor) {
    const number = Number(value);
    if (!isFinite(number) || number <= 0) return null;
    const scaled = number * factor;
    if (factor === 1) return number;
    if (scaled >= 100) return Math.round(scaled);
    if (scaled >= 10) return Math.round(scaled * 10) / 10;
    return Math.max(0.01, Math.round(scaled * 100) / 100);
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
        const scaledRow = row.mnozstvo ? Object.assign({}, row, { mnozstvo: scaleMnozstvo(row.mnozstvo, factor) }) : row;
        const davka = formatSurovinaDavka(scaledRow);
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

    // „Do nákupu" pridá suroviny pre práve zobrazený počet porcií.
    const shoppingBtn = document.getElementById('viewRecipeShoppingBtn');
    if (shoppingBtn) {
        shoppingBtn.classList.toggle('d-none', suroviny.length === 0);
        shoppingBtn.onclick = () => shopping && shopping.addRecipe(data.id, canScale ? target : null, shoppingBtn);
    }

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
    if (data.category) lines.push(`Kategória: ${data.category}`);
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
            return;
        }

        if (surovinyPicker) {
            // Rozpísaná, ale nepotvrdená surovina by sa inak potichu stratila.
            const pending = surovinyPicker.pendingText();
            if (pending) {
                errorBox.textContent = 'Surovinu „' + pending + '“ ešte potvrď výberom zo zoznamu, alebo pole vymaž.';
                errorBox.classList.remove('d-none');
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
                return;
            }

            if (data.success) {
                bootstrap.Modal.getInstance(modalEl).hide();
                showToast(isEdit ? 'Recept bol upravený.' : 'Recept bol pridaný.');
                // Najjednoduchší spoľahlivý spôsob ako zobraziť správne
                // zoradenú/aktualizovanú tabuľku je znova načítať stránku.
                setTimeout(() => location.reload(), 400);
            } else {
                errorBox.textContent = data.error || 'Niečo sa pokazilo, skús to znova.';
                errorBox.classList.remove('d-none');
            }
        } catch (err) {
            errorBox.textContent = 'Chyba pripojenia k serveru (' + (err.message || err) + '). Skús to znova.';
            errorBox.classList.remove('d-none');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Uložiť recept';
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
            madeBtn.disabled = true;
            try {
                const data = await postAction('mark_made', madeBtn.dataset.id);
                if (data.success) {
                    showToast('Skvelé, chuť do jedla! 🍽️');
                    setTimeout(() => location.reload(), 400);
                } else {
                    showToast(data.error || 'Akcia zlyhala.', true);
                    madeBtn.disabled = false;
                }
            } catch (err) {
                showToast('Chyba pripojenia k serveru.', true);
                madeBtn.disabled = false;
            }
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
(function initRecipeRatings() {
    const widgets = document.querySelectorAll('.recipe-rating');
    if (!widgets.length) return;

    const csrfToken = document.querySelector('#recipeForm input[name="csrf_token"]')?.value || '';

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

    widgets.forEach(widget => {
        const recipeId = widget.dataset.recipeId;
        const starsEl = widget.querySelector('.rating-stars');
        const summaryEl = widget.querySelector('.rating-summary');
        let myRating = parseInt(widget.dataset.userRating, 10) || 0;
        let submitting = false;

        starsEl.querySelectorAll('.rating-star').forEach(starEl => {
            const value = parseInt(starEl.dataset.value, 10);

            // Náhľad pri prejdení myšou, po odídení sa vráti na uložené hodnotenie
            starEl.addEventListener('mouseenter', () => paintStars(starsEl, value));
            starEl.addEventListener('mouseleave', () => paintStars(starsEl, myRating));

            starEl.addEventListener('click', async () => {
                if (submitting) return;
                submitting = true;

                try {
                    const data = await postRating(recipeId, value);
                    if (data.success) {
                        myRating = data.yourRating;
                        paintStars(starsEl, myRating);
                        summaryEl.textContent = `${data.average.toFixed(1).replace('.', ',')} ★ (${data.count})`;
                        showToast('Vďaka za hodnotenie!');
                    } else {
                        showToast(data.error || 'Hodnotenie sa nepodarilo uložiť.', true);
                    }
                } catch (err) {
                    showToast('Chyba pripojenia k serveru.', true);
                } finally {
                    submitting = false;
                }
            });
        });
    });
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
                
                const dateObj = new Date(log.created_at);
                const dateStr = dateObj.toLocaleDateString('sk-SK') + ' ' + dateObj.toLocaleTimeString('sk-SK', {hour: '2-digit', minute:'2-digit'});
                const noteHtml = log.note ? `<div class="small text-muted mt-1 fst-italic px-3 py-1 border-start border-2 border-secondary-subtle">"${log.note}"</div>` : '';

                html += `
                    <li class="list-group-item bg-transparent px-0 py-3">
                        <div class="d-flex w-100 justify-content-between align-items-start">
                            <div>
                                <i class="bi ${icon} ${color} me-2"></i>
                                <strong>${String(log.username || 'Neznámy').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}</strong> <span class="text-secondary">${actionText}</span>
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
            bodyEl.innerHTML = `<div class="alert alert-danger m-3">${data.error || 'Chyba načítania.'}</div>`;
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
    if (window.APP_OPEN_RECIPE) {
        const card = tableBody.querySelector('.recipe-row[data-recipe-id="' + window.APP_OPEN_RECIPE + '"]');
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