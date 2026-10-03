// Appka má bežať len šifrovane. Ak ju niekto otvorí cez http://, hneď ho prepneme
// na https://. Robí sa to tu v prehliadači (tento súbor načítava každá stránka ako
// prvý), lebo prehliadač vždy vie, cez čo stránku naozaj otvoril — presmerovanie na
// serveri by sa za proxy hostingu mohlo zacykliť.
// Výnimkou je lokálny server: localhost a adresy domácej siete (192.168.x.x, 10.x.x.x,
// 172.16–31.x.x, mená končiace na .local/.lan), kde HTTPS zvyčajne nie je.
const isLocalHost = /^(localhost|127\.|\[::1\]|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(location.hostname)
    || /\.(local|lan|home|test)$/.test(location.hostname);
if (location.protocol === 'http:' && !isLocalHost) {
    location.replace('https://' + location.host + location.pathname + location.search + location.hash);
}

/* ========================================================================
   theme.js — spoločné prepínanie témy (dark/light) pre index.php,
   login.php aj admin/index.php.

   Načítava sa v <head> BEZ defer/async, aby sa téma nastavila ešte pred
   vykreslením stránky (inak by stránka pri načítaní "bliklo" nesprávnou
   farbou). Voľba sa ukladá do localStorage pod kľúčom 'nas-theme';
   pri prvej návšteve sa použije preferencia operačného systému.

   Tlačidlo prepínača: ľubovoľný element s id="themeToggle", ikonka
   v ňom s id="themeIcon".
   ======================================================================== */
(function () {
    const STORAGE_KEY = 'nas-theme';
    const html = document.documentElement;

    function readSaved() {
        try { return localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
    }

    function systemPrefersDark() {
        return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }

    // 1) Nastav tému okamžite (ešte v <head>)
    const initial = readSaved() || (systemPrefersDark() ? 'dark' : 'light');
    html.setAttribute('data-bs-theme', initial);

    // 2) Po načítaní DOM napoj tlačidlo prepínača, ak na stránke je
    function bindToggle() {
        const toggle = document.getElementById('themeToggle');
        const icon = document.getElementById('themeIcon');
        if (!toggle) return;

        function applyIcon(theme) {
            if (icon) icon.className = theme === 'dark' ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill';
        }

        applyIcon(html.getAttribute('data-bs-theme') || 'dark');

        toggle.addEventListener('click', () => {
            const next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-bs-theme', next);
            try { localStorage.setItem(STORAGE_KEY, next); } catch (e) { /* private mode a pod. */ }
            applyIcon(next);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindToggle);
    } else {
        bindToggle();
    }
})();
