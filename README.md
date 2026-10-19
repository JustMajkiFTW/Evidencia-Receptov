# Evidencia receptov

Rodinná webová appka na recepty. Beží v prehliadači, dá sa pridať na plochu telefónu ako bežná aplikácia (PWA) a je určená pre malú skupinu ľudí, ktorí si recepty spravujú spoločne.

Tento dokument má dve časti:

- **[Časť A: Inštalácia](#časť-a-inštalácia)** je návod pre toho, kto chce appku rozbehnúť na vlastnom hostingu alebo na lokálnom serveri.
- **[Časť B: Ako appka funguje](#časť-b-ako-appka-funguje)** je dokumentácia: čo appka vie, čo je v ktorom súbore, aké má tabuľky a ako fungujú jednotlivé časti.

## Čo appka vie

- **Recepty** s názvom, kategóriou, časom prípravy, počtom porcií, odkazom na pôvodný web a poznámkami.
- **Galéria fotiek.** Ku každému vareniu sa dá pridať nová fotka a staršie ostávajú, každá so svojím dátumom. V náhľade receptu sa medzi nimi listuje.
- **Suroviny z číselníka.** Vyberajú sa vo vyhľadávacom poli, ktoré ignoruje veľké písmená a diakritiku, takže „Múka hladká" a „muka hladka" je vždy tá istá surovina. V základe je 418 surovín v 14 kategóriách a ďalšie sa pridávajú priamo pri písaní receptu. Pri preklepe appka ponúkne existujúcu surovinu („Nemysleli ste Paradajky?“) a admin vie novú surovinu zaradiť do kategórie, premenovať alebo zlúčiť s inou.
- **Prepočet porcií.** V náhľade receptu sa tlačidlami − a + mení počet porcií. Množstvá sa prepočítajú a prevedú na jednotku, ktorá sa dá odmerať (2000 g → 2 kg, 0,5 PL → 1½ ČL).
- **Kategórie receptov** spravuje admin priamo v appke: pridá, premenuje, zoradí aj zmaže nepoužívanú.
- **Nákupný zoznam** spoločný pre všetkých. Suroviny z viacerých receptov sa zlúčia a sčítajú, zoznam sa dá odškrtávať a odoslať do Google Keep alebo inej appky. Zmeny od ostatných sa v otvorenom zozname objavia do pár sekúnd.
- **Prehľad, čo sa kedy varilo.** Tlačidlom Uvarené sa zapíše deň varenia (aj spätne) a k nemu nepovinná fotka. Pri recepte je vidno, pred koľkými dňami sa naposledy varil, a po 90 dňoch appka pripomenie, že by sa hodilo ho zopakovať.
- **Hodnotenie hviezdičkami** a **história** každého receptu (kto ho pridal, upravil, uvaril, ohodnotil).
- **Upozornenia do telefónu** na nový recept, na hodnotenie tvojho receptu a pripomienky dlho nevarených receptov.
- **Prihlásenie** heslom alebo odtlačkom prsta či Face ID, s možnosťou zostať prihlásený 90 dní.
- **Recepty aj bez internetu.** Posledné načítané recepty sa uložia v zariadení a bez pripojenia sa dajú pozerať (meniť nie).
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

Verejná registrácia neexistuje. Účty vytvára administrátor: tlačidlo **Správa** v hornej lište. Každý používateľ si potom v appke môže:

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
| Recepty bez internetu | áno | nie | áno |
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
| Bez internetu sa namiesto receptov ukáže „Si offline" | V zariadení ešte nie je uložená kópia receptov: appku treba aspoň raz otvoriť s internetom a s prihlásením. Kópia sa zmaže aj pri odhlásení. Bez HTTPS (okrem `localhost`) sa nič neukladá, pozri [B10](#b10-aplikácia-na-ploche-a-service-worker). |

## A9. Aktualizácia na novú verziu

Ak máš nastavené automatické nahrávanie ([A10](#a10-automatické-nahrávanie-na-server)), krok 2 urobí GitHub sám po vydaní verzie.

1. Zálohuj databázu a priečinok `uploads/recipes/` (pozri [B12](#b12-zálohovanie)).
2. Nahraj nové súbory appky cez staré. Súbor `includes/.env` a priečinok `uploads/recipes/` nechaj tak, ako sú.
3. Ak nová verzia prináša súbor `sql/migracia-X.Y.sql`, naimportuj ho cez phpMyAdmin (**Import**). Pri prechode z 1.0 na 1.1 je to `sql/migracia-1.1.sql`, pri prechode na 1.5 `sql/migracia-1.5.sql`. Pri preskočení viacerých verzií naimportuj všetky chýbajúce migrácie od najstaršej.
4. Otvor appku a skontroluj, že beží. Ak sa zobrazí „Chýba povinná konfiguračná premenná…", doplň uvedený riadok do `includes/.env` (vzor je v `includes/.env.example`).

Kým migrácia nie je naimportovaná, appka beží ďalej po starom, len bez nových funkcií (v logu je riadok `FOTKY: chýba tabuľka recipe_photos` alebo `KATEGÓRIE: chýba tabuľka kategorie_receptov`). Migráciu je bezpečné spustiť aj opakovane.

**Čo urobí `migracia-1.1.sql`:** vytvorí tabuľku `recipe_photos` a doterajšiu fotku každého receptu do nej prenesie ako prvú fotku galérie. Dátum fotky zistí z názvu súboru (appka fotky pomenúva podľa času nahratia) a autora z histórie receptu.

**Čo urobí `migracia-1.5.sql`:** vytvorí tabuľku `kategorie_receptov` a naplní ju doterajšími siedmimi kategóriami s rovnakými kľúčmi, aké majú uložené recepty, takže recepty sa nemenia. Bez nej appka ukazuje doterajší pevný zoznam a v správe sa kategórie upravovať nedajú. Ak admin niektorú zo siedmich základných kategórií zmaže, opakované spustenie migrácie ju vráti.

## A10. Automatické nahrávanie na server

Keď je projekt na GitHube, nová verzia sa dá na server nahrať automaticky. Stačí na GitHube vydať verziu (Release) a GitHub sám:

1. spustí testy (pozri [B14](#b14-automatické-testy-a-pravidlá-pre-novú-verziu)); ak niečo zlyhá, na server sa nenahrá nič,
2. cez šifrované FTP (FTPS) nahrá len súbory, ktoré sa od minulého nahratia zmenili, a zmaže tie, ktoré v novej verzii už nie sú,
3. overí, že appka na serveri odpovedá, že server vydáva práve nahratú verziu a že súbory s heslami nie sú dostupné z internetu.

Keď niektorý krok zlyhá, GitHub pošle e-mail tomu, kto verziu vydal. Celý postup je v `.github/workflows/nasadenie.yml`.

**Na server sa nikdy nenahrá ani sa z neho nezmaže:** `includes/.env`, `vendor/`, `uploads/` (fotky), `sql/`, `install.php`, priečinok `testy/`, súbory pre GitHub, `README.md`, `LICENSE` a `composer.json` / `composer.lock`.

### Jednorazové nastavenie

1. Appka už musí na serveri bežať, prvú inštaláciu urob ručne podľa [A2](#a2-inštalácia-na-webhosting). Pred každým nahratím GitHub overí, že v cieľovom priečinku je `login.php` a `sw.js`, aby súbory omylom nenahral do iného webu na tom istom hostingu.
2. V administrácii hostingu si odporúčam založiť samostatný FTP účet len na nahrávanie.
3. Na GitHube otvor repozitár → **Settings → Secrets and variables → Actions → New repository secret** a pridaj:

| Secret | Hodnota |
|---|---|
| `FTP_SERVER` | Adresa FTP servera z administrácie hostingu. |
| `FTP_USERNAME` | Meno FTP účtu. |
| `FTP_PASSWORD` | Heslo FTP účtu. |
| `FTP_DIR` | Priečinok appky na FTP, s lomkou na konci, napr. `/recepty/`. Ak sa FTP účet prihlasuje rovno do priečinka appky, tento Secret nevytváraj. |
| `APP_URL` | Adresa appky, napr. `https://recepty.example.com`. |

Hodnoty Secrets po uložení neuvidí nikto, ani ty (dajú sa len prepísať), a v záznamoch z GitHubu sa zobrazujú ako `***`. Pri verejnom repozitári je vidno len to, že nahrávanie existuje a kedy prebehlo, nie kam.

4. Nastavenie over skúškou: **Actions → Nasadenie na server → Run workflow**, do poľa „verzia" napíš poslednú vydanú verziu (napr. `V1.1.0`), zaškrtni **Len skúška** a spusti. GitHub sa prihlási na FTP, skontroluje priečinok a vypíše, čo by nahral, ale na serveri nič nezmení.

### Vydanie novej verzie

1. Nahraj zmeny do `main`. Testy prebehnú automaticky, výsledok je v záložke **Actions**.
2. Na GitHube vytvor Release s novým tagom (napr. `V1.2.0`) a zverejni ho.
3. V záložke **Actions** sleduj priebeh. Prvé nahratie nahrá všetky súbory, ďalšie už len zmeny.
4. Ak verzia prináša `sql/migracia-X.Y.sql`, zálohuj databázu a migráciu naimportuj cez phpMyAdmin ([A9](#a9-aktualizácia-na-novú-verziu)). Appka beží aj bez nej, len bez nových funkcií.

Release označený ako **pre-release** sa na server nenahrá.

**Keď sa zmení `composer.lock`** (nové alebo aktualizované knižnice), test na to upozorní. Priečinok `vendor/` sa automaticky nenahráva, preto ho pred vydaním verzie nahraj ručne: spusti `composer install --no-dev` a nahraj `vendor/` cez FTP.

Zoznam nahratých súborov si GitHub ukladá na server do `includes/.ftp-deploy-sync-state.json` a podľa neho nabudúce nahrá len zmeny. Súbor nemaž. Ak sa stratí, ďalšie nahratie nahrá všetko znova.

### Keď nahrávanie zlyhá

| Hlásenie | Riešenie |
|---|---|
| Chýba Secret … | Doplň uvedený Secret (krok 3). |
| FTP prihlásenie zlyhalo | Nesprávne `FTP_USERNAME` alebo `FTP_PASSWORD`. |
| K FTP serveru sa nedá pripojiť | Nesprávny `FTP_SERVER`, alebo hosting povoľuje FTP len z niektorých krajín či adries. GitHub sa pripája zo zahraničia. |
| FTP server neumožnil šifrované spojenie | Hosting nepodporuje šifrované FTP (FTPS) na porte 21. |
| V priečinku z FTP_DIR nie je appka | `FTP_DIR` ukazuje inam, než kde na serveri leží `index.php` appky. |
| Server nevydáva nahratú verziu sw.js | Súbory sa nahrali, ale web ich vydáva z iného priečinka (zlé `FTP_DIR`), alebo hosting chvíľu drží starú verziu v cache. |
| … je dostupný z internetu | Na serveri chýba alebo nefunguje `includes/.htaccess` či `sql/.htaccess`. Treba to opraviť hneď. |

## A11. Návrat k predchádzajúcej verzii

Ak nová verzia na serveri nefunguje:

1. Na GitHube otvor **Actions → Nasadenie na server → Run workflow**.
2. Do poľa „verzia" napíš tag verzie, ktorá fungovala (napr. `V1.1.0`, na veľkosti písmen nezáleží).
3. Klikni na **Run workflow**.

GitHub nahrá súbory staršej verzie a zmaže tie, ktoré pridala novšia. Testy sa pri návrate nespúšťajú, kontrola po nahratí áno. Opravenú verziu potom vydáš ako nový Release.

Databázu pri návrate vracať netreba, ak sa dodrží pravidlo z [B14](#b14-automatické-testy-a-pravidlá-pre-novú-verziu): migrácie len pridávajú tabuľky a stĺpce a staršia verzia appky ich jednoducho ignoruje. Záloha databázy pred migráciou ([A9](#a9-aktualizácia-na-novú-verziu)) je poistka pre prípad, že by sa pokazili samotné dáta.

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
| `sw.js` | Service worker: cache statických súborov a knižníc z CDN, uložená kópia receptov na čítanie bez internetu, fotky, offline stránka, príjem upozornení. |
| `manifest.json`, `app-icons/` | Údaje a ikony pre inštaláciu na plochu. |
| `offline.html` | Stránka zobrazená bez pripojenia, keď v zariadení nie je uložená kópia receptov, a pre stránky, ktoré bez servera nefungujú. |
| `admin/index.php` | Správa používateľov a kategórií receptov (len admin). |
| `admin/suroviny.php` | Správa surovín (len admin): kategória, premenovanie, zlúčenie, zmazanie nepoužívanej. |
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
| `sql/migracia-1.1.sql` | Aktualizácia databázy z verzie 1.0 na 1.1 (galéria fotiek). Pri novej inštalácii netreba. |
| `sql/migracia-1.5.sql` | Aktualizácia databázy na verziu 1.5 (kategórie receptov v databáze). Pri novej inštalácii netreba. |
| `uploads/recipes/` | Fotky receptov. |
| `vendor/` | Knižnice stiahnuté Composerom. Nie je súčasťou projektu na GitHube. |
| `testy/` | Automatické testy a kontroly pred nahratím na server a po ňom (pozri [B14](#b14-automatické-testy-a-pravidlá-pre-novú-verziu)). Na server sa nenahráva. |
| `.github/workflows/testy.yml` | Spustenie testov na GitHube pri každom nahratí do `main`. |
| `.github/workflows/nasadenie.yml` | Nahratie verzie na server po vydaní a návrat k staršej verzii ([A10](#a10-automatické-nahrávanie-na-server), [A11](#a11-návrat-k-predchádzajúcej-verzii)). |

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
| `recipe_photos` | Galéria fotiek receptu: cesta k fotke, dátum varenia, kto ju pridal. |
| `kategorie_receptov` | Kategórie receptov: kľúč uložený pri recepte, zobrazený názov, poradie. |
| `suroviny` | Číselník surovín. |
| `recept_suroviny` | Suroviny priradené k receptom, s množstvom, jednotkou a poznámkou. |
| `recept_suroviny_zaloha` | Pôvodný text surovín z prevodu starých receptov. |
| `nakupny_zoznam` | Spoločný nákupný zoznam. |

Dôležité väzby:

- Zmazaním receptu sa zmažú aj jeho suroviny (`recept_suroviny`), hodnotenia a fotky (záznamy v `recipe_photos` aj súbory na disku). História v `recipe_logs` ostáva.
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

**Kategórie** sú v tabuľke `kategorie_receptov` a spravuje ich admin v **Správe** (časť Kategórie receptov):

- **Pridať** novú kategóriu. Pridá sa na koniec zoznamu. Rovnaký názov (ani s inými veľkými písmenami alebo bez diakritiky) sa nepridá druhýkrát.
- **Premenovať.** Recept má v `recipes.category` uložený kľúč kategórie (napr. `Fit`), na obrazovke sa ukazuje názov („Nízko kal. / Fit"). Premenovanie mení len názov, takže sa hneď prejaví pri všetkých receptoch a recepty sa neprepisujú. Nová kategória má kľúč rovnaký ako názov, pod ktorým vznikla.
- **Zmeniť poradie** šípkami. Poradie platí pre filter aj formulár receptu.
- **Zmazať** sa dá len kategória, ktorú nepoužíva žiadny recept. Pri používanej je pri nej počet receptov a kôš je vypnutý.

Ak má recept kategóriu, ktorá v zozname nie je (napr. z ručnej úpravy databázy), appka ju ukáže pod jej kľúčom na konci filtra aj formulára, aby sa pri úprave receptu nestratila, a správa na ňu upozorní. Kým nie je naimportovaná migrácia 1.5, appka používa pevný zoznam `RECEPT_KATEGORIE_ZAKLAD` z `includes/functions.php` a v správe sa kategórie upravovať nedajú.

**Fotky.** Prehliadač fotku pred odoslaním zmenší, server ju potom prevedie na JPEG s rozmerom najviac 1200 × 800 bodov a uloží do `uploads/recipes/` pod náhodným menom. Prijíma JPG, PNG a WEBP do 8 MB.

**Galéria.** Recept môže mať ľubovoľný počet fotiek, každú s dátumom varenia a menom toho, kto ju pridal (tabuľka `recipe_photos`).

- Nová fotka sa **pridá** k starším, nikdy ich nenahradí. Dá sa pridať v okne Uvarené (s dňom varenia) alebo vo formulári receptu (s dátumom z poľa „Dátum novej fotky", predvolene dnes, pri novom recepte deň posledného varenia).
- Na karte receptu je najnovšia fotka podľa dátumu. Jej cesta je skopírovaná aj v `recipes.image_path`, takže zdieľanie a ďalšie časti appky galériu poznať nemusia. Ak má recept viac fotiek, na karte je ich počet.
- V náhľade receptu sa medzi fotkami listuje šípkami, posúvaním prstom alebo klikom na miniatúru. Pod fotkou je dátum a autor.
- Mazať jednotlivé fotky môže len admin, vo formulári receptu ikonou koša na miniatúre. Súbor sa z disku odstráni hneď.
- Pridanie aj zmazanie fotky sa zapíše do histórie receptu.

**Uvarené.** Tlačidlo na karte otvorí okno s dňom varenia (predvolene dnes, dá sa zvoliť aj skorší deň) a nepovinnou fotkou. Spätne zapísané staršie varenie neposunie „naposledy varené" dozadu, len sa zapíše do histórie a fotka dostane svoj dátum.

**Práva.** Pridávať a upravovať recepty a pridávať fotky môže každý prihlásený. Mazať recepty, mazať fotky z galérie a spravovať používateľov, kategórie a suroviny môže len admin.

**Tlačidlá na karte receptu:** uvarené (okno s dátumom a fotkou), do nákupu, história, upraviť, zmazať (len admin). Kliknutím kamkoľvek inam na kartu sa otvorí náhľad.

## B5. Suroviny

Každá surovina má v tabuľke `suroviny` názov a **kľúč**: názov malými písmenami, bez diakritiky a s jednou medzerou medzi slovami. Podľa kľúča sa suroviny porovnávajú, takže nevznikajú duplicity z preklepov vo veľkosti písmen či dĺžňoch.

Kľúč sa počíta na troch miestach a všade musí vyjsť rovnako:

- `surovina_key()` vo `functions.php`,
- `normKey()` v `suroviny.js`,
- stĺpec `kluc` v `sql/instalacia.sql`.

**Vo formulári receptu** sa surovina vyhľadá a pridá ako riadok s nepovinným množstvom, jednotkou a poznámkou. Ak surovina v číselníku nie je, ponúkne sa jej pridanie. Nová surovina vznikne až pri uložení receptu a nemá kategóriu.

**Návrh pri preklepe.** Keď sa napísaný názov v číselníku nenájde, ale od niektorej suroviny sa líši len o jedno písmeno (pri názvoch od 9 znakov o dve), výber ju ponúkne ako „Nemysleli ste Paradajky?“ pred možnosťou pridať novú. Rátajú sa vynechané, pridané, zamenené aj prehodené susedné písmená (funkcia `vzdialenost` v `suroviny.js`). Ak inak nič nesedí, návrh je zvýraznený ako prvý, takže Enter vyberie existujúcu surovinu. Zámerne novú surovinu stačí ťuknúť na „Pridať … ako novú surovinu“. Rovnako to funguje v nákupnom zozname.

**Správa surovín** (Správa → Suroviny, len admin, `admin/suroviny.php`). Predvolene ukazuje suroviny bez kategórie, teda tie, ktoré pribudli pri písaní receptov. Hľadaním sa dá upraviť ktorákoľvek. Odznak pri tlačidle Suroviny v Správe ukazuje, koľko surovín je bez kategórie.

- **Kategória:** výber z kategórií, ktoré už suroviny majú. Nákupný zoznam ju hneď použije (surovina bez kategórie je v skupine „Ostatné“).
- **Premenovanie** opraví názov vo všetkých receptoch aj v nekúpených položkách nákupu a zapíše sa do histórie dotknutých receptov. Ak taký názov (bez ohľadu na veľké písmená a diakritiku) už má iná surovina, appka ponúkne zlúčenie.
- **Zlúčenie** („Zlúčiť s inou…“): vyberie sa surovina, ktorá ostane. Recepty a položky nákupu prejdú na ňu, zlučovaná surovina sa zmaže a ak tá, ktorá ostane, nemala kategóriu, prevezme ju. Všetko prebehne v jednej transakcii. Rovnaké nekúpené položky v nákupe sa sčítajú. Ak mal recept obe suroviny, bude v ňom dvakrát a appka na to upozorní. Zlúčenie sa zapíše do histórie receptov a nedá sa vrátiť.
- **Zmazanie** je možné len pri surovine, ktorú nepoužíva žiadny recept. Položka v nákupe ostane ako vlastná.

Zmeny surovín nepotrebujú migráciu, všetko je v existujúcich tabuľkách.

**Jednotky** sú dané zoznamom `SUROVINA_JEDNOTKY` vo `functions.php`: g, kg, ml, dl, l, ks, PL, ČL, šálka, štipka, balenie, plátok, strúčik.

Pole „Poznámky / postup" je voľný text pre všetko ostatné (postup, pomôcky, kde čo kúpiť).

## B6. Prepočet porcií

Recept má nepovinný počet porcií. Ak je vyplnený a aspoň jedna surovina má množstvo, v náhľade sa zobrazia tlačidlá − a +.

- V databáze ostávajú množstvá vždy pre pôvodný počet porcií, prepočet je len zobrazenie.
- Pri pôvodnom počte porcií sa množstvá ukazujú presne tak, ako sú zapísané v recepte.
- Po prepočte sa množstvo prevedie na najvhodnejšiu jednotku **tej istej skupiny** (funkcia `prevedDavku` v `suroviny.js`):

| Skupina | Prevod | Príklad |
|---|---|---|
| hmotnosť | g a kg, od 1000 g kilogramy | 250 g × 8 = 2 kg |
| objem | ml, dl a l sa zobrazia v ml, od 1000 ml v litroch | 0,5 l × ¼ = 125 ml |
| lyžice | ČL a PL (1 PL = 3 ČL). Na PL, keď to vyjde aspoň na 1 PL po polovičkách alebo bolo zadané v PL, inak ČL | 3 ČL × 2 = 2 PL, 1 PL × ½ = 1½ ČL |
| kusy, balenia, plátky, strúčiky | na polovice | 2 ks × ⅔ = 1½ ks |
| šálka | na štvrtiny | ¾ šálka |
| štipka | na celé | 1 štipka |

- Medzi skupinami sa neprevádza (lyžica na gramy by potrebovala hustotu každej suroviny).
- Lyžice, šálky a kusy sa zobrazujú v zlomkoch (¼, ½, ¾), ČL a šálky po štvrtinách, PL a kusy po polovičkách.
- Gramy a mililitre sa zaokrúhľujú podľa veľkosti: od 100 na celé čísla, od 10 na jedno desatinné miesto, menšie hodnoty na dve.
- Rovnaký prevod používa nákupný zoznam. Tam sa navyše kusy a štipky zaokrúhľujú nahor na celé, aby sa nekúpilo menej.

## B7. Nákupný zoznam

Zoznam je jeden pre všetkých používateľov. Otvára sa košíkom v hornej lište alebo plávajúcim tlačidlom vpravo dole, ktoré je vidno, kým je čo kúpiť.

**Pridanie receptu.** Tlačidlo s košíkom na karte pridá suroviny pre pôvodný počet porcií, tlačidlo „Do nákupu" v náhľade pre práve nastavený počet.

**Zlučovanie:**

- Rovnaká surovina s rovnakou jednotkou sa sčíta.
- Pred sčítaním sa hmotnosť prevedie na gramy a objem na mililitre, takže 500 g + 1 kg = 1,5 kg. Zobrazuje sa rovnako ako pri prepočte porcií ([B6](#b6-prepočet-porcií)): v kg a l od hodnoty 1000, lyžice v zlomkoch, kusy nahor na celé.
- Položka bez množstva sa pripojí k existujúcej položke tej istej suroviny.
- Rôzne druhy jednotiek (napr. gramy a lyžice) ostávajú ako dva riadky.
- Pri položke je vidno, z ktorých receptov pochádza.

**Vlastné položky** (napr. toaletný papier) sa do zoznamu píšu priamo a do číselníka surovín sa nepridávajú.

**Odoslanie do inej appky.** Tlačidlo „Odoslať" otvorí systémové zdieľanie s nekúpenými položkami, každá na vlastnom riadku. Názov poznámky tvoria názvy receptov. Google Keep nemá pre bežné účty rozhranie, cez ktoré by appka mohla zoznam zapísať priamo, preto to ide cez zdieľanie. Zoznam v Keepe a v appke sa potom už nesynchronizujú.

**Zmeny od ostatných.** Kým je zoznam otvorený a appka je na obrazovke, každých 5 sekúnd sa načíta zo servera. Keď dvaja nakupujú naraz, odškrtnutie jedného uvidí druhý do pár sekúnd. Zoznam aj odznak s počtom pri tlačidle Nákup sa obnovia aj hneď po návrate do appky (prepnutie z inej appky, odomknutie telefónu) a po návrate internetu. Na pozadí sa nič nenačítava.

- Zoznam sa prekreslí, len keď sa zmenil, a ostane na tom mieste, kde bol posunutý.
- Odpoveď zo servera, ktorá prišla počas odškrtávania alebo pridávania, sa zahodí. Políčko tak neskočí späť.
- Kým je zoznam otvorený, obnovovanie udržiava prihlásenie aktívne. Keď prihlásenie predsa vyprší, appka to raz oznámi a ponúkne obnovenie stránky.
- Interval je v `script.js` ako `OBNOVA_MS`.

Zoznam vyžaduje pripojenie na internet.

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

`manifest.json` umožňuje pridať appku na plochu. `sw.js` rieši tieto veci:

- **Cache statických súborov** (štýly, skripty, ikony) a knižníc Bootstrap z CDN, aby sa appka načítala rýchlo a mala vzhľad aj bez internetu.
- **Recepty bez internetu.** Hlavná stránka sa vždy načíta zo servera a jej kópia sa uloží v zariadení. Keď server neodpovie, ukáže sa táto kópia (pozri [Offline režim](#offline-režim)).
- **Fotky receptov** v samostatnej cache, najviac 250 naposledy uložených. Názov fotky je jedinečný a obsah sa nemení, preto sa po uložení berú z cache.
- **Ostatné stránky PHP nikdy z cache** (prihlásenie, správa používateľov, biometria). Pri výpadku pripojenia sa ukáže `offline.html`.
- **Príjem upozornení** a otvorenie správneho receptu po kliknutí.

### Offline režim

Pri každom načítaní hlavnej stránky prihláseným používateľom si `sw.js` uloží jej kópiu. `index.php` ju na to označí hlavičkou `X-Recepty-Offline: 1`, takže presmerovanie na prihlásenie ani odpovede akcií sa neuložia. Keď sa server nedá zastihnúť, neodpovie do 10 sekúnd alebo hlási výpadok hostingu (502, 503, 504), ukáže sa uložená kópia:

- hore je pásik **Bez pripojenia** s časom uloženia a tlačidlom **Skúsiť znova**,
- funguje hľadanie, filter kategórií, radenie, náhľad receptu, galéria uložených fotiek, prepočet porcií aj zdieľanie textu receptu,
- tlačidlá, ktoré potrebujú server (pridanie, úprava, Uvarené, hodnotenie, história, nákupný zoznam, notifikácie, biometria, správa používateľov, odhlásenie), sú vyblednuté a po ťuknutí vysvetlia, že bez internetu to nejde; v `index.php` majú atribút `data-vyzaduje-siet`,
- fotka, ktorá v zariadení uložená nie je, sa nahradí obrázkom „Fotka nie je uložená v zariadení",
- keď sa internet vráti, pásik zozelenie a ponúkne **Načítať aktuálne recepty**. Dovtedy ostanú tlačidlá zablokované, lebo uložená kópia môže mať neplatný bezpečnostný token (CSRF).

Keď sa pripojenie stratí počas používania appky, zobrazí sa rovnaký pásik a zablokujú sa rovnaké tlačidlá. Po návrate pripojenia sa všetko odblokuje samo.

Uložená je vždy len jedna kópia, po prihlásení iného používateľa sa prepíše jeho stránkou. Kópia aj fotky sa zmažú pri odhlásení a vždy, keď server pošle zariadenie na prihlásenie (napr. vypršané prihlásenie).

Po nahratí novej verzie si nový service worker kópiu stránky uloží hneď, ako prevezme otvorenú stránku (požiada ho o to `script.js`), takže offline režim funguje už po prvom otvorení appky s internetom.

### Úprava a nasadenie novej verzie

Statické súbory si prehliadače držia v cache. Po každej zmene v `style.css`, `script.js`, `suroviny.js`, `theme.js`, `webauthn-client.js` alebo `offline.html` preto treba:

1. v `sw.js` zvýšiť číslo v `CACHE_NAME` (napr. `recepty-static-v22` na `v23`),
2. v `index.php` a `login.php` zvýšiť číslo za `?v=` pri odkazoch na zmenené súbory.

Bez toho uvidia používatelia starú verziu, kým sa cache neobnoví sama. Automatický test ([B14](#b14-automatické-testy-a-pravidlá-pre-novú-verziu)) skontroluje, či sa na to nezabudlo.

Pri zmene verzie Bootstrapu alebo ikon v `index.php` treba rovnaké adresy zapísať aj do `CDN_ASSETS` v `sw.js`, pri ikonách aj adresu písma (`fonts/bootstrap-icons.woff2?…` zo súboru `bootstrap-icons.min.css`). Inak by uložená kópia offline nemala vzhľad. Adresy z `index.php` kontroluje aj automatický test, písmo ikon nie.

## B11. Zápis chýb

Chyby a varovania PHP sa zapisujú do `includes/debug-log.txt`. Ak tam PHP nemôže zapisovať, idú do štandardného logu hostingu. Návštevníkom sa podrobnosti nezobrazujú, pokiaľ nie je v `.env` nastavené `APP_DEBUG=1`.

Časté značky v logu:

| Značka | Význam |
|---|---|
| `PUSH ZLYHAL` | Upozornenie sa nepodarilo odoslať, nasleduje dôvod. |
| `PRIPOMIENKA zlyhala` | Chyba pri posielaní pripomienky. |
| `ZOSTAT PRIHLASENY` | Problém s trvalým prihlásením, zvyčajne chýbajúca tabuľka `auth_tokens`. |
| `WEBAUTHN … ERROR` | Chyba biometrie. |
| `SUROVINY`, `NAKUP`, `PORCIE`, `FOTKY` | V databáze chýba tabuľka alebo stĺpec (napr. nenaimportovaná migrácia). Appka vtedy beží ďalej, len bez danej funkcie. |

## B12. Zálohovanie

Zálohovať treba tri veci:

- databázu (export cez phpMyAdmin),
- priečinok `uploads/recipes/` s fotkami,
- súbor `includes/.env`.

Všetko ostatné sa dá znova stiahnuť z projektu.

## B13. Známe obmedzenia

- Bez internetu sa recepty dajú len pozerať, meniť nie (pozri [Offline režim](#offline-režim)).
- Rozhranie je len po slovensky.

## B14. Automatické testy a pravidlá pre novú verziu

Testy sú v priečinku `testy/`. GitHub ich spúšťa pri každom nahratí do `main` a pred nahratím novej verzie na server (výsledok je v záložke **Actions**). Hlavný skript `testy/spusti-testy.sh` overí:

1. prostredie: PHP 8.4.1 alebo novšie, potrebné rozšírenia a priečinok `vendor/`,
2. syntax všetkých PHP súborov,
3. pravidlá pre novú verziu (nižšie, skript `testy/kontrola-pravidiel.sh`),
4. **novú inštaláciu:** import `sql/instalacia.sql` (aj druhýkrát po sebe) a celý priechod appkou,
5. **databázu z predchádzajúcej verzie bez migrácie:** nová appka na nej musí bežať,
6. **migrácie** na tej istej databáze (každú dvakrát po sebe), znova priechod appkou a porovnanie štruktúry s novou inštaláciou.

Priechod appkou (`testy/smoke.php`) robí to isté ako človek: prihlási sa (aj nesprávnym heslom), pridá recept s fotkou a surovinami, upraví ho, zapíše varenie, ohodnotí, pozrie históriu, zmaže fotku z galérie, použije nákupný zoznam, zmaže recept, v správe pridá, premenuje, posunie a zmaže kategóriu receptov (a overí, že používanú zmazať nejde), v správe surovín zaradí, premenuje a zlúči surovinu s preklepom, otvorí biometriu a `cron.php`, overí „Zostať prihlásený" a odhlási sa. Skontroluje aj, že hlavnú stránku si appka môže uložiť na čítanie bez internetu a presmerovanie ani odpovede akcií nie. Po každom priechode skontroluje aj záznam chýb appky. Samotný offline režim v prehliadači test nespúšťa, ten treba vyskúšať ručne (napr. v Chrome: Nástroje pre vývojárov → Network → Offline).

Po nahratí na server skript `testy/kontrola-po-nahrati.sh` overí ostrú appku a `testy/kontrola-ftp.sh` pred nahratím skontroluje FTP a cieľový priečinok.

### Spustenie na vlastnom počítači

Treba PHP 8.4.1 alebo novšie, bežiacu MariaDB a stiahnuté knižnice (`composer install --no-dev`). V priečinku projektu spusti:

```
TEST_DB_ROOT_USER=root TEST_DB_ROOT_PASS=heslo bash testy/spusti-testy.sh
```

Účet databázy musí vedieť zakladať databázy a používateľov. Test si založí databázy `recepty_test_nova` a `recepty_test_stara` a používateľa `recepty_test` a databázy na konci zmaže (s `TEST_PONECHAT=1` ich nechá). Appku spúšťa z dočasnej kópie, takže tvoj `includes/.env`, záznam chýb ani fotky nezmení. Ďalšie nastavenia (`TEST_DB_HOST`, `TEST_DB_PORT`, `PHP_BIN`, `TEST_PORT`) sú popísané na začiatku skriptu. Na Windows sa dá spustiť cez Git Bash alebo WSL.

### Pravidlá pre novú verziu

| Pravidlo | Ako ho overí test |
|---|---|
| Po zmene CSS alebo JS zvýšiť `CACHE_NAME` v `sw.js` a číslo za `?v=` v `index.php` a `login.php`. | Porovná s predchádzajúcou verziou (tagom). Chyba. |
| Zmeny databázy dať do `sql/migracia-X.Y.sql` a doplniť aj do `sql/instalacia.sql`. | Štruktúra databázy po migrácii sa musí zhodovať s novou inštaláciou. Chyba. |
| Appka musí bežať aj bez migrácie (kontrola existencie tabuľky, napr. `recipes_have_photos()`). | Nová appka sa spustí nad databázou predchádzajúcej verzie. Chyba. |
| Migráciu musí ísť spustiť opakovane bez zdvojenia dát. | Spustí ju dvakrát a porovná počty riadkov. Chyba. |
| Migrácie len pridávajú. Tabuľku ani stĺpec, ktorý používa predchádzajúca verzia, nemazať ani nepremenovať v tej istej verzii; urobí sa to až v ďalšej, keď je jasné, že nová funguje. Inak by pri návrate ([A11](#a11-návrat-k-predchádzajúcej-verzii)) nestačilo vrátiť súbory. | Netestuje sa, treba na to myslieť. |
| Zmena `composer.lock` znamená ručne nahrať `vendor/`. | Upozornenie. |
| Knižnice z CDN v `index.php` musia byť aj v `CDN_ASSETS` v `sw.js`, aby mala appka vzhľad aj offline. | Porovná adresy. Chyba. |
| Každá verzia má záznam v Histórii verzií nižšie. | Pri vydaní verzie upozornenie, ak záznam chýba. |

## B15. História verzií

**1.6**

- Správa surovín (Správa → Suroviny): surovinu pridanú pri recepte vie admin zaradiť do kategórie, premenovať, zlúčiť s inou (preklep, iný názov tej istej veci) a zmazať nepoužívanú. Zmeny sa prejavia vo všetkých receptoch aj v nákupnom zozname.
- Pri písaní suroviny s preklepom appka ponúkne existujúcu: „Nemysleli ste Paradajky?“. Funguje vo formulári receptu aj v nákupnom zozname.
- Tlačidlo Suroviny v Správe ukazuje počet surovín bez kategórie.
- Databáza sa nemení, migrácia netreba.

**1.5**

- Prepočet porcií prevádza jednotky na takú, ktorá sa dá odmerať: 2000 g → 2 kg, 0,13 l → 125 ml, 0,5 PL → 1½ ČL, 6 ČL → 2 PL. Lyžice a kusy sú v zlomkoch (1½ ks). Pri pôvodnom počte porcií sa ukazuje presne to, čo je v recepte.
- Nákupný zoznam používa rovnaký prevod a kusy zaokrúhľuje nahor na celé.
- Oprava: množstvo pridané do nákupu pre iný počet porcií sa zaokrúhľovalo pred prevodom na ml a g (0,375 l sa zobrazilo ako 380 ml).
- Kategórie receptov spravuje admin v appke (tlačidlo Správa): pridať, premenovať, zmeniť poradie, zmazať nepoužívanú. Recepty ukazujú názov kategórie.
- Treba naimportovať `sql/migracia-1.5.sql`. Kým nie je naimportovaná, appka beží s doterajším zoznamom kategórií.

**1.4**

- Nákupný zoznam sa obnovuje sám. Kým je otvorený, každých 5 sekúnd sa načítajú zmeny od ostatných, takže keď nakupujú dvaja naraz, vidia odškrtnutia toho druhého.
- Zoznam aj odznak s počtom sa obnovia aj po návrate do appky a po návrate internetu. Na pozadí appka nič nenačítava.
- Zoznam sa pri obnove neprekresľuje zbytočne a ostane posunutý tam, kde bol. Práve odškrtnutá položka neskočí späť.
- Pri vypršanom prihlásení appka namiesto opakovanej chyby pripojenia raz ponúkne obnovenie stránky.
- Databáza sa nemení, migrácia netreba.

**1.3**

- Recepty sa dajú pozerať aj bez internetu. Appka si pri každom načítaní uloží kópiu receptov a pri výpadku pripojenia ju ukáže s pásikom Bez pripojenia a časom uloženia. Funguje hľadanie, filtre, náhľad receptu aj prepočet porcií.
- Pridávanie, úpravy, Uvarené, hodnotenie a nákupný zoznam potrebujú internet. Ich tlačidlá sú offline vyblednuté a po ťuknutí vysvetlia prečo.
- Bootstrap a ikony sa ukladajú do cache, fotky receptov do vlastnej cache s limitom 250. Pri odhlásení sa uložené recepty aj fotky zo zariadenia zmažú.
- Stránka „Si offline" má väčšie tlačidlá a odkaz na uložené recepty.
- Automatický test overuje, že adresy knižníc v `index.php` a `sw.js` sedia.
- Databáza sa nemení, migrácia netreba.

**1.2**

- Automatické nahrávanie na server po vydaní verzie na GitHube: šifrované FTP, len zmenené súbory, kontrola cieľového priečinka pred nahratím a kontrola appky po nahratí.
- Návrat k staršej verzii jedným spustením na GitHube.
- Automatické testy pri každom nahratí do `main`: syntax PHP, priechod appkou nad novou aj starou databázou, migrácie, porovnanie štruktúry databázy a pravidlá pre cache a verzie súborov.
- Appka ani databáza sa nemenia, migrácia netreba.

**1.1**

- Galéria fotiek receptu: nová fotka sa pridá k starším, každá má dátum varenia a autora. Listovanie v náhľade receptu, počet fotiek na karte.
- Tlačidlo Uvarené otvára okno s dňom varenia (aj spätne) a nepovinnou fotkou.
- Mazanie jednotlivých fotiek (len admin), záznamy o fotkách v histórii receptu.
- Oprava: text poznámok v histórii receptu sa vkladal do stránky bez escapovania.
- Databáza: nová tabuľka `recipe_photos`, aktualizácia cez `sql/migracia-1.1.sql`.

**1.0**

- Prvá zverejnená verzia.
