/**
 * suroviny.js — spoločné pomôcky pre prácu so surovinami: normalizácia
 * názvov, vyhľadávací výber (combobox) a polia množstvo / jednotka / poznámka.
 * Používa ho formulár receptu (script.js) aj prevodná stránka v admin/.
 */
(function (global) {
    'use strict';

    /**
     * Kľúč na porovnávanie: malé písmená, bez diakritiky, jedna medzera medzi
     * slovami. Musí dávať rovnaký výsledok ako surovina_key() vo functions.php.
     */
    function normKey(text) {
        return String(text || '')
            .toLowerCase()
            .normalize('NFKD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    /** Názov na zobrazenie: bez zbytočných medzier, s veľkým prvým písmenom. */
    function displayName(text) {
        const clean = String(text || '').replace(/\s+/g, ' ').trim();
        return clean ? clean.charAt(0).toUpperCase() + clean.slice(1) : '';
    }

    /** 200 → „200", 0.5 → „0,5", 1.25 → „1,25". Prázdne alebo neplatné → „". */
    function formatMnozstvo(value) {
        if (value === null || value === undefined || value === '') return '';
        const number = Number(String(value).replace(',', '.'));
        if (!isFinite(number) || number <= 0) return '';
        return String(Math.round(number * 100) / 100).replace('.', ',');
    }

    /** „200 g" / „2" / „štipka" — množstvo s jednotkou tak, ako sa číta v recepte. */
    function formatDavka(row) {
        return [formatMnozstvo(row.mnozstvo), row.jednotka || ''].filter(Boolean).join(' ');
    }

    /* --------------------------------------------------------------------
       Prevod jednotiek pre prepočítané množstvá (prepočet porcií, nákupný
       zoznam). Prevádza sa len v rámci jednej skupiny — lyžicu na gramy
       previesť nejde, každá surovina má inú hustotu:
         hmotnosť  g ↔ kg        (od 1000 g kilogramy)
         objem     ml, dl, l → ml alebo l (od 1000 ml litre)
         lyžice    ČL ↔ PL       (1 PL = 3 ČL)
       Lyžice, šálky a kusy sa zobrazujú v zlomkoch (1½ ČL), lebo „0,33 PL"
       sa odmerať nedá: ČL a šálky po štvrtinách, PL a kusy po polovičkách,
       štipka na celé. V nákupe sa kusy a štipky zaokrúhľujú nahor na celé.
       -------------------------------------------------------------------- */
    const ZLOMKY = { 25: '¼', 50: '½', 75: '¾' };
    const KUSOVE = ['ks', 'balenie', 'plátok', 'strúčik', ''];

    /** Číslo zaokrúhlené na násobok kroku (½, ¼) a zapísané so zlomkom: 1.5 → „1½", 0.25 → „¼". */
    function zlomkom(value, krok, nahor) {
        const pocet = value / krok;
        const zaokruhlene = Math.max(1, nahor ? Math.ceil(pocet - 1e-9) : Math.round(pocet)) * krok;
        const cele = Math.floor(zaokruhlene + 1e-9);
        const zlomok = ZLOMKY[Math.round((zaokruhlene - cele) * 100)] || '';
        return { cislo: zaokruhlene, text: (cele > 0 ? String(cele) : '') + zlomok };
    }

    /** Metrické množstvo: veľké na celé čísla (313), stredné na desatiny (12,5), malé na stotiny (0,63). */
    function metricke(value) {
        if (value >= 100) return Math.round(value);
        if (value >= 10) return Math.round(value * 10) / 10;
        return Math.max(0.01, Math.round(value * 100) / 100);
    }

    /**
     * Množstvo prevedené na najvhodnejšiu jednotku tej istej skupiny.
     * opts.nakup: kusy sa zaokrúhľujú nahor na celé (radšej o vajce viac, než menej).
     * Vráti { mnozstvo, jednotka, text } — text je napr. „1,25 kg", „1½ ČL", „3 ks".
     */
    function prevedDavku(mnozstvo, jednotka, opts = {}) {
        const number = Number(String(mnozstvo ?? '').replace(',', '.'));
        jednotka = jednotka || '';
        if (!isFinite(number) || number <= 0) {
            return { mnozstvo: null, jednotka, text: jednotka };
        }
        const hotovo = (cislo, jed, text) => ({
            mnozstvo: cislo, jednotka: jed, text: [text ?? formatMnozstvo(cislo), jed].filter(Boolean).join(' '),
        });

        if (jednotka === 'g' || jednotka === 'kg') {
            const g = jednotka === 'kg' ? number * 1000 : number;
            return g >= 1000 ? hotovo(Math.round(g / 10) / 100, 'kg') : hotovo(metricke(g), 'g');
        }
        if (jednotka === 'ml' || jednotka === 'dl' || jednotka === 'l') {
            const ml = number * ({ ml: 1, dl: 100, l: 1000 }[jednotka]);
            return ml >= 1000 ? hotovo(Math.round(ml / 10) / 100, 'l') : hotovo(metricke(ml), 'ml');
        }
        if (jednotka === 'ČL' || jednotka === 'PL') {
            const cl = jednotka === 'PL' ? number * 3 : number;
            const pl = cl / 3;
            // Na polievkové lyžice, keď to vyjde aspoň na 1 PL a odmeria sa to po polovičkách
            // (6 ČL → 2 PL), alebo keď to v PL bolo už zadané. Inak čajové lyžičky.
            const pekneVPL = Math.abs(pl * 2 - Math.round(pl * 2)) < 0.08;
            if (pl >= 1 && (pekneVPL || jednotka === 'PL')) {
                const s = zlomkom(pl, 0.5);
                return hotovo(s.cislo, 'PL', s.text);
            }
            const s = zlomkom(cl, 0.25);
            return hotovo(s.cislo, 'ČL', s.text);
        }
        if (jednotka === 'šálka') {
            const s = zlomkom(number, 0.25);
            return hotovo(s.cislo, jednotka, s.text);
        }
        if (KUSOVE.includes(jednotka)) {
            // V recepte na polovice (1½ vajca), v nákupe nahor na celé — pol vajca sa kúpiť nedá.
            const s = opts.nakup ? zlomkom(number, 1, true) : zlomkom(number, 0.5);
            return hotovo(s.cislo, jednotka, s.text);
        }
        if (jednotka === 'štipka') {
            const s = zlomkom(number, 1, opts.nakup);
            return hotovo(s.cislo, jednotka, s.text);
        }
        return hotovo(metricke(number), jednotka);
    }

    /* --------------------------------------------------------------------
       Vyhľadávací výber suroviny.
       input musí byť vnútri prvku s triedou .surovina-combo (position: relative).
       options:
         items      — pole {id, nazov, kategoria} (celý číselník); číta sa pri každom
                      hľadaní, takže suroviny pridané do poľa neskôr sa hneď ponúkajú
         isTaken    — (item) => true, ak sa surovina už nemá ponúkať
         allowNew   — ponúknuť „Pridať ako novú surovinu" (predvolene áno)
         newLabel   — (nazov) => text možnosti pre položku, ktorá v číselníku nie je
         onPick     — (item) po výbere; nová surovina má id === null a isNew === true
         podobne    — pri novom názve ponúknuť podobné existujúce suroviny, napr. pri
                      preklepe „Paradjky" → „Nemysleli ste Paradajky?" (predvolene áno)
       -------------------------------------------------------------------- */
    let comboCounter = 0;

    /**
     * Koľko úprav (vloženie, zmazanie, zámena alebo prehodenie dvoch susedných
     * písmen) treba, aby z a vzniklo b. Nad max sa počítanie ukončí a vráti max + 1.
     */
    function vzdialenost(a, b, max) {
        if (Math.abs(a.length - b.length) > max) return max + 1;
        let pred = null;
        let riadok = Array.from({ length: b.length + 1 }, (_, j) => j);
        for (let i = 1; i <= a.length; i++) {
            const novy = [i];
            let najmenej = i;
            for (let j = 1; j <= b.length; j++) {
                const cena = a[i - 1] === b[j - 1] ? 0 : 1;
                let d = Math.min(riadok[j] + 1, novy[j - 1] + 1, riadok[j - 1] + cena);
                if (pred && i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) {
                    d = Math.min(d, pred[j - 2] + 1);
                }
                novy.push(d);
                if (d < najmenej) najmenej = d;
            }
            if (najmenej > max) return max + 1;
            pred = riadok;
            riadok = novy;
        }
        return riadok[b.length];
    }

    /** Povolený počet preklepov podľa dĺžky: krátke slová 1, dlhšie (od 9 znakov) 2. */
    function tolerancia(dlzka) {
        return dlzka >= 9 ? 2 : 1;
    }

    function attachCombobox(input, options) {
        const items = options.items || [];
        function keyedItems() {
            items.forEach((item) => {
                if (item.key === undefined) item.key = normKey(item.nazov);
            });
            return items;
        }
        const isTaken = options.isTaken || (() => false);
        const allowNew = options.allowNew !== false;
        const podobne = options.podobne !== false;
        const maxResults = 8;
        const listId = 'surovinaOptions' + (++comboCounter);

        const list = document.createElement('div');
        list.className = 'surovina-options';
        list.id = listId;
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        input.parentNode.appendChild(list);

        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', listId);

        let current = [];   // práve ponúkané možnosti
        let active = -1;    // index zvýraznenej možnosti

        function close() {
            list.hidden = true;
            list.replaceChildren();
            current = [];
            active = -1;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }

        function setActive(index) {
            active = index;
            Array.from(list.children).forEach((el, i) => {
                const on = i === active;
                el.classList.toggle('is-active', on);
                el.setAttribute('aria-selected', on ? 'true' : 'false');
                if (on) {
                    input.setAttribute('aria-activedescendant', el.id);
                    el.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        function pick(option) {
            if (!option || option.disabled) return;
            close();
            options.onPick(option.item);
        }

        function render() {
            const raw = input.value;
            const query = normKey(raw);
            if (!query) { close(); return; }

            const tokens = query.split(' ');
            const scored = [];
            let exact = null;
            keyedItems().forEach((item) => {
                if (item.key === query) exact = item;
                if (!tokens.every((t) => item.key.includes(t)) || isTaken(item)) return;
                // Najprv názvy začínajúce hľadaným textom, potom slová, potom zhoda kdekoľvek.
                let score = 2;
                if (item.key.startsWith(query)) score = 0;
                else if (item.key.split(' ').some((word) => word.startsWith(tokens[0]))) score = 1;
                scored.push({ item, score });
            });
            scored.sort((a, b) => a.score - b.score
                || a.item.key.length - b.item.key.length
                || a.item.nazov.localeCompare(b.item.nazov, 'sk'));

            current = scored.slice(0, maxResults).map((s) => ({ item: s.item }));

            // Nový názov, ktorý sa len málo líši od existujúcej suroviny (preklep):
            // ponúkneme ju pred „Pridať ako novú". Keď inak nič nesedí, je zvýraznená
            // ako prvá, takže Enter vyberie existujúcu surovinu namiesto preklepu.
            if (!exact && allowNew && podobne && query.length >= 3) {
                const uz = new Set(current.map((o) => o.item));
                const max = tolerancia(query.length);
                keyedItems()
                    .filter((item) => !uz.has(item) && !isTaken(item))
                    .map((item) => ({ item, d: vzdialenost(query, item.key, max) }))
                    .filter((x) => x.d <= max)
                    .sort((a, b) => a.d - b.d || a.item.key.length - b.item.key.length)
                    .slice(0, 3)
                    .forEach((x) => current.push({ item: x.item, podobna: true }));
            }

            if (exact && isTaken(exact)) {
                current.push({ disabled: true, label: '„' + exact.nazov + '“ už v recepte je' });
            } else if (!exact && allowNew && query.length >= 2) {
                const nazov = displayName(raw);
                current.push({ item: { id: null, nazov, kategoria: '', key: query, isNew: true }, isNew: true });
            }

            if (!current.length) { close(); return; }

            list.replaceChildren(...current.map((option, i) => {
                const el = document.createElement('div');
                el.className = 'surovina-option';
                el.id = listId + '-' + i;
                el.setAttribute('role', 'option');
                if (option.disabled) {
                    el.classList.add('is-disabled');
                    el.setAttribute('aria-disabled', 'true');
                    el.textContent = option.label;
                } else if (option.isNew) {
                    el.classList.add('is-new');
                    const icon = document.createElement('i');
                    icon.className = 'bi bi-plus-circle me-2';
                    el.append(icon, options.newLabel
                        ? options.newLabel(option.item.nazov)
                        : 'Pridať „' + option.item.nazov + '“ ako novú surovinu');
                } else {
                    const name = document.createElement('span');
                    if (option.podobna) {
                        el.classList.add('is-podobna');
                        const otazka = document.createElement('span');
                        otazka.className = 'surovina-option-otazka';
                        otazka.textContent = 'Nemysleli ste ';
                        const nazov = document.createElement('strong');
                        nazov.textContent = option.item.nazov;
                        name.append(otazka, nazov, '?');
                    } else {
                        name.textContent = option.item.nazov;
                    }
                    el.appendChild(name);
                    if (option.item.kategoria) {
                        const cat = document.createElement('span');
                        cat.className = 'surovina-option-cat';
                        cat.textContent = option.item.kategoria;
                        el.appendChild(cat);
                    }
                }
                // mousedown + preventDefault: klik na možnosť nesmie vziať fokus z poľa
                el.addEventListener('mousedown', (e) => e.preventDefault());
                el.addEventListener('click', () => pick(option));
                return el;
            }));
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            setActive(current[0].disabled ? -1 : 0);
        }

        input.addEventListener('input', render);
        input.addEventListener('focus', render);
        input.addEventListener('blur', close);
        input.addEventListener('keydown', (e) => {
            const open = !list.hidden;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (!open) { render(); return; }
                e.preventDefault();
                const step = e.key === 'ArrowDown' ? 1 : -1;
                const count = current.length;
                let next = active;
                for (let i = 0; i < count; i++) {
                    next = (next + step + count) % count;
                    if (!current[next].disabled) break;
                }
                setActive(next);
            } else if (e.key === 'Enter') {
                // Enter v tomto poli vyberá surovinu — nikdy neodosiela celý formulár.
                e.preventDefault();
                if (open && active >= 0) pick(current[active]);
            } else if (e.key === 'Escape' && open) {
                // Zavrie len ponuku, nie celé modálne okno.
                e.preventDefault();
                e.stopPropagation();
                close();
            }
        });

        return {
            close,
            /** Je surovina s týmto názvom v číselníku? Vráti ju, alebo null. */
            find(text) {
                const key = normKey(text);
                return keyedItems().find((item) => item.key === key) || null;
            },
        };
    }

    /* --------------------------------------------------------------------
       Polia množstvo / jednotka / poznámka pre jeden riadok suroviny.
       -------------------------------------------------------------------- */
    function createDetailFields(row, jednotky, labelName) {
        const wrap = document.createElement('div');
        wrap.className = 'surovina-fields';

        const mnozstvo = document.createElement('input');
        mnozstvo.type = 'text';
        mnozstvo.inputMode = 'decimal';
        mnozstvo.className = 'form-control form-control-sm sur-mnozstvo';
        mnozstvo.placeholder = 'Množstvo';
        mnozstvo.maxLength = 10;
        mnozstvo.value = formatMnozstvo(row.mnozstvo);
        mnozstvo.setAttribute('aria-label', 'Množstvo: ' + labelName);

        const jednotka = document.createElement('select');
        jednotka.className = 'form-select form-select-sm sur-jednotka';
        jednotka.setAttribute('aria-label', 'Jednotka: ' + labelName);
        jednotka.add(new Option('—', ''));
        (jednotky || []).forEach((j) => jednotka.add(new Option(j, j)));
        jednotka.value = row.jednotka || '';

        const poznamka = document.createElement('input');
        poznamka.type = 'text';
        poznamka.className = 'form-control form-control-sm sur-poznamka';
        poznamka.placeholder = 'Poznámka';
        poznamka.maxLength = 100;
        poznamka.value = row.poznamka || '';
        poznamka.setAttribute('aria-label', 'Poznámka: ' + labelName);

        wrap.append(mnozstvo, jednotka, poznamka);
        return wrap;
    }

    function readDetailFields(container) {
        return {
            mnozstvo: container.querySelector('.sur-mnozstvo').value.trim(),
            jednotka: container.querySelector('.sur-jednotka').value,
            poznamka: container.querySelector('.sur-poznamka').value.trim(),
        };
    }

    global.Suroviny = { normKey, displayName, formatMnozstvo, formatDavka, prevedDavku, attachCombobox, createDetailFields, readDetailFields };
})(window);
