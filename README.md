# Evidencia receptov

Rodinná webová appka na recepty. Beží v prehliadači, dá sa pridať na plochu telefónu ako bežná aplikácia (PWA) a je určená pre malú skupinu ľudí, ktorí si recepty spravujú spoločne.

Tento dokument má dve časti:

- **[Časť A: Inštalácia](#časť-a-inštalácia)** je návod pre toho, kto chce appku rozbehnúť na vlastnom hostingu alebo na lokálnom serveri.
- **[Časť B: Ako appka funguje](#časť-b-ako-appka-funguje)** je dokumentácia: čo appka vie, čo je v ktorom súbore, aké má tabuľky a ako fungujú jednotlivé časti.

## Čo appka vie

- **Recepty** s názvom, kategóriou, časom prípravy, počtom porcií, odkazom na pôvodný web, fotkou a poznámkami.
- **Suroviny z číselníka.** Vyberajú sa vo vyhľadávacom poli, ktoré ignoruje veľké písmená a diakritiku, takže „Múka hladká" a „muka hladka" je vždy tá istá surovina. V základe je 418 surovín v 14 kategóriách a ďalšie sa pridávajú priamo pri písaní receptu.
- **Prepočet porcií.** V náhľade receptu sa tlačidlami − a + mení počet porcií a množstvá sa prepočítajú.
- **Nákupný zoznam** spoločný pre všetkých. Suroviny z viacerých receptov sa zlúčia a sčítajú, zoznam sa dá odškrtávať a odoslať do Google Keep alebo inej appky.
- **Prehľad, čo sa kedy varilo.** Pri recepte je vidno, pred koľkými dňami sa naposledy varil, a po 90 dňoch appka pripomenie, že by sa hodilo ho zopakovať.
- **Hodnotenie hviezdičkami** a **história** každého receptu (kto ho pridal, upravil, uvaril, ohodnotil).
- **Upozornenia do telefónu** na nový recept, na hodnotenie tvojho receptu a pripomienky dlho nevarených receptov.
- **Prihlásenie** heslom alebo odtlačkom prsta či Face ID, s možnosťou zostať prihlásený 90 dní.
- **Svetlý a tmavý vzhľad**, veľké ovládacie prvky vhodné aj pre starších používateľov.

---

# Časť A: Inštalácia

## A1. Čo budeš potrebovať

| Požiadavka | Podrobnosti |
|---|---|
| Webový server | Apache s povoleným `.htaccess`. Pri nginx treba pravidlá doplniť ručne, pozri [A6](#a6-nginx-a-iné-servery-bez-htaccess). |
| PHP | **8.4.1 alebo novšie.** Vyžaduje to knižnica na biometriu v zamknutých verziách (`composer.lock`). |
| Rozšírenia PHP | `pdo_mysql`, `openssl`, `curl`, `mbstring`, `gd`, `json` (ďalej `ctype`, `iconv`, `filter`, ktoré bývajú zapnuté vždy). |
| Databáza | MariaDB 10.5 alebo novšia. Appka bola testovaná na MariaDB 10.5 a 10.11. S MySQL 8 by mala fungovať, ale odskúšaná nie je. |
| Composer | Na stiahnutie knižníc do priečinka `vendor/`. Stačí ho mať na vlastnom počítači. |
| HTTPS | Potrebné pre inštaláciu na plochu, upozornenia a biometriu. Výnimkou je adresa `localhost`, kde prvé dve fungujú aj bez neho. |

Appka nepotrebuje Node.js ani žiadne zostavovanie. Súbory sa nahrávajú tak, ako sú.

## A2. Inštalácia na webhosting

### Krok 1: Priprav súbory

Stiahni projekt a v jeho priečinku spusti:

```
composer install --no-dev
```

Vznikne priečinok `vendor/` s knižnicami. Ak na hostingu nemáš prístup k príkazovému riadku, sprav to na svojom počítači a `vendor/` nahraj spolu s ostatnými súbormi.

### Krok 2: Nahraj súbory na server

Nahraj celý obsah projektu (vrátane `vendor/`) do priečinka domény alebo subdomény, napríklad cez FTP. Appka je odskúšaná v koreni domény alebo subdomény (`https://recepty.example.com/`) aj v podpriečinku (`https://example.com/recepty/`).

Skontroluj, že existuje priečinok `uploads/recipes/` a že doň PHP môže zapisovať (práva 755 alebo 775). Ukladajú sa tam fotky receptov.

### Krok 3: Vytvor databázu

1. V administrácii hostingu vytvor novú databázu a používateľa s heslom.
2. Otvor phpMyAdmin, vyber databázu, klikni na **Import** a nahraj súbor `sql/instalacia.sql`.

Súbor vytvorí všetky tabuľky a naplní zoznam surovín. Dá sa spustiť aj opakovane, nič nepokazí.

### Krok 4: Otvor sprievodcu inštaláciou

V prehliadači otvor `https://tvoja-domena/install.php`. Sprievodca má tri kroky:

1. **Kontrola servera.** Ukáže, či sedí verzia PHP, rozšírenia, knižnice a práva na zápis. Čo je červené, treba opraviť.
2. **Súbor `includes/.env`.** Sprievodca pripraví jeho obsah vrátane čerstvo vygenerovaných kľúčov. Skopíruj ho do súboru `includes/.env`, doplň údaje k databáze a svoj e-mail a nahraj ho na server. Potom stránku obnov.
3. **Prvý administrátor.** Vyplň meno a heslo (aspoň 8 znakov). Sprievodca vytvorí účet s rolou `admin`.

Význam všetkých riadkov v `.env` je v časti [A5](#a5-nastavenie-v-súbore-env).

### Krok 5: Zmaž `install.php`

Sprievodca po vytvorení prvého účtu už nič nerobí, aj tak ho zo servera zmaž. Potom sa prihlás na `https://tvoja-domena.example/`.

### Krok 6: Zapni HTTPS

V administrácii hostingu zapni certifikát (väčšina hostingov ponúka Let's Encrypt zadarmo) a vynútenie HTTPS, ak tú možnosť má. Appka sama prepne návštevníka z `http://` na `https://` v prehliadači, ale presmerovanie na strane servera je spoľahlivejšie.

### Krok 7: Pripomienky cez cron (nepovinné)

Pripomienka „dlho ste nevarili" sa pošle pri prvom otvorení appky v daný deň medzi 9:00 a 20:00. Ak má prísť aj vtedy, keď appku nikto neotvoril, nastav v administrácii hostingu úlohu (cron), ktorá raz denne zavolá:

```
https://tvoja-domena/cron.php?key=HODNOTA_CRON_KEY
```

`HODNOTA_CRON_KEY` je riadok `CRON_KEY` z tvojho `.env`. Bez neho je `cron.php` vypnutý.

### Krok 8: Pridaj ďalších používateľov

Verejná registrácia neexistuje. Účty vytvára administrátor: tlačidlo **Používatelia** v hornej lište. Každý používateľ si potom v appke môže:

- pridať appku na plochu (v prehliadači telefónu „Pridať na plochu" alebo „Inštalovať"),
- povoliť upozornenia tlačidlom so zvončekom,
- zapnúť biometriu tlačidlom s odtlačkom.

Na iPhone fungujú upozornenia len v appke pridanej na plochu (iOS 16.4 a novší).

## A3. Inštalácia na lokálny server

Postup je rovnaký ako pri hostingu, líši sa len tým, kde appka beží.

### Možnosť 1: Vstavaný server PHP (najrýchlejšie na vyskúšanie)

Potrebuješ nainštalované PHP 8.4.1+ a MariaDB.

1. V priečinku projektu spusti `composer install --no-dev`.
2. Vytvor databázu a používateľa s heslom a naimportuj schému:
   ```
   mysql -u root -p -e "CREATE DATABASE recepty CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; CREATE USER 'recepty'@'localhost' IDENTIFIED BY 'zvol-si-heslo'; GRANT ALL ON recepty.* TO 'recepty'@'localhost';"
   mysql -u recepty -p recepty < sql/instalacia.sql
   ```
3. Spusti server:
   ```
   php -S localhost:8000
   ```
4. Otvor `http://localhost:8000/install.php` a pokračuj sprievodcom. Do `.env` daj `APP_HOST=localhost`.

> **Pozor:** vstavaný server PHP nepozná `.htaccess`, takže by komukoľvek vydal aj súbor `includes/.env` s heslami. Používaj ho len na adrese `localhost` na vlastnom počítači, nikdy ho nesprístupňuj do siete ani do internetu.

### Možnosť 2: Balík s Apache (XAMPP, Laragon, MAMP a podobné)

1. Over, že balík obsahuje PHP 8.4.1 alebo novšie. Staršie verzie balíkov majú staršie PHP a appka by na nich nebežala.
2. Projekt skopíruj do priečinka webu. Najjednoduchšie je nechať ho v podpriečinku a otvárať cez `http://localhost/recepty/`. Vlastný virtuálny host (napr. `recepty.test`) funguje tiež, ale bez HTTPS na ňom nepôjde pridanie na plochu ani upozornenia.
3. Databázu vytvor cez phpMyAdmin a naimportuj `sql/instalacia.sql`.
4. Otvor `install.php` a pokračuj sprievodcom.

Heslo k databáze nesmie byť prázdne. Predvolený účet `root` bez hesla, aký tieto balíky často majú, appka neprijme. Vytvor si vlastného používateľa databázy s heslom.

### Čo na lokálnom serveri funguje a čo nie

| Funkcia | `http://localhost` | Domáca sieť bez HTTPS (napr. `http://192.168.1.20`) | Vlastná doména s HTTPS |
|---|---|---|---|
| Recepty, suroviny, porcie, nákupný zoznam | áno | áno | áno |
| Pridanie na plochu (PWA) | áno | nie | áno |
| Upozornenia | áno | nie | áno |
| Biometria | nie | nie | áno |
| Prístup z telefónu | nie | áno | áno |

Dôvodom je, že prehliadače povoľujú service worker, upozornenia a biometriu len na zabezpečenej adrese. Biometria navyše v appke počíta výhradne s adresou `https://APP_HOST`.

Ak chceš appku používať v domácej sieti naplno, potrebuje vlastnú (aj internú) doménu s platným certifikátom.

## A4. Prenos existujúcej inštalácie na iný server

1. Skopíruj všetky súbory vrátane `uploads/recipes/` (fotky) a `includes/.env`.
2. Exportuj celú databázu (štruktúru aj dáta) a naimportuj ju na novom serveri.
3. V `.env` uprav údaje k databáze a `APP_HOST`.

Kľúče VAPID nechaj pôvodné, inak si všetci musia upozornenia povoliť znova. Pri zmene domény prestanú platiť uložené biometrické kľúče (sú viazané na doménu) a každý si biometriu zapne nanovo.

`install.php` pri prenose netreba, účty už v databáze sú.

## A5. Nastavenie v súbore `.env`

Súbor `includes/.env` obsahuje heslá a kľúče. Nepatrí na GitHub (je v `.gitignore`) a z webu je chránený súborom `includes/.htaccess`. Vzor je v `includes/.env.example`.

| Premenná | Povinná | Význam |
|---|---|---|
| `DB_HOST` | áno | Adresa databázového servera, často `localhost`. |
| `DB_NAME` | áno | Názov databázy. |
| `DB_USER` | áno | Používateľ databázy. |
| `DB_PASS` | áno | Heslo k databáze. Nesmie byť prázdne. |
| `DB_CHARSET` | nie | Znaková sada spojenia, predvolene `utf8mb4`. |
| `APP_HOST` | áno | Doména appky bez `https://` a bez lomky, napr. `recepty.example.com`. Používa ju biometria. |
| `VAPID_PUBLIC_KEY` | áno | Verejný kľúč pre upozornenia. Vygeneruje ho `install.php`. |
| `VAPID_PRIVATE_KEY` | áno | Súkromný kľúč pre upozornenia. Drž ho v tajnosti. |
| `VAPID_SUBJECT` | áno | Kontakt na správcu v tvare `mailto:meno@domena.sk`. Vyžadujú ho služby, ktoré upozornenia doručujú. |
| `CRON_KEY` | nie | Tajný kľúč pre `cron.php`, aspoň 16 znakov. Prázdny = cron vypnutý. |
| `APP_DEBUG` | nie | `1` zobrazí presnú chybu na obrazovke. V prevádzke nechaj `0`. |

## A6. nginx a iné servery bez `.htaccess`

Appka chráni priečinky `includes/` a `sql/` súbormi `.htaccess`, ktoré pozná len Apache. Na inom serveri **musíš prístup zakázať sám**, inak si ktokoľvek stiahne `includes/.env` s heslom k databáze. Pre nginx:

```nginx
location ^~ /includes/ { deny all; }
location ^~ /sql/      { deny all; }
location ^~ /vendor/   { deny all; }
```

Po nastavení si over, že adresa `https://tvoja-domena/includes/.env` vracia chybu 403 alebo 404.

## A7. Kontrola po inštalácii

- [ ] `https://tvoja-domena/includes/.env` sa nedá otvoriť (403 alebo 404).
- [ ] `install.php` je zo servera zmazaný.
- [ ] Prihlásenie funguje a dá sa pridať recept s fotkou.
- [ ] Tlačidlo so zvončekom povolí upozornenia a po pridaní receptu iným používateľom upozornenie príde.
- [ ] `http://tvoja-domena/` prepne na `https://`.

## A8. Riešenie problémov

Chyby appka zapisuje do `includes/debug-log.txt`. Pri hľadaní problému nastav v `.env` dočasne `APP_DEBUG=1`, presná chyba sa potom zobrazí na obrazovke.

| Príznak | Príčina a riešenie |
|---|---|
| „Chýba povinná konfiguračná premenná…" | V `includes/.env` chýba uvedený riadok alebo je prázdny. |
| „Aplikácia sa nevie pripojiť k databáze" | Nesprávne `DB_HOST`, `DB_NAME`, `DB_USER` alebo `DB_PASS`. |
| Biela stránka alebo chyba o verzii PHP z priečinka `vendor/` | Server má PHP staršie než 8.4.1, alebo chýba `vendor/` (spusti `composer install`). |
| Fotka sa nenahrá | Priečinok `uploads/recipes/` neexistuje alebo doň PHP nemôže zapisovať, prípadne chýba rozšírenie `gd`. |
| Upozornenia nechodia | Používateľ si ich nepovolil zvončekom, appka nebeží na HTTPS, alebo je v logu riadok „PUSH ZLYHAL" s dôvodom. Na iPhone musí byť appka pridaná na plochu. |
| Biometria hlási chybu | `APP_HOST` nesedí s doménou, na ktorej appka beží, alebo appka nebeží na HTTPS. |
| Po nahratí novej verzie vidno starý vzhľad | V `sw.js` nebola zvýšená verzia cache, pozri [Úprava a nasadenie novej verzie](#úprava-a-nasadenie-novej-verzie). |
| Stále sa treba prihlasovať | Pri prihlásení nebolo zaškrtnuté „Zostať prihlásený", alebo prehliadač maže cookies. |

---

# Časť B: Ako appka funguje

## B1. Technológie

- **Server:** čisté PHP bez frameworku, databáza MariaDB cez PDO.
- **Prehliadač:** obyčajný JavaScript bez knižníc a bez zostavovania, vzhľad Bootstrap 5.3 a ikony Bootstrap Icons načítané z CDN.
- **Knižnice cez Composer:** jediná priama závislosť je `web-auth/webauthn-lib` na biometriu (spolu s tým, čo sama potrebuje, je to 30 balíkov). Upozornenia odosiela vlastný kód appky, žiadnu knižnicu na to netreba.

Všetky akcie appky (uloženie receptu, hodnotenie, nákupný zoznam) idú ako požiadavky POST na `index.php` s parametrom `action` a vracajú JSON. Každá vyžaduje prihlásenie a CSRF token.

## B2. Štruktúra súborov

| Súbor alebo priečinok | Na čo slúži |
|---|---|
| `index.php` | Hlavná stránka so zoznamom receptov a všetkými oknami. Zároveň spracúva všetky akcie z prehliadača. |
| `login.php`, `logout.php` | Prihlásenie a odhlásenie. |
| `install.php` | Sprievodca prvou inštaláciou. Po inštalácii sa maže. |
| `cron.php` | Denná úloha pre pripomienky, chránená kľúčom `CRON_KEY`. |
| `script.js` | Celá logika hlavnej stránky v prehliadači. |
| `suroviny.js` | Vyhľadávací výber surovín a normalizácia názvov. Používa ho hlavná stránka aj prevodná stránka. |
| `webauthn-client.js` | Biometria v prehliadači. |
| `theme.js` | Svetlý a tmavý vzhľad, prepnutie na HTTPS. Načítava sa ako prvý na každej stránke. |
| `style.css` | Vlastné štýly nad Bootstrapom. |
| `sw.js` | Service worker: cache statických súborov, offline stránka, príjem upozornení. |
| `manifest.json`, `app-icons/` | Údaje a ikony pre inštaláciu na plochu. |
| `offline.html` | Stránka zobrazená bez pripojenia. |
| `admin/index.php` | Správa používateľov (len admin). |
| `admin/prevod-surovin.php` | Jednorazový prevod receptov zo starej verzie, kde boli suroviny voľným textom. Pri novej inštalácii nie je potrebný. |
| `includes/config.php` | Nastavenie session, zápis chýb, načítanie `.env`, pripojenie k databáze. |
| `includes/env.php` | Načítanie súboru `.env`. |
| `includes/auth.php` | Prihlásenie, trvalé prihlásenie, brzda proti hádaniu hesla. |
| `includes/functions.php` | Recepty, suroviny, porcie, nákupný zoznam, fotky, hodnotenia, upozornenia, pripomienky. |
| `includes/webpush.php` | Odosielanie upozornení (šifrovanie a podpis). |
| `includes/.env` | Heslá a kľúče. Nie je súčasťou projektu, vytvára sa pri inštalácii. |
| `includes/debug-log.txt` | Zápis chýb. Vzniká sám. |
| `webauthn/` | Serverová časť biometrie: príprava a overenie registrácie a prihlásenia. |
| `sql/instalacia.sql` | Kompletná databáza pre novú inštaláciu. |
| `uploads/recipes/` | Fotky receptov. |
| `vendor/` | Knižnice stiahnuté Composerom. Nie je súčasťou projektu na GitHube. |

## B3. Databáza

Štruktúra je v `sql/instalacia.sql`, kde má každá tabuľka aj komentár.

| Tabuľka | Obsah |
|---|---|
| `users` | Účty: meno, odtlačok hesla, rola (`admin` alebo `user`), celé meno. |
| `login_attempts` | Neúspešné pokusy o prihlásenie. |
| `auth_tokens` | Zapamätané zariadenia („Zostať prihlásený"). |
| `webauthn_credentials` | Biometrické kľúče zariadení. |
| `push_subscriptions` | Zariadenia, ktoré si povolili upozornenia. |
| `recipes` | Recepty. |
| `recipe_logs` | História receptov. |
| `recipe_ratings` | Hodnotenia, jeden hlas používateľa na recept. |
| `suroviny` | Číselník surovín. |
| `recept_suroviny` | Suroviny priradené k receptom, s množstvom, jednotkou a poznámkou. |
| `recept_suroviny_zaloha` | Pôvodný text surovín z prevodu starých receptov. |
| `nakupny_zoznam` | Spoločný nákupný zoznam. |

Dôležité väzby:

- Zmazaním receptu sa zmažú aj jeho suroviny (`recept_suroviny`) a hodnotenia. História v `recipe_logs` ostáva.
- Surovinu, ktorú používa nejaký recept, databáza zmazať nedovolí.
- Zmazaním používateľa sa zmažú jeho hodnotenia a odbery upozornení. Jeho recepty ostávajú.

Názvy tabuliek a stĺpcov sú čiastočne po anglicky (staršia časť) a čiastočne po slovensky (suroviny a nákup). Je to historické a na fungovanie to nemá vplyv.

## B4. Recepty

**Zoznam.** Recepty sú zoradené od naposledy vareného. Tie, ktoré sa ešte nevarili, sú na konci. Zoradenie sa dá zmeniť (najstaršie varené, najnovšie pridané, čas prípravy) a zoznam sa dá filtrovať podľa kategórie a prehľadávať podľa názvu, surovín a poznámok. Hľadanie ignoruje diakritiku.

**Odznak pri recepte** ukazuje, kedy sa naposledy varil:

| Stav | Odznak |
|---|---|
| ešte nikdy | zelený „Ešte nikdy — skús to!" |
| do 10 dní | sivý „Pred X dňami" |
| 11 až 90 dní | žltý „Pred X dňami" |
| viac než 90 dní | červený „čas na opakovanie!" |

**Kategórie** sú pevne dané v `index.php` (Polievky, Hlavné jedlá, Bezmäsité, Fit, Dezerty, Rýchlovky, Raňajky). Zoznam je tam dvakrát, vo filtri a vo formulári, a pri zmene treba upraviť oba.

**Fotky.** Prehliadač fotku pred odoslaním zmenší, server ju potom prevedie na JPEG s rozmerom najviac 1200 × 800 bodov a uloží do `uploads/recipes/` pod náhodným menom. Prijíma JPG, PNG a WEBP do 8 MB. Pri výmene alebo zmazaní receptu sa stará fotka z disku odstráni.

**Práva.** Pridávať a upravovať recepty môže každý prihlásený. Mazať recepty a spravovať používateľov môže len admin.

**Tlačidlá na karte receptu:** uvarené dnes, do nákupu, história, upraviť, zmazať (len admin). Kliknutím kamkoľvek inam na kartu sa otvorí náhľad.

## B5. Suroviny

Každá surovina má v tabuľke `suroviny` názov a **kľúč**: názov malými písmenami, bez diakritiky a s jednou medzerou medzi slovami. Podľa kľúča sa suroviny porovnávajú, takže nevznikajú duplicity z preklepov vo veľkosti písmen či dĺžňoch.

Kľúč sa počíta na troch miestach a všade musí vyjsť rovnako:

- `surovina_key()` vo `functions.php`,
- `normKey()` v `suroviny.js`,
- stĺpec `kluc` v `sql/instalacia.sql`.

**Vo formulári receptu** sa surovina vyhľadá a pridá ako riadok s nepovinným množstvom, jednotkou a poznámkou. Ak surovina v číselníku nie je, ponúkne sa jej pridanie. Nová surovina vznikne až pri uložení receptu a nemá kategóriu.

**Jednotky** sú dané zoznamom `SUROVINA_JEDNOTKY` vo `functions.php`: g, kg, ml, dl, l, ks, PL, ČL, šálka, štipka, balenie, plátok, strúčik.

Pole „Poznámky / postup" je voľný text pre všetko ostatné (postup, pomôcky, kde čo kúpiť).

## B6. Prepočet porcií

Recept má nepovinný počet porcií. Ak je vyplnený a aspoň jedna surovina má množstvo, v náhľade sa zobrazia tlačidlá − a +.

- V databáze ostávajú množstvá vždy pre pôvodný počet porcií, prepočet je len zobrazenie.
- Zaokrúhľuje sa podľa veľkosti: od 100 na celé čísla, od 10 na jedno desatinné miesto, menšie hodnoty na dve.
- Jednotky sa neprevádzajú a kusové suroviny sa nezaokrúhľujú na celé (môže vyjsť 0,5 vajca).

## B7. Nákupný zoznam

Zoznam je jeden pre všetkých používateľov. Otvára sa košíkom v hornej lište alebo plávajúcim tlačidlom vpravo dole, ktoré je vidno, kým je čo kúpiť.

**Pridanie receptu.** Tlačidlo s košíkom na karte pridá suroviny pre pôvodný počet porcií, tlačidlo „Do nákupu" v náhľade pre práve nastavený počet.

**Zlučovanie:**

- Rovnaká surovina s rovnakou jednotkou sa sčíta.
- Pred sčítaním sa hmotnosť prevedie na gramy a objem na mililitre, takže 250 g + 1 kg = 1,5 kg. Zobrazuje sa v kg a l od hodnoty 1000.
- Položka bez množstva sa pripojí k existujúcej položke tej istej suroviny.
- Rôzne druhy jednotiek (napr. gramy a lyžice) ostávajú ako dva riadky.
- Pri položke je vidno, z ktorých receptov pochádza.

**Vlastné položky** (napr. toaletný papier) sa do zoznamu píšu priamo a do číselníka surovín sa nepridávajú.

**Odoslanie do inej appky.** Tlačidlo „Odoslať" otvorí systémové zdieľanie s nekúpenými položkami, každá na vlastnom riadku. Názov poznámky tvoria názvy receptov. Google Keep nemá pre bežné účty rozhranie, cez ktoré by appka mohla zoznam zapísať priamo, preto to ide cez zdieľanie. Zoznam v Keepe a v appke sa potom už nesynchronizujú.

Zmeny od ostatných používateľov sa načítajú pri otvorení zoznamu, nie naživo. Zoznam vyžaduje pripojenie na internet.

## B8. Upozornenia

Appka používa štandard Web Push. Odosielanie je napísané priamo v `includes/webpush.php` pomocou rozšírení `openssl` a `curl`: správa sa šifruje podľa RFC 8291 a odosielateľ sa preukazuje VAPID tokenom podľa RFC 8292.

| Upozornenie | Kto ho dostane | Kedy |
|---|---|---|
| Nový recept | všetci okrem autora | po pridaní receptu |
| Nové hodnotenie | autor receptu | keď jeho recept ohodnotí niekto iný |
| Dlho ste nevarili | všetci | 90 dní od posledného varenia |

Kliknutie na upozornenie otvorí konkrétny recept (adresa `/?recept=ČÍSLO`). Ak používateľ nie je prihlásený, číslo receptu sa prenesie cez prihlásenie.

**Pripomienka „dlho ste nevarili"** sa posiela najviac jedna denne, od najdlhšie nevareného receptu, a pri každom recepte len raz, kým sa znova neuvarí. Recepty, ktoré sa nevarili nikdy, sa nepripomínajú. Spúšťa ju `cron.php`, alebo prvé otvorenie appky v daný deň medzi 9:00 a 20:00.

Zariadenie, ktoré odber zrušilo, appka z databázy sama vymaže pri najbližšom pokuse o odoslanie.

## B9. Prihlásenie a bezpečnosť

**Heslo.** Ukladá sa len jeho odtlačok. Po 5 neúspešných pokusoch za 15 minút sa ďalšie pokusy pre dané meno a adresu dočasne odmietajú.

**Zostať prihlásený.** Pri prihlásení so zaškrtnutým poľom dostane zariadenie cookie platnú 90 dní, ktorá sa každým použitím predlžuje. V databáze je uložený len jej odtlačok. Bez zaškrtnutia sa používateľ odhlási po 30 minútach nečinnosti. Tlačidlo Odhlásiť zruší zapamätanie na danom zariadení, nové heslo od admina na všetkých zariadeniach používateľa.

**Biometria.** Používa štandard WebAuthn (prihlasovacie kľúče). Kľúč sa ukladá do zariadenia a odomyká odtlačkom alebo Face ID. Zariadenie, ktoré už biometriu použilo, vyvolá výzvu na prihlasovacej stránke samo. Okno s výzvou zobrazuje telefón, appka jeho podobu neovplyvní.

**Ochrana formulárov.** Každá akcia vyžaduje CSRF token.

**Citlivé súbory.** `includes/` a `sql/` sú z webu neprístupné vďaka `.htaccess`. Platí to len na Apache, pozri [A6](#a6-nginx-a-iné-servery-bez-htaccess).

**HTTPS.** Cookies sa pri šifrovanom spojení posielajú len šifrovane a appka posiela hlavičku HSTS. Návštevníka z `http://` prepne na `https://` skript v `theme.js`, okrem adries `localhost` a domácej siete.

**Na čo myslieť:**

- Kto má v ruke odomknutý telefón so zapamätaným prihlásením, dostane sa do appky bez hesla.
- Appka je stavaná pre malú skupinu ľudí, ktorí si dôverujú. Každý prihlásený môže upraviť ktorýkoľvek recept.
- Stĺpec `users.active` appka nepoužíva. Účet sa zneprístupní zmazaním alebo zmenou hesla.

## B10. Aplikácia na ploche a service worker

`manifest.json` umožňuje pridať appku na plochu. `sw.js` rieši tri veci:

- **Cache statických súborov** (štýly, skripty, ikony), aby sa appka načítala rýchlo.
- **Stránky PHP nikdy z cache.** Vždy sa načítajú zo servera, pri výpadku pripojenia sa ukáže `offline.html`.
- **Príjem upozornení** a otvorenie správneho receptu po kliknutí.

Appka offline nefunguje, bez pripojenia ukáže len informačnú stránku.

### Úprava a nasadenie novej verzie

Statické súbory si prehliadače držia v cache. Po každej zmene v `style.css`, `script.js`, `suroviny.js`, `theme.js` alebo `webauthn-client.js` preto treba:

1. v `sw.js` zvýšiť číslo v `CACHE_NAME` (napr. `recepty-static-v21` na `v22`),
2. v `index.php` a `login.php` zvýšiť číslo za `?v=` pri odkazoch na zmenené súbory.

Bez toho uvidia používatelia starú verziu, kým sa cache neobnoví sama.

## B11. Zápis chýb

Chyby a varovania PHP sa zapisujú do `includes/debug-log.txt`. Ak tam PHP nemôže zapisovať, idú do štandardného logu hostingu. Návštevníkom sa podrobnosti nezobrazujú, pokiaľ nie je v `.env` nastavené `APP_DEBUG=1`.

Časté značky v logu:

| Značka | Význam |
|---|---|
| `PUSH ZLYHAL` | Upozornenie sa nepodarilo odoslať, nasleduje dôvod. |
| `PRIPOMIENKA zlyhala` | Chyba pri posielaní pripomienky. |
| `ZOSTAT PRIHLASENY` | Problém s trvalým prihlásením, zvyčajne chýbajúca tabuľka `auth_tokens`. |
| `WEBAUTHN … ERROR` | Chyba biometrie. |
| `SUROVINY`, `NAKUP`, `PORCIE` | V databáze chýba tabuľka alebo stĺpec. Appka vtedy beží ďalej, len bez danej funkcie. |

## B12. Zálohovanie

Zálohovať treba tri veci:

- databázu (export cez phpMyAdmin),
- priečinok `uploads/recipes/` s fotkami,
- súbor `includes/.env`.

Všetko ostatné sa dá znova stiahnuť z projektu.

## B13. Známe obmedzenia

- Bez pripojenia na internet nefunguje.
- Nákupný zoznam sa medzi zariadeniami neobnovuje naživo.
- Jednotky sa pri prepočte porcií neprevádzajú.
- Kategórie receptov sa nedajú meniť v appke, len v kóde.
- Suroviny sa v appke nedajú premenovať, zlúčiť ani im doplniť kategória. Dá sa to len priamo v databáze.
- Rozhranie je len po slovensky.
