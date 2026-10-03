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
       Vyhľadávací výber suroviny.
       input musí byť vnútri prvku s triedou .surovina-combo (position: relative).
       options:
         items      — pole {id, nazov, kategoria} (celý číselník); číta sa pri každom
                      hľadaní, takže suroviny pridané do poľa neskôr sa hneď ponúkajú
         isTaken    — (item) => true, ak sa surovina už nemá ponúkať
         allowNew   — ponúknuť „Pridať ako novú surovinu" (predvolene áno)
         newLabel   — (nazov) => text možnosti pre položku, ktorá v číselníku nie je
         onPick     — (item) po výbere; nová surovina má id === null a isNew === true
       -------------------------------------------------------------------- */
    let comboCounter = 0;

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
                    name.textContent = option.item.nazov;
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

    global.Suroviny = { normKey, displayName, formatMnozstvo, formatDavka, attachCombobox, createDetailFields, readDetailFields };
})(window);
