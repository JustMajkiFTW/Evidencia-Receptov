# Evidencia receptov

Rodinná webová appka na recepty. Beží v prehliadači a dá sa pridať na plochu telefónu ako aplikácia (PWA). Je pre malú skupinu ľudí, ktorí si recepty spravujú spoločne, a má veľké ovládacie prvky, aby sa s ňou dobre pracovalo aj starším.

## Čo appka vie

- **Recepty** s kategóriou, časom prípravy, porciami, postupom a **galériou fotiek** (každé varenie môže mať vlastnú fotku s dátumom).
- **Suroviny z číselníka.** Vyhľadávanie ignoruje veľké písmená a diakritiku, pri preklepe appka ponúkne „Nemysleli ste Paradajky?“.
- **Prepočet porcií** s prevodom jednotiek (2000 g → 2 kg, 0,5 PL → 1½ ČL).
- **Spoločný nákupný zoznam.** Zlúči suroviny z viacerých receptov, dá sa odškrtávať a poslať do Google Keep. Zmeny od ostatných sa ukážu do pár sekúnd.
- **Uvarené, hodnotenie a história.** Pri recepte je vidno, kedy sa naposledy varil, a po 90 dňoch appka pripomenie, že by sa hodil zopakovať.
- **Upozornenia do telefónu** na nový recept, nové hodnotenie a dlho nevarené recepty.
- **Prihlásenie** heslom alebo odtlačkom prsta či Face ID, s možnosťou zostať prihlásený 90 dní.
- **Recepty bez internetu** (len na čítanie).
- **Recept z fotky.** AI (Gemini, Claude alebo OpenAI) z fotky jedla alebo kuchárky navrhne celý recept, ty ho skontroluješ a uložíš. Nepovinné.
- **Rozloženie pre telefón** (spodná lišta, formulár v troch krokoch) a **pre PC** (bočná ponuka), svetlý a tmavý vzhľad. Rýchle dvojité ťuknutie stránku na telefóne nepriblíži, zväčšiť sa dá dvoma prstami.
- **Správa** používateľov, kategórií a surovín pre admina.

## Požiadavky

| | |
|---|---|
| Server | Apache s povoleným `.htaccess` (pri nginx pozri [nižšie](#nginx)) |
| PHP | 8.4.1 alebo novšie, rozšírenia `pdo_mysql`, `openssl`, `curl`, `mbstring`, `gd` |
| Databáza | MariaDB 10.5 alebo novšia |
| Composer | Na stiahnutie knižníc do `vendor/`. Stačí ho mať na vlastnom počítači. |
| HTTPS | Potrebné pre pridanie na plochu, upozornenia a biometriu |

Node.js ani žiadne zostavovanie netreba, súbory sa nahrávajú tak, ako sú.

> **Prečo ešte Composer:** upozornenia už žiadnu knižnicu nepotrebujú, posiela ich vlastný kód v `includes/webpush.php`. Biometria (`webauthn/`) však stojí na knižnici `web-auth/webauthn-lib` a `includes/functions.php` načítava `vendor/autoload.php` na každej stránke. Bez `vendor/` appka nebeží vôbec.

## Inštalácia na webhosting

1. **Knižnice.** V priečinku projektu spusti `composer install --no-dev`. Vznikne priečinok `vendor/`.
2. **Súbory.** Nahraj celý projekt aj s `vendor/` na server, napríklad cez FTP. Appka funguje v koreni domény aj v podpriečinku. Do priečinka `uploads/recipes/` musí PHP vedieť zapisovať (práva 755 alebo 775).
3. **Databáza.** V administrácii hostingu vytvor databázu a používateľa s heslom. V phpMyAdmin klikni na **Import** a nahraj `sql/instalacia.sql`.
4. **Sprievodca.** Otvor `https://tvoja-domena/install.php`. Skontroluje server, pripraví obsah súboru `includes/.env` aj s kľúčmi (ulož ho, doplň údaje k databáze a obnov stránku) a vytvorí prvého admina.
5. **Upratanie.** Zmaž `install.php` zo servera a v administrácii hostingu zapni HTTPS (Let's Encrypt býva zadarmo).
6. **Cron (nepovinné).** Pripomienka „dlho ste nevarili" sa pošle pri prvom otvorení appky v daný deň medzi 9:00 a 20:00. Ak má chodiť aj vtedy, keď appku nikto neotvorí, nastav denné volanie `https://tvoja-domena/cron.php?key=HODNOTA_CRON_KEY`.

Verejná registrácia neexistuje, účty vytvára admin v **Správe**. Upozornenia a prihlásenie odtlačkom si každý zapne v appke sám. Na iPhone fungujú upozornenia len v appke pridanej na plochu (iOS 16.4 a novší).

**Kontrola na záver:** adresa `https://tvoja-domena/includes/.env` musí vrátiť chybu 403 alebo 404 a prihlásenie aj pridanie receptu s fotkou musí fungovať.

### Lokálne spustenie

```
composer install --no-dev
mysql -u root -p -e "CREATE DATABASE recepty CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; CREATE USER 'recepty'@'localhost' IDENTIFIED BY 'zvol-si-heslo'; GRANT ALL ON recepty.* TO 'recepty'@'localhost';"
mysql -u recepty -p recepty < sql/instalacia.sql
php -S localhost:8000
```

Potom otvor `http://localhost:8000/install.php` a do `.env` daj `APP_HOST=localhost`. Heslo k databáze nesmie byť prázdne, takže účet `root` bez hesla z XAMPP-u appka neprijme.

- Vstavaný server PHP nepozná `.htaccess` a vydal by aj `includes/.env`. Používaj ho len na `localhost`, nikdy ho nesprístupňuj do siete.
- Na `localhost` funguje všetko okrem biometrie, tá potrebuje HTTPS na `APP_HOST`. Z iného zariadenia v domácej sieti bez HTTPS nefunguje ani plocha, offline režim a upozornenia.

### nginx

Appka chráni `includes/` a `sql/` súbormi `.htaccess`, ktoré pozná len Apache. Na nginx musíš prístup zakázať sám, inak si ktokoľvek stiahne `includes/.env` s heslami:

```nginx
location ^~ /includes/ { deny all; }
location ^~ /sql/      { deny all; }
location ^~ /vendor/   { deny all; }
```

## Nastavenie v `includes/.env`

Vzor je v `includes/.env.example`. Súbor obsahuje heslá, preto nepatrí na GitHub (je v `.gitignore`).

| Premenná | Význam |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Pripojenie k databáze. Heslo nesmie byť prázdne. `DB_CHARSET` je nepovinné (predvolene `utf8mb4`). |
| `APP_HOST` | Doména appky bez `https://` a bez lomky, napr. `recepty.example.com`. Potrebuje ju biometria. |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | Kľúče pre upozornenia, vygeneruje ich `install.php`. Nemeň ich, inak si všetci musia upozornenia povoliť znova. |
| `VAPID_SUBJECT` | Kontakt na správcu v tvare `mailto:meno@domena.sk`. |
| `CRON_KEY` | Tajný kľúč pre `cron.php`, aspoň 16 znakov. Prázdny = cron vypnutý. |
| `APP_DEBUG` | `1` zobrazí presnú chybu na obrazovke. V prevádzke nechaj `0`. |
| `GEMINI_API_KEY`, `ANTHROPIC_API_KEY`, `OPENAI_API_KEY` | Kľúče pre recept z fotky. Stačí jeden, bez kľúča je funkcia vypnutá. |
| `AI_PORADIE` | V akom poradí služby skúšať, predvolene `gemini,claude,openai`. |
| `AI_DENNY_LIMIT` | Koľko fotiek môže jeden používateľ poslať za deň, predvolene `20` (`0` = bez limitu). |
| `AI_MODEL_GEMINI`, `AI_MODEL_CLAUDE`, `AI_MODEL_OPENAI` | Iné modely než predvolené, viac oddelených čiarkou. Väčšinou netreba. |

Premenné `AI_ADRESA_…` slúžia len na testy, v prevádzke ich nevypĺňaj.

## Nová verzia na serveri

### Automaticky cez GitHub

Po vydaní verzie (Release) na GitHube spraví `.github/workflows/nasadenie.yml` toto:

1. spustí testy, a ak niečo zlyhá, nenahrá nič,
2. cez šifrované FTP nahrá len zmenené súbory a zmaže tie, ktoré v novej verzii už nie sú,
3. overí, že appka na serveri beží a že súbory s heslami nie sú dostupné z internetu.

Nikdy nenahrá ani nezmaže `includes/.env`, `vendor/`, `uploads/`, `sql/`, `install.php`, `testy/`, `README.md`, `LICENSE` a `composer.json` / `composer.lock`. Release označený ako pre-release sa nenahráva. Súbor `includes/.ftp-deploy-sync-state.json` na serveri nemaž, podľa neho sa nahrávajú len zmeny.

**Jednorazové nastavenie:** v repozitári otvor **Settings → Secrets and variables → Actions** a pridaj:

| Secret | Hodnota |
|---|---|
| `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` | Prístup na FTP. Najlepšie samostatný FTP účet len na nahrávanie. |
| `FTP_DIR` | Priečinok appky s lomkou na konci, napr. `/recepty/`. Netreba, ak sa FTP účet prihlasuje rovno doň. |
| `APP_URL` | Adresa appky, napr. `https://recepty.example.com`. |

Nastavenie sa dá overiť cez **Actions → Nasadenie na server → Run workflow** so zaškrtnutým **Len skúška**: GitHub vypíše, čo by nahral, ale nič nezmení. Keď nahrávanie zlyhá, hlásenie v záložke **Actions** povie, ktorý Secret chýba alebo čo nesedí na FTP.

### Návrat k staršej verzii

**Actions → Nasadenie na server → Run workflow**, do poľa „verzia" napíš tag verzie, ktorá fungovala (napr. `V2.0.0`), a spusti. Databázu vracať netreba, lebo migrácie len pridávajú a staršia appka nové tabuľky ignoruje.

### Ručne cez FTP

1. Zálohuj databázu a priečinok `uploads/recipes/`.
2. Nahraj nové súbory cez staré. `includes/.env` a `uploads/` nechaj tak.
3. Ak verzia prináša `sql/migracia-X.Y.sql`, naimportuj ju v phpMyAdmin. Pri preskočení viacerých verzií naimportuj všetky chýbajúce od najstaršej.

Kým migrácia nie je naimportovaná, appka beží ďalej, len bez nových funkcií. Migráciu je bezpečné spustiť aj opakovane.

**Keď sa zmení `composer.lock`**, priečinok `vendor/` treba nahrať ručne (spusti `composer install --no-dev` a nahraj ho cez FTP). Automatické nahrávanie ho nerieši.

## Pre vývojára

### Technológie

PHP 8.4 bez frameworku, MariaDB cez PDO, čistý JavaScript bez zostavovania, Bootstrap 5.3 a Bootstrap Icons z CDN. Jediná knižnica cez Composer je `web-auth/webauthn-lib` na biometriu.

Všetky akcie (uloženie receptu, hodnotenie, nákup) idú ako POST na `index.php` s parametrom `action`, vracajú JSON a vyžadujú prihlásenie a CSRF token.

### Súbory

| Súbor alebo priečinok | Na čo slúži |
|---|---|
| `index.php` | Hlavná stránka so všetkými oknami a spracovanie všetkých akcií. |
| `login.php`, `logout.php` | Prihlásenie a odhlásenie. |
| `install.php` | Sprievodca prvou inštaláciou, potom sa maže. |
| `cron.php` | Denné pripomienky, chránené kľúčom `CRON_KEY`. |
| `script.js` | Logika hlavnej stránky v prehliadači, aj rozloženie pre telefón a PC. |
| `suroviny.js` | Výber surovín, kľúč suroviny, návrh pri preklepe a prepočet jednotiek. |
| `theme.js`, `webauthn-client.js`, `style.css` | Motív a prepnutie na HTTPS, biometria v prehliadači, štýly. |
| `sw.js`, `manifest.json`, `offline.html` | PWA: cache, kópia receptov na čítanie bez internetu, príjem upozornení. |
| `admin/` | Správa používateľov a kategórií (`index.php`) a surovín (`suroviny.php`). `prevod-surovin.php` je jednorazový prevod zo starej verzie. |
| `includes/config.php`, `env.php` | Session, zápis chýb, načítanie `.env`, pripojenie k databáze. |
| `includes/auth.php` | Prihlásenie, „Zostať prihlásený", brzda proti hádaniu hesla. |
| `includes/functions.php` | Recepty, suroviny, porcie, nákup, fotky, hodnotenia, upozornenia. |
| `includes/webpush.php` | Odosielanie upozornení (šifrovanie a podpis cez openssl a curl). |
| `includes/ai.php` | Recept z fotky: volanie AI služieb, kontrola odpovede, párovanie surovín, denný limit. |
| `webauthn/` | Serverová časť biometrie. |
| `sql/` | `instalacia.sql` pre novú inštaláciu a `migracia-X.Y.sql` pre aktualizácie. |
| `uploads/recipes/` | Fotky receptov. |
| `testy/`, `.github/workflows/` | Automatické testy a nahrávanie na server. |

### Databáza

Celá štruktúra s komentármi je v `sql/instalacia.sql`.

| Oblasť | Tabuľky |
|---|---|
| Účty | `users`, `login_attempts`, `auth_tokens` (Zostať prihlásený), `webauthn_credentials`, `push_subscriptions` |
| Recepty | `recipes`, `recipe_logs` (história), `recipe_ratings`, `recipe_photos` (galéria), `kategorie_receptov` |
| Suroviny a nákup | `suroviny`, `recept_suroviny`, `recept_suroviny_zaloha`, `nakupny_zoznam` |
| Recept z fotky | `ai_rozpoznania` (denný limit) |

| Migrácia | Čo pridá |
|---|---|
| `migracia-1.1.sql` | Tabuľku `recipe_photos` a prenesie do nej doterajšie fotky. |
| `migracia-1.5.sql` | Tabuľku `kategorie_receptov` s doterajšími siedmimi kategóriami. |
| `migracia-1.8.sql` | Tabuľku `ai_rozpoznania`. |

### Pravidlá pre novú verziu

- Zmeny databázy patria do `sql/migracia-X.Y.sql` a zároveň do `sql/instalacia.sql`. Migrácia musí ísť spustiť opakovane bez zdvojenia dát.
- Appka musí bežať aj bez migrácie, s kontrolou existencie tabuľky (napr. `recipes_have_photos()`).
- Migrácie len pridávajú. Tabuľku ani stĺpec nemaž ani nepremenúvaj v tej istej verzii, inak by návrat k staršej verzii nestačil.
- Po zmene CSS, JS alebo `offline.html` zvýš `CACHE_NAME` v `sw.js` a číslo za `?v=` v `index.php` a `login.php`.
- Knižnice z CDN v `index.php` musia byť aj v `CDN_ASSETS` v `sw.js`, pri ikonách aj písmo `bootstrap-icons.woff2`.
- Kľúč suroviny (malé písmená, bez diakritiky) sa počíta na troch miestach a musí vyjsť rovnako: `surovina_key()` vo `functions.php`, `normKey()` v `suroviny.js` a stĺpec `kluc` v `sql/instalacia.sql`.
- Každá verzia má riadok v [Histórii verzií](#história-verzií) v tvare `**X.Y**`.

Okrem pravidla o migráciách, ktoré len pridávajú, všetko ostatné skontrolujú testy.

### Testy

`testy/spusti-testy.sh` beží na GitHube pri každom nahratí do `main` a pred nahratím na server. Overí syntax PHP, pravidlá vyššie, novú inštaláciu, beh novej appky nad databázou predchádzajúcej verzie, migrácie a celý priechod appkou (`testy/smoke.php` sa prihlási, pridá a upraví recept, použije nákup, správu a ďalšie). Offline režim v prehliadači treba vyskúšať ručne.

Na vlastnom počítači (PHP 8.4.1+, bežiaca MariaDB, stiahnutý `vendor/`):

```
TEST_DB_ROOT_USER=root TEST_DB_ROOT_PASS=heslo bash testy/spusti-testy.sh
```

Test si založí vlastné databázy `recepty_test_…` a na konci ich zmaže. Tvoj `.env` ani fotky nemení. Na Windows sa dá spustiť cez Git Bash alebo WSL.

## Riešenie problémov

Chyby sa zapisujú do `includes/debug-log.txt`. S `APP_DEBUG=1` v `.env` sa presná chyba ukáže priamo na obrazovke.

| Príznak | Čo s tým |
|---|---|
| „Chýba povinná konfiguračná premenná…" | Doplň uvedený riadok do `includes/.env`. |
| „Aplikácia sa nevie pripojiť k databáze" | Skontroluj `DB_HOST`, `DB_NAME`, `DB_USER` a `DB_PASS`. |
| Biela stránka | Chýba `vendor/` alebo je na serveri PHP staršie než 8.4.1. |
| Fotka sa nenahrá | Do `uploads/recipes/` sa nedá zapisovať alebo chýba rozšírenie `gd`. |
| Upozornenia nechodia | Používateľ ich nepovolil, appka nebeží na HTTPS, alebo je v logu `PUSH ZLYHAL`. Na iPhone len v appke z plochy. |
| Biometria hlási chybu | `APP_HOST` nesedí s doménou alebo appka nebeží na HTTPS. |
| Po nahratí novej verzie starý vzhľad | V `sw.js` nebol zvýšený `CACHE_NAME`. |
| Bez internetu sa ukáže „Si offline" | Appka sa v zariadení ešte neotvorila s internetom po prihlásení. |
| V logu `FOTKY`, `KATEGÓRIE`, `SUROVINY`… | V databáze chýba tabuľka, naimportuj migráciu. |

## Zálohovanie

Zálohovať treba databázu (export v phpMyAdmin), priečinok `uploads/recipes/` a súbor `includes/.env`. Všetko ostatné je na GitHube.

Pri prenose na iný server stačí tieto tri veci preniesť a v `.env` upraviť údaje k databáze a `APP_HOST`. Pri zmene domény si každý musí znova zapnúť prihlásenie odtlačkom.

## História verzií

- **2.0.2** Rýchle dvojité ťuknutie (napr. pri pridávaní receptu) už na telefóne nepriblíži stránku. Priblíženie dvoma prstami ostáva.
- **2.0.1** Obsah na PC sa roztiahne na celú šírku obrazovky, na veľkom monitore je viac stĺpcov kariet.
- **2.0** Nové rozloženie na PC: bočná ponuka, veľké hľadanie, kategórie ako tlačidlá, karty s tlačidlami Uvarené a Do nákupu, náhľad receptu s fotkami vľavo a receptom vpravo.
- **1.9** Formulár receptu na telefóne v troch krokoch (Základ, Suroviny, Postup a fotka). Recept z fotky ide rovno do vyplneného formulára.
- **1.8.2** Keď je Gemini preťažený, appka skúsi záložný ľahší model. V `AI_MODEL_…` môže byť viac modelov.
- **1.8.1** Oprava: rozpoznanie cez Gemini nestihlo odpovedať, AI teraz premýšľa krátko.
- **1.8** Recept z fotky cez AI (Gemini, Claude, OpenAI) s denným limitom. Migrácia `migracia-1.8.sql`.
- **1.7** Nové rozloženie pre telefón: spodná lišta Recepty, Pridať, Nákup, Viac a okná na celú obrazovku s veľkými tlačidlami.
- **1.6** Správa surovín (kategória, premenovanie, zlúčenie, zmazanie) a návrh „Nemysleli ste …?" pri preklepe.
- **1.5** Prepočet porcií prevádza jednotky (2000 g → 2 kg). Kategórie receptov spravuje admin. Migrácia `migracia-1.5.sql`.
- **1.4** Nákupný zoznam sa sám obnovuje každých 5 sekúnd.
- **1.3** Recepty sa dajú pozerať aj bez internetu.
- **1.2** Automatické testy a automatické nahrávanie na server po vydaní verzie, návrat k staršej verzii jedným klikom.
- **1.1** Galéria fotiek receptu a okno Uvarené s dátumom. Migrácia `migracia-1.1.sql`.
- **1.0** Prvá zverejnená verzia.
