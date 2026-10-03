-- ======================================================================
-- Evidencia receptov — kompletná databáza pre NOVÚ inštaláciu.
--
-- Vytvorí všetky tabuľky a naplní zoznam surovín. Používateľov ani recepty
-- nevytvára — prvého administrátora založíš cez install.php.
--
-- Import: phpMyAdmin → vyber databázu → Import → tento súbor,
-- alebo:  mysql -u POUZIVATEL -p NAZOV_DATABAZY < sql/instalacia.sql
--
-- Súbor sa dá spustiť aj opakovane, existujúce tabuľky a dáta nezmení.
-- Testované na MariaDB 10.5 a 10.11.
-- ======================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------
-- Používatelia a prihlasovanie
-- ----------------------------------------------------------------------

-- Účty. Heslo je uložené len ako odtlačok (password_hash). Rola: 'admin' alebo 'user'.
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `role` varchar(32) NOT NULL DEFAULT 'user',
  `full_name` varchar(128) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Neúspešné pokusy o prihlásenie (brzda proti hádaniu hesla: 5 pokusov za 15 minút).
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` varchar(191) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_identifier_time` (`identifier`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- „Zostať prihlásený": jeden riadok na zapamätané zariadenie.
CREATE TABLE IF NOT EXISTS `auth_tokens` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `selector` char(24) NOT NULL,            -- verejná časť cookie, podľa nej sa token hľadá
  `validator_hash` char(64) NOT NULL,      -- SHA-256 odtlačok tajnej časti cookie
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_tokens_selector` (`selector`),
  KEY `idx_auth_tokens_user` (`user_id`),
  KEY `idx_auth_tokens_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Biometria (odtlačok / Face ID): uložené prihlasovacie kľúče zariadení.
CREATE TABLE IF NOT EXISTS `webauthn_credentials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `credential_id` varchar(1024) NOT NULL,
  `credential_record` mediumtext NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_credential_id` (`credential_id`(255)),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Zariadenia, ktoré si povolili upozornenia (Web Push).
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `endpoint` text NOT NULL,
  `p256dh` varchar(255) NOT NULL,
  `auth` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_endpoint` (`endpoint`(255)),
  KEY `user_id` (`user_id`),
  CONSTRAINT `push_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------
-- Recepty
-- ----------------------------------------------------------------------

-- Recept. `ingredients` je voľný text (poznámky / postup), suroviny sú v recept_suroviny.
CREATE TABLE IF NOT EXISTS `recipes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `last_made_date` date DEFAULT NULL,           -- kedy sa naposledy varil
  `created_by` int(11) NOT NULL,                -- users.id autora
  `ingredients` text DEFAULT NULL,
  `url` varchar(512) DEFAULT NULL,
  `prep_time` int(11) DEFAULT NULL,             -- minúty
  `servings` smallint(5) UNSIGNED DEFAULT NULL, -- počet porcií, základ pre prepočet množstiev
  `category` varchar(50) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,       -- napr. uploads/recipes/recipe_xxx.jpg
  `reminder_sent_at` date DEFAULT NULL,         -- kedy sa poslala pripomienka „dlho ste nevarili"
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- História receptu: kto ho pridal, upravil, uvaril, ohodnotil, zmazal.
CREATE TABLE IF NOT EXISTS `recipe_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipe_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,                -- created | updated | made | rated | deleted
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `recipe_id` (`recipe_id`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Hodnotenie hviezdičkami: jeden používateľ = jeden hlas na recept.
CREATE TABLE IF NOT EXISTS `recipe_ratings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipe_id` int(11) NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `rating` tinyint(3) UNSIGNED NOT NULL,        -- 1 až 5
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_recipe_user` (`recipe_id`,`user_id`),
  KEY `fk_rating_user` (`user_id`),
  CONSTRAINT `fk_rating_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rating_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------
-- Suroviny a nákupný zoznam
-- ----------------------------------------------------------------------

-- Číselník surovín. `kluc` = názov malými písmenami, bez diakritiky, s jednou
-- medzerou medzi slovami; vďaka nemu „Múka hladká" a „muka hladka" je to isté.
CREATE TABLE IF NOT EXISTS `suroviny` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nazov` varchar(100) NOT NULL,
  `kluc` varchar(100) NOT NULL,
  `kategoria` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_suroviny_kluc` (`kluc`),
  KEY `idx_suroviny_kategoria` (`kategoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Suroviny priradené k receptu (s nepovinným množstvom, jednotkou a poznámkou).
CREATE TABLE IF NOT EXISTS `recept_suroviny` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `recept_id` int(11) NOT NULL,
  `surovina_id` int(10) UNSIGNED NOT NULL,
  `mnozstvo` decimal(8,2) DEFAULT NULL,
  `jednotka` varchar(20) DEFAULT NULL,
  `poznamka` varchar(100) DEFAULT NULL,
  `poradie` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_rs_recept` (`recept_id`),
  KEY `idx_rs_surovina` (`surovina_id`),
  CONSTRAINT `fk_rs_recept` FOREIGN KEY (`recept_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rs_surovina` FOREIGN KEY (`surovina_id`) REFERENCES `suroviny` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Záloha pôvodného textu surovín (používa len prevodná stránka admin/prevod-surovin.php).
CREATE TABLE IF NOT EXISTS `recept_suroviny_zaloha` (
  `recept_id` int(11) NOT NULL,
  `povodny_text` text DEFAULT NULL,
  `prevedene_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`recept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Spoločný nákupný zoznam všetkých používateľov.
CREATE TABLE IF NOT EXISTS `nakupny_zoznam` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `surovina_id` int(10) UNSIGNED DEFAULT NULL,  -- NULL = vlastná položka mimo číselníka
  `nazov` varchar(100) NOT NULL,
  `kluc` varchar(100) NOT NULL,
  `mnozstvo` decimal(10,2) DEFAULT NULL,        -- v základnej jednotke: g, ml alebo kusy
  `jednotka` varchar(20) DEFAULT NULL,
  `zdroj` varchar(255) DEFAULT NULL,            -- z ktorých receptov položka pochádza
  `kupene` tinyint(1) NOT NULL DEFAULT 0,
  `pridal` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nz_surovina` (`surovina_id`),
  KEY `idx_nz_kupene` (`kupene`),
  CONSTRAINT `fk_nz_surovina` FOREIGN KEY (`surovina_id`) REFERENCES `suroviny` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------
-- Základný zoznam surovín (418 položiek v 14 kategóriách)
-- ----------------------------------------------------------------------

-- Múky a obilniny
INSERT IGNORE INTO suroviny (nazov, kluc, kategoria) VALUES
  ('Múka', 'muka', 'Múky a obilniny'),
  ('Krupica', 'krupica', 'Múky a obilniny'),
  ('Bageta', 'bageta', 'Múky a obilniny'),
  ('Múka hladká', 'muka hladka', 'Múky a obilniny'),
  ('Múka polohrubá', 'muka polohruba', 'Múky a obilniny'),
  ('Múka hrubá', 'muka hruba', 'Múky a obilniny'),
  ('Múka celozrnná', 'muka celozrnna', 'Múky a obilniny'),
  ('Múka špaldová', 'muka spaldova', 'Múky a obilniny'),
  ('Múka ražná', 'muka razna', 'Múky a obilniny'),
  ('Múka kukuričná', 'muka kukuricna', 'Múky a obilniny'),
  ('Múka ryžová', 'muka ryzova', 'Múky a obilniny'),
  ('Múka mandľová', 'muka mandlova', 'Múky a obilniny'),
  ('Krupica detská', 'krupica detska', 'Múky a obilniny'),
  ('Krupica hrubá', 'krupica hruba', 'Múky a obilniny'),
  ('Ovsené vločky', 'ovsene vlocky', 'Múky a obilniny'),
  ('Strúhanka', 'struhanka', 'Múky a obilniny'),
  ('Kukuričný škrob', 'kukuricny skrob', 'Múky a obilniny'),
  ('Zemiakový škrob', 'zemiakovy skrob', 'Múky a obilniny'),
  ('Solamyl', 'solamyl', 'Múky a obilniny'),
  ('Pšeno', 'pseno', 'Múky a obilniny'),
  ('Pohánka', 'pohanka', 'Múky a obilniny'),
  ('Krúpy', 'krupy', 'Múky a obilniny'),
  ('Bulgur', 'bulgur', 'Múky a obilniny'),
  ('Kuskus', 'kuskus', 'Múky a obilniny'),
  ('Quinoa', 'quinoa', 'Múky a obilniny'),
  ('Polenta', 'polenta', 'Múky a obilniny'),
  ('Chlieb', 'chlieb', 'Múky a obilniny'),
  ('Rožok', 'rozok', 'Múky a obilniny'),
  ('Žemľa', 'zemla', 'Múky a obilniny'),
  ('Toastový chlieb', 'toastovy chlieb', 'Múky a obilniny'),
  ('Tortilla', 'tortilla', 'Múky a obilniny'),
  ('Lístkové cesto', 'listkove cesto', 'Múky a obilniny'),
  ('Cesto na pizzu', 'cesto na pizzu', 'Múky a obilniny');

-- Cestoviny a ryža
INSERT IGNORE INTO suroviny (nazov, kluc, kategoria) VALUES
  ('Cestoviny', 'cestoviny', 'Cestoviny a ryža'),
  ('Ryža', 'ryza', 'Cestoviny a ryža'),
  ('Špagety', 'spagety', 'Cestoviny a ryža'),
  ('Cestoviny penne', 'cestoviny penne', 'Cestoviny a ryža'),
  ('Cestoviny fusilli', 'cestoviny fusilli', 'Cestoviny a ryža'),
  ('Kolienka', 'kolienka', 'Cestoviny a ryža'),
  ('Široké rezance', 'siroke rezance', 'Cestoviny a ryža'),
  ('Polievkové rezance', 'polievkove rezance', 'Cestoviny a ryža'),
  ('Lasagne', 'lasagne', 'Cestoviny a ryža'),
  ('Tarhoňa', 'tarhona', 'Cestoviny a ryža'),
  ('Halušky', 'halusky', 'Cestoviny a ryža'),
  ('Gnocchi', 'gnocchi', 'Cestoviny a ryža'),
  ('Ryžové rezance', 'ryzove rezance', 'Cestoviny a ryža'),
  ('Ryža guľatozrnná', 'ryza gulatozrnna', 'Cestoviny a ryža'),
  ('Ryža dlhozrnná', 'ryza dlhozrnna', 'Cestoviny a ryža'),
  ('Ryža basmati', 'ryza basmati', 'Cestoviny a ryža'),
  ('Ryža jazmínová', 'ryza jazminova', 'Cestoviny a ryža'),
  ('Ryža na rizoto', 'ryza na rizoto', 'Cestoviny a ryža'),
  ('Ryža natural', 'ryza natural', 'Cestoviny a ryža');

-- Pečenie a sladidlá
INSERT IGNORE INTO suroviny (nazov, kluc, kategoria) VALUES
  ('Cukor', 'cukor', 'Pečenie a sladidlá'),
  ('Čokoláda', 'cokolada', 'Pečenie a sladidlá'),
  ('Droždie', 'drozdie', 'Pečenie a sladidlá'),
  ('Džem', 'dzem', 'Pečenie a sladidlá'),
  ('Puding', 'puding', 'Pečenie a sladidlá'),
  ('Cukor kryštálový', 'cukor krystalovy', 'Pečenie a sladidlá'),
  ('Cukor práškový', 'cukor praskovy', 'Pečenie a sladidlá'),
  ('Cukor trstinový', 'cukor trstinovy', 'Pečenie a sladidlá'),
  ('Cukor vanilkový', 'cukor vanilkovy', 'Pečenie a sladidlá'),
  ('Cukor škoricový', 'cukor skoricovy', 'Pečenie a sladidlá'),
  ('Med', 'med', 'Pečenie a sladidlá'),
  ('Javorový sirup', 'javorovy sirup', 'Pečenie a sladidlá'),
  ('Kypriaci prášok do pečiva', 'kypriaci prasok do peciva', 'Pečenie a sladidlá'),
  ('Sóda bikarbóna', 'soda bikarbona', 'Pečenie a sladidlá'),
  ('Droždie čerstvé', 'drozdie cerstve', 'Pečenie a sladidlá'),
  ('Droždie sušené', 'drozdie susene', 'Pečenie a sladidlá'),
  ('Vanilkový extrakt', 'vanilkovy extrakt', 'Pečenie a sladidlá'),
  ('Vanilkový struk', 'vanilkovy struk', 'Pečenie a sladidlá'),
  ('Vanilkový puding', 'vanilkovy puding', 'Pečenie a sladidlá'),
  ('Čokoládový puding', 'cokoladovy puding', 'Pečenie a sladidlá'),
  ('Želatína', 'zelatina', 'Pečenie a sladidlá'),
  ('Kakao', 'kakao', 'Pečenie a sladidlá'),
  ('Čokoláda horká', 'cokolada horka', 'Pečenie a sladidlá'),
  ('Čokoláda mliečna', 'cokolada mliecna', 'Pečenie a sladidlá'),
  ('Čokoláda biela', 'cokolada biela', 'Pečenie a sladidlá'),
  ('Čokoládová poleva', 'cokoladova poleva', 'Pečenie a sladidlá'),
  ('Kokos strúhaný', 'kokos struhany', 'Pečenie a sladidlá'),
  ('Mak mletý', 'mak mlety', 'Pečenie a sladidlá'),
  ('Hrozienka', 'hrozienka', 'Pečenie a sladidlá'),
  ('Lekvár slivkový', 'lekvar slivkovy', 'Pečenie a sladidlá'),
  ('Džem marhuľový', 'dzem marhulovy', 'Pečenie a sladidlá'),
  ('Džem jahodový', 'dzem jahodovy', 'Pečenie a sladidlá'),
  ('Piškóty', 'piskoty', 'Pečenie a sladidlá'),
  ('Maslové sušienky', 'maslove susienky', 'Pečenie a sladidlá'),
  ('Nutella', 'nutella', 'Pečenie a sladidlá');

-- Mliečne výrobky a vajcia
INSERT IGNORE INTO suroviny (nazov, kluc, kategoria) VALUES
  ('Syr', 'syr', 'Mliečne výrobky a vajcia'),
  ('Syr strúhaný', 'syr struhany', 'Mliečne výrobky a vajcia'),
  ('Syr camembert', 'syr camembert', 'Mliečne výrobky a vajcia'),
  ('Smotana', 'smotana', 'Mliečne výrobky a vajcia'),
  ('Jogurt', 'jogurt', 'Mliečne výrobky a vajcia'),
  ('Tvaroh', 'tvaroh', 'Mliečne výrobky a vajcia'),
  ('Šľahačka', 'slahacka', 'Mliečne výrobky a vajcia'),
  ('Vajcia', 'vajcia', 'Mliečne výrobky a vajcia'),
  ('Mlieko', 'mlieko', 'Mliečne výrobky a vajcia'),
  ('Mlieko kondenzované', 'mlieko kondenzovane', 'Mliečne výrobky a vajcia'),
  ('Mlieko sušené', 'mlieko susene', 'Mliečne výrobky a vajcia'),
  ('Maslo', 'maslo', 'Mliečne výrobky a vajcia'),
  ('Smotana na varenie', 'smotana na varenie', 'Mliečne výrobky a vajcia'),
  ('Smotana na šľahanie', 'smotana na slahanie', 'Mliečne výrobky a vajcia'),
  ('Smotana kyslá', 'smotana kysla', 'Mliečne výrobky a vajcia'),
  ('Jogurt biely', 'jogurt biely', 'Mliečne výrobky a vajcia'),
  ('Jogurt grécky', 'jogurt grecky', 'Mliečne výrobky a vajcia'),
  ('Kefír', 'kefir', 'Mliečne výrobky a vajcia'),
  ('Cmar', 'cmar', 'Mliečne výrobky a vajcia'),
  ('Tvaroh hrudkovitý', 'tvaroh hrudkovity', 'Mliečne výrobky a vajcia'),
  ('Tvaroh mäkký', 'tvaroh makky', 'Mliečne výrobky a vajcia'),
  ('Bryndza', 'bryndza', 'Mliečne výrobky a vajcia'),
  ('Syr eidam', 'syr eidam', 'Mliečne výrobky a vajcia'),
  ('Syr gouda', 'syr gouda', 'Mliečne výrobky a vajcia'),
  ('Syr čedar', 'syr cedar', 'Mliečne výrobky a vajcia'),
  ('Syr parmezán', 'syr parmezan', 'Mliečne výrobky a vajcia'),
  ('Syr mozzarella', 'syr mozzarella', 'Mliečne výrobky a vajcia'),
  ('Syr feta', 'syr feta', 'Mliečne výrobky a vajcia'),
  ('Syr niva', 'syr niva', 'Mliečne výrobky a vajcia'),
  ('Syr hermelín', 'syr hermelin', 'Mliečne výrobky a vajcia'),
  ('Syr tavený', 'syr taveny', 'Mliečne výrobky a vajcia'),
  ('Syr údený', 'syr udeny', 'Mliečne výrobky a vajcia'),
  ('Oštiepok', 'ostiepok', 'Mliečne výrobky a vajcia'),
  ('Parenica', 'parenica', 'Mliečne výrobky a vajcia'),
  ('Mascarpone', 'mascarpone', 'Mliečne výrobky a vajcia'),
  ('Ricotta', 'ricotta', 'Mliečne výrobky a vajcia'),
  ('Cottage cheese', 'cottage cheese', 'Mliečne výrobky a vajcia'),
  ('Syr balkánsky', 'syr balkansky', 'Mliečne výrobky a vajcia'),
  ('Syr halloumi', 'syr halloumi', 'Mliečne výrobky a vajcia'),
  ('Žervé', 'zerve', 'Mliečne výrobky a vajcia');

-- Mäso a údeniny
INSERT IGNORE INTO suroviny (nazov, kluc, kategoria) VALUES
  ('Kuracie mäso', 'kuracie maso', 'Mäso a údeniny'),
  ('Bravčové mäso', 'bravcove maso', 'Mäso a údeniny'),
  ('Hovädzie mäso', 'hovadzie maso', 'Mäso a údeniny'),
  ('Morčacie mäso', 'morcacie maso', 'Mäso a údeniny'),
  ('Mleté mäso', 'mlete maso', 'Mäso a údeniny'),
  ('Anglická slanina', 'anglicka slanina', 'Mäso a údeniny'),
  ('Kuracie prsia', 'kuracie prsia', 'Mäso a údeniny'),
  ('Kuracie stehná', 'kuracie stehna', 'Mäso a údeniny'),
  ('Kuracie krídla', 'kuracie kridla', 'Mäso a údeniny'),
  ('Kurča celé', 'kurca cele', 'Mäso a údeniny'),
  ('Kuracia pečeň', 'kuracia pecen', 'Mäso a údeniny'),
  ('Morčacie prsia', 'morcacie prsia', 'Mäso a údeniny'),
  ('Kačacie prsia', 'kacacie prsia', 'Mäso a údeniny'),
  ('Kačacie stehná', 'kacacie stehna', 'Mäso a údeniny'),
  ('Bravčové karé', 'bravcove kare', 'Mäso a údeniny'),
  ('Bravčová krkovička', 'bravcova krkovicka', 'Mäso a údeniny'),
  ('Bravčové pliecko', 'bravcove pliecko', 'Mäso a údeniny'),
  ('Bravčové stehno', 'bravcove stehno', 'Mäso a údeniny'),
  ('Bravčová panenka', 'bravcova panenka', 'Mäso a údeniny'),
  ('Bravčový bôčik', 'bravcovy bocik', 'Mäso a údeniny'),
  ('Bravčové rebrá', 'bravcove rebra', 'Mäso a údeniny'),
  ('Bravčové koleno', 'bravcove koleno', 'Mäso a údeniny'),
  ('Hovädzie predné', 'hovadzie predne', 'Mäso a údeniny'),
  ('Hovädzie zadné', 'hovadzie zadne', 'Mäso a údeniny'),
  ('Hovädzia sviečkovica', 'hovadzia svieckovica', 'Mäso a údeniny'),
  ('Hovädzia roštenka', 'hovadzia rostenka', 'Mäso a údeniny'),
  ('Teľacie mäso', 'telacie maso', 'Mäso a údeniny'),
  ('Jahňacie mäso', 'jahnacie maso', 'Mäso a údeniny'),
  ('Králik', 'kralik', 'Mäso a údeniny'),
  ('Mleté mäso bravčové', 'mlete maso bravcove', 'Mäso a údeniny'),
  ('Mleté mäso hovädzie', 'mlete maso hovadzie', 'Mäso a údeniny'),
  ('Mleté mäso miešané', 'mlete maso miesane', 'Mäso a údeniny'),
  ('Slanina', 'slanina', 'Mäso a údeniny'),
  ('Slanina údená', 'slanina udena', 'Mäso a údeniny'),
  ('Šunka', 'sunka', 'Mäso a údeniny'),
  ('Klobása', 'klobasa', 'Mäso a údeniny'),
  ('Saláma', 'salama', 'Mäso a údeniny'),
  ('Párky', 'parky', 'Mäso a údeniny'),
  ('Špekáčiky', 'spekaciky', 'Mäso a údeniny'),
  ('Údené mäso', 'udene maso', 'Mäso a údeniny'),
  ('Údené koleno', 'udene koleno', 'Mäso a údeniny'),
  ('Oškvarky', 'oskvarky', 'Mäso a údeniny');

-- Ryby a morské plody
INSERT IGNORE INTO suroviny (nazov, kluc, kategoria) VALUES
  ('Ryba', 'ryba', 'Ryby a morské plody'),
  ('Tuniak', 'tuniak', 'Ryby a morské plody'),
  ('Losos', 'losos', 'Ryby a morské plody'),
  ('Losos údený', 'losos udeny', 'Ryby a morské plody'),
  ('Treska', 'treska', 'Ryby a morské plody'),
  ('Pstruh', 'pstruh', 'Ryby a morské plody'),
  ('Kapor', 'kapor', 'Ryby a morské plody'),
  ('Zubáč', 'zubac', 'Ryby a morské plody'),
  ('Makrela', 'makrela', 'Ryby a morské plody'),
  ('Tuniak v konzerve', 'tuniak v konzerve', 'Ryby a morské plody'),
  ('Sardinky', 'sardinky', 'Ryby a morské plody'),
  ('Ančovičky', 'ancovicky', 'Ryby a morské plody'),
  ('Rybie filé', 'rybie file', 'Ryby a morské plody'),
  ('Krevety', 'krevety', 'Ryby a morské plody'),
  ('Mušle', 'musle', 'Ryby a morské plody'),
  ('Kalamáre', 'kalamare', 'Ryby a morské plody');

-- Zelenina
INSERT IGNORE INTO suroviny (nazov, kluc, kategoria) VALUES
  ('Paprika', 'paprika', 'Zelenina'),
  ('Kapusta', 'kapusta', 'Zelenina'),
  ('Šalát', 'salat', 'Zelenina'),
  ('Tekvica', 'tekvica', 'Zelenina'),
  ('Huby', 'huby', 'Zelenina'),
  ('Uhorka', 'uhorka', 'Zelenina'),
  ('Olivy', 'olivy', 'Zelenina'),
  ('Koreňová zelenina', 'korenova zelenina', 'Zelenina'),
  ('Mrazená zelenina', 'mrazena zelenina', 'Zelenina'),
  ('Hranolky', 'hranolky', 'Zelenina'),
  ('Zemiaky', 'zemiaky', 'Zelenina'),
  ('Batáty', 'bataty', 'Zelenina'),
  ('Cibuľa', 'cibula', 'Zelenina'),
  ('Cibuľa červená', 'cibula cervena', 'Zelenina'),
  ('Cibuľa jarná', 'cibula jarna', 'Zelenina'),
  ('Šalotka', 'salotka', 'Zelenina'),
  ('Cesnak', 'cesnak', 'Zelenina'),
  ('Pór', 'por', 'Zelenina'),
  ('Mrkva', 'mrkva', 'Zelenina'),
  ('Petržlen koreň', 'petrzlen koren', 'Zelenina'),
  ('Zeler buľvový', 'zeler bulvovy', 'Zelenina'),
  ('Zeler stopkový', 'zeler stopkovy', 'Zelenina'),
  ('Paštrnák', 'pastrnak', 'Zelenina'),
  ('Kaleráb', 'kalerab', 'Zelenina'),
  ('Reďkovka', 'redkovka', 'Zelenina'),
  ('Cvikla', 'cvikla', 'Zelenina'),
  ('Paradajky', 'paradajky', 'Zelenina'),
  ('Paradajky cherry', 'paradajky cherry', 'Zelenina'),
  ('Paprika červená', 'paprika cervena', 'Zelenina'),
  ('Paprika žltá', 'paprika zlta', 'Zelenina'),
  ('Paprika zelená', 'paprika zelena', 'Zelenina'),
  ('Čili paprička', 'cili papricka', 'Zelenina'),
  ('Uhorka šalátová', 'uhorka salatova', 'Zelenina'),
  ('Uhorky kyslé', 'uhorky kysle', 'Zelenina'),
  ('Cuketa', 'cuketa', 'Zelenina'),
  ('Baklažán', 'baklazan', 'Zelenina'),
  ('Tekvica hokkaido', 'tekvica hokkaido', 'Zelenina'),
  ('Tekvica maslová', 'tekvica maslova', 'Zelenina'),
  ('Kapusta biela', 'kapusta biela', 'Zelenina'),
  ('Kapusta červená', 'kapusta cervena', 'Zelenina'),
  ('Kapusta kyslá', 'kapusta kysla', 'Zelenina'),
  ('Kapusta čínska', 'kapusta cinska', 'Zelenina'),
  ('Kel hlávkový', 'kel hlavkovy', 'Zelenina'),
  ('Kel kučeravý', 'kel kuceravy', 'Zelenina'),
  ('Ružičkový kel', 'ruzickovy kel', 'Zelenina'),
  ('Brokolica', 'brokolica', 'Zelenina'),
  ('Karfiol', 'karfiol', 'Zelenina'),
  ('Špenát', 'spenat', 'Zelenina'),
  ('Šalát hlávkový', 'salat hlavkovy', 'Zelenina'),
  ('Šalát ľadový', 'salat ladovy', 'Zelenina'),
  ('Rukola', 'rukola', 'Zelenina'),
  ('Poľníček', 'polnicek', 'Zelenina'),
  ('Špargľa', 'spargla', 'Zelenina'),
  ('Hrášok', 'hrasok', 'Zelenina'),
  ('Kukurica', 'kukurica', 'Zelenina'),
  ('Fazuľové struky', 'fazulove struky', 'Zelenina'),
  ('Šampiňóny', 'sampinony', 'Zelenina'),
  ('Hliva ustricová', 'hliva ustricova', 'Zelenina'),
  ('Hríby sušené', 'hriby susene', 'Zelenina'),
  ('Lesné huby', 'lesne huby', 'Zelenina'),
  ('Avokádo', 'avokado', 'Zelenina'),
  ('Olivy zelené', 'olivy zelene', 'Zelenina'),
  ('Olivy čierne', 'olivy cierne', 'Zelenina'),
  ('Zázvor', 'zazvor', 'Zelenina'),
  ('Chren', 'chren', 'Zelenina');

-- Ovocie
INSERT IGNORE INTO suroviny (nazov, kluc, kategoria) VALUES
  ('Lesné ovocie', 'lesne ovocie', 'Ovocie'),
  ('Jablká', 'jablka', 'Ovocie'),
  ('Hrušky', 'hrusky', 'Ovocie'),
  ('Banány', 'banany', 'Ovocie'),
  ('Citrón', 'citron', 'Ovocie'),
  ('Limetka', 'limetka', 'Ovocie'),
  ('Pomaranč', 'pomaranc', 'Ovocie'),
  ('Mandarínky', 'mandarinky', 'Ovocie'),
  ('Grapefruit', 'grapefruit', 'Ovocie'),
  ('Jahody', 'jahody', 'Ovocie'),
  ('Maliny', 'maliny', 'Ovocie'),
  ('Čučoriedky', 'cucoriedky', 'Ovocie'),
  ('Černice', 'cernice', 'Ovocie'),
  ('Ríbezle', 'ribezle', 'Ovocie'),
  ('Brusnice', 'brusnice', 'Ovocie'),
  ('Čerešne', 'ceresne', 'Ovocie'),
  ('Višne', 'visne', 'Ovocie'),
  ('Marhule', 'marhule', 'Ovocie'),
  ('Broskyne', 'broskyne', 'Ovocie'),
  ('Nektárinky', 'nektarinky', 'Ovocie'),
  ('Slivky', 'slivky', 'Ovocie'),
  ('Hrozno', 'hrozno', 'Ovocie'),
  ('Kiwi', 'kiwi', 'Ovocie'),
  ('Ananás', 'ananas', 'Ovocie'),
  ('Mango', 'mango', 'Ovocie'),
  ('Melón červený', 'melon cerveny', 'Ovocie'),
  ('Melón žltý', 'melon zlty', 'Ovocie'),
  ('Granátové jablko', 'granatove jablko', 'Ovocie'),
  ('Figy', 'figy', 'Ovocie'),
  ('Datle', 'datle', 'Ovocie'),
  ('Sušené slivky', 'susene slivky', 'Ovocie'),
  ('Sušené marhule', 'susene marhule', 'Ovocie'),
  ('Sušené brusnice', 'susene brusnice', 'Ovocie'),
  ('Rebarbora', 'rebarbora', 'Ovocie');

-- Strukoviny
INSERT IGNORE INTO suroviny (nazov, kluc, kategoria) VALUES
  ('Fazuľa', 'fazula', 'Strukoviny'),
  ('Šošovica', 'sosovica', 'Strukoviny'),
  ('Hrach', 'hrach', 'Strukoviny'),
  ('Fazuľa biela', 'fazula biela', 'Strukoviny'),
  ('Fazuľa červená', 'fazula cervena', 'Strukoviny'),
  ('Fazuľa farebná', 'fazula farebna', 'Strukoviny'),
  ('Šošovica hnedá', 'sosovica hneda', 'Strukoviny'),
  ('Šošovica červená', 'sosovica cervena', 'Strukoviny'),
  ('Hrach žltý', 'hrach zlty', 'Strukoviny'),
  ('Cícer', 'cicer', 'Strukoviny'),
  ('Sója', 'soja', 'Strukoviny'),
  ('Tofu', 'tofu', 'Strukoviny');

-- Orechy a semená
INSERT IGNORE INTO suroviny (nazov, kluc, kategoria) VALUES
  ('Orechy', 'orechy', 'Orechy a semená'),
  ('Vlašské orechy', 'vlasske orechy', 'Orechy a semená'),
  ('Lieskové orechy', 'lieskove orechy', 'Orechy a semená'),
  ('Mandle', 'mandle', 'Orechy a semená'),
  ('Mandľové lupienky', 'mandlove lupienky', 'Orechy a semená'),
  ('Kešu orechy', 'kesu orechy', 'Orechy a semená'),
  ('Arašidy', 'arasidy', 'Orechy a semená'),
  ('Pistácie', 'pistacie', 'Orechy a semená'),
  ('Píniové oriešky', 'piniove oriesky', 'Orechy a semená'),
  ('Pekanové orechy', 'pekanove orechy', 'Orechy a semená'),
  ('Gaštany', 'gastany', 'Orechy a semená'),
  ('Slnečnicové semienka', 'slnecnicove semienka', 'Orechy a semená'),
  ('Tekvicové semienka', 'tekvicove semienka', 'Orechy a semená'),
  ('Sezamové semienka', 'sezamove semienka', 'Orechy a semená'),
  ('Ľanové semienka', 'lanove semienka', 'Orechy a semená'),
  ('Chia semienka', 'chia semienka', 'Orechy a semená'),
  ('Mak', 'mak', 'Orechy a semená'),
  ('Arašidové maslo', 'arasidove maslo', 'Orechy a semená');

-- Bylinky a koreniny
INSERT IGNORE INTO suroviny (nazov, kluc, kategoria) VALUES
  ('Čierne korenie', 'cierne korenie', 'Bylinky a koreniny'),
  ('Rasca', 'rasca', 'Bylinky a koreniny'),
  ('Škorica', 'skorica', 'Bylinky a koreniny'),
  ('Paprika mletá', 'paprika mleta', 'Bylinky a koreniny'),
  ('Čili', 'cili', 'Bylinky a koreniny'),
  ('Bujón', 'bujon', 'Bylinky a koreniny'),
  ('Korenie na kurča', 'korenie na kurca', 'Bylinky a koreniny'),
  ('Gyros korenie', 'gyros korenie', 'Bylinky a koreniny'),
  ('Soľ', 'sol', 'Bylinky a koreniny'),
  ('Čierne korenie mleté', 'cierne korenie mlete', 'Bylinky a koreniny'),
  ('Čierne korenie celé', 'cierne korenie cele', 'Bylinky a koreniny'),
  ('Nové korenie', 'nove korenie', 'Bylinky a koreniny'),
  ('Bobkový list', 'bobkovy list', 'Bylinky a koreniny'),
  ('Paprika mletá sladká', 'paprika mleta sladka', 'Bylinky a koreniny'),
  ('Paprika mletá štipľavá', 'paprika mleta stiplava', 'Bylinky a koreniny'),
  ('Paprika mletá údená', 'paprika mleta udena', 'Bylinky a koreniny'),
  ('Rasca celá', 'rasca cela', 'Bylinky a koreniny'),
  ('Rasca mletá', 'rasca mleta', 'Bylinky a koreniny'),
  ('Majoránka', 'majoranka', 'Bylinky a koreniny'),
  ('Oregano', 'oregano', 'Bylinky a koreniny'),
  ('Bazalka', 'bazalka', 'Bylinky a koreniny'),
  ('Tymián', 'tymian', 'Bylinky a koreniny'),
  ('Rozmarín', 'rozmarin', 'Bylinky a koreniny'),
  ('Šalvia', 'salvia', 'Bylinky a koreniny'),
  ('Petržlenová vňať', 'petrzlenova vnat', 'Bylinky a koreniny'),
  ('Pažítka', 'pazitka', 'Bylinky a koreniny'),
  ('Kôpor', 'kopor', 'Bylinky a koreniny'),
  ('Koriander', 'koriander', 'Bylinky a koreniny'),
  ('Mäta', 'mata', 'Bylinky a koreniny'),
  ('Ligurček', 'ligurcek', 'Bylinky a koreniny'),
  ('Estragón', 'estragon', 'Bylinky a koreniny'),
  ('Škorica mletá', 'skorica mleta', 'Bylinky a koreniny'),
  ('Škorica celá', 'skorica cela', 'Bylinky a koreniny'),
  ('Klinčeky', 'klinceky', 'Bylinky a koreniny'),
  ('Muškátový oriešok', 'muskatovy oriesok', 'Bylinky a koreniny'),
  ('Badián', 'badian', 'Bylinky a koreniny'),
  ('Kardamóm', 'kardamom', 'Bylinky a koreniny'),
  ('Kurkuma', 'kurkuma', 'Bylinky a koreniny'),
  ('Kari korenie', 'kari korenie', 'Bylinky a koreniny'),
  ('Rímska rasca', 'rimska rasca', 'Bylinky a koreniny'),
  ('Čili mleté', 'cili mlete', 'Bylinky a koreniny'),
  ('Cesnak sušený', 'cesnak suseny', 'Bylinky a koreniny'),
  ('Zázvor mletý', 'zazvor mlety', 'Bylinky a koreniny'),
  ('Provensálske bylinky', 'provensalske bylinky', 'Bylinky a koreniny'),
  ('Grilovacie korenie', 'grilovacie korenie', 'Bylinky a koreniny'),
  ('Perníkové korenie', 'pernikove korenie', 'Bylinky a koreniny'),
  ('Vegeta', 'vegeta', 'Bylinky a koreniny'),
  ('Bujón zeleninový', 'bujon zeleninovy', 'Bylinky a koreniny'),
  ('Bujón kurací', 'bujon kuraci', 'Bylinky a koreniny'),
  ('Bujón hovädzí', 'bujon hovadzi', 'Bylinky a koreniny');

-- Oleje, tuky a octy
INSERT IGNORE INTO suroviny (nazov, kluc, kategoria) VALUES
  ('Olej', 'olej', 'Oleje, tuky a octy'),
  ('Ocot', 'ocot', 'Oleje, tuky a octy'),
  ('Masť', 'mast', 'Oleje, tuky a octy'),
  ('Olej slnečnicový', 'olej slnecnicovy', 'Oleje, tuky a octy'),
  ('Olej repkový', 'olej repkovy', 'Oleje, tuky a octy'),
  ('Olej olivový', 'olej olivovy', 'Oleje, tuky a octy'),
  ('Olej kokosový', 'olej kokosovy', 'Oleje, tuky a octy'),
  ('Olej sezamový', 'olej sezamovy', 'Oleje, tuky a octy'),
  ('Bravčová masť', 'bravcova mast', 'Oleje, tuky a octy'),
  ('Kačacia masť', 'kacacia mast', 'Oleje, tuky a octy'),
  ('Margarín', 'margarin', 'Oleje, tuky a octy'),
  ('Maslo prepustené', 'maslo prepustene', 'Oleje, tuky a octy'),
  ('Ocot kvasný', 'ocot kvasny', 'Oleje, tuky a octy'),
  ('Ocot jablčný', 'ocot jablcny', 'Oleje, tuky a octy'),
  ('Ocot vínny', 'ocot vinny', 'Oleje, tuky a octy'),
  ('Ocot balzamikový', 'ocot balzamikovy', 'Oleje, tuky a octy');

-- Omáčky a dochucovadlá
INSERT IGNORE INTO suroviny (nazov, kluc, kategoria) VALUES
  ('Horčica', 'horcica', 'Omáčky a dochucovadlá'),
  ('Horčica plnotučná', 'horcica plnotucna', 'Omáčky a dochucovadlá'),
  ('Horčica dijonská', 'horcica dijonska', 'Omáčky a dochucovadlá'),
  ('Kečup', 'kecup', 'Omáčky a dochucovadlá'),
  ('Majonéza', 'majoneza', 'Omáčky a dochucovadlá'),
  ('Tatárska omáčka', 'tatarska omacka', 'Omáčky a dochucovadlá'),
  ('Paradajkový pretlak', 'paradajkovy pretlak', 'Omáčky a dochucovadlá'),
  ('Paradajky krájané v konzerve', 'paradajky krajane v konzerve', 'Omáčky a dochucovadlá'),
  ('Paradajková passata', 'paradajkova passata', 'Omáčky a dochucovadlá'),
  ('Sójová omáčka', 'sojova omacka', 'Omáčky a dochucovadlá'),
  ('Worcesterová omáčka', 'worcesterova omacka', 'Omáčky a dochucovadlá'),
  ('Rybacia omáčka', 'rybacia omacka', 'Omáčky a dochucovadlá'),
  ('Čili omáčka', 'cili omacka', 'Omáčky a dochucovadlá'),
  ('Tabasco', 'tabasco', 'Omáčky a dochucovadlá'),
  ('Pesto bazalkové', 'pesto bazalkove', 'Omáčky a dochucovadlá'),
  ('Kapary', 'kapary', 'Omáčky a dochucovadlá'),
  ('Citrónová šťava', 'citronova stava', 'Omáčky a dochucovadlá'),
  ('Kokosové mlieko', 'kokosove mlieko', 'Omáčky a dochucovadlá'),
  ('Sterilizovaná kukurica', 'sterilizovana kukurica', 'Omáčky a dochucovadlá'),
  ('Sterilizovaný hrášok', 'sterilizovany hrasok', 'Omáčky a dochucovadlá'),
  ('Fazuľa v konzerve', 'fazula v konzerve', 'Omáčky a dochucovadlá'),
  ('Cícer v konzerve', 'cicer v konzerve', 'Omáčky a dochucovadlá'),
  ('Kompót broskyňový', 'kompot broskynovy', 'Omáčky a dochucovadlá'),
  ('Kompót ananásový', 'kompot ananasovy', 'Omáčky a dochucovadlá');

-- Nápoje a alkohol
INSERT IGNORE INTO suroviny (nazov, kluc, kategoria) VALUES
  ('Vývar', 'vyvar', 'Nápoje a alkohol'),
  ('Víno', 'vino', 'Nápoje a alkohol'),
  ('Voda', 'voda', 'Nápoje a alkohol'),
  ('Voda sýtená', 'voda sytena', 'Nápoje a alkohol'),
  ('Vývar zeleninový', 'vyvar zeleninovy', 'Nápoje a alkohol'),
  ('Vývar kurací', 'vyvar kuraci', 'Nápoje a alkohol'),
  ('Vývar hovädzí', 'vyvar hovadzi', 'Nápoje a alkohol'),
  ('Víno biele', 'vino biele', 'Nápoje a alkohol'),
  ('Víno červené', 'vino cervene', 'Nápoje a alkohol'),
  ('Pivo', 'pivo', 'Nápoje a alkohol'),
  ('Rum', 'rum', 'Nápoje a alkohol'),
  ('Káva', 'kava', 'Nápoje a alkohol'),
  ('Čaj čierny', 'caj cierny', 'Nápoje a alkohol'),
  ('Džús pomarančový', 'dzus pomarancovy', 'Nápoje a alkohol');

