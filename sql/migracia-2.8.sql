-- ======================================================================
-- Evidencia receptov — aktualizácia databázy na verziu 2.8.
--
-- Novinka: kuchárky v PDF. PDF z priečinka kucharky/ na serveri (nahrané cez
-- FTP alebo v appke: Správa → Kuchárky) appka prečíta po stranách a text
-- uloží sem. Hlasový asistent (aj Claude cez MCP) v nich potom hľadá recepty
-- a odporúča, čo uvariť, aj s názvom knihy a stranou.
--
-- Spusti RAZ nad existujúcou databázou:
--   phpMyAdmin → vyber databázu → Import → tento súbor,
--   alebo:  mysql -u POUZIVATEL -p NAZOV_DATABAZY < sql/migracia-2.8.sql
--
-- Dá sa spustiť aj opakovane, nič sa nezdvojí. Kým migrácia nie je spustená,
-- appka funguje ďalej, len bez kuchárok. Pri novej inštalácii netreba,
-- tabuľky sú už v sql/instalacia.sql. Potrebuje MariaDB 10.0.5+ (FULLTEXT v InnoDB).
-- ======================================================================

SET NAMES utf8mb4;

-- Kuchárky: jeden riadok = jeden PDF súbor v priečinku kucharky/.
CREATE TABLE IF NOT EXISTS `kucharky` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `subor` varchar(255) NOT NULL,                -- názov súboru v kucharky/ (bez cesty)
  `nazov` varchar(255) NOT NULL,                -- názov knihy (z PDF alebo z názvu súboru)
  `velkost` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `zmenene` int(10) UNSIGNED NOT NULL DEFAULT 0, -- čas zmeny súboru pri spracovaní (zmenený súbor sa spracuje znova)
  `pocet_stran` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `stav` varchar(20) NOT NULL DEFAULT 'ok',      -- ok | bez_textu (skeny) | chyba
  `chyba` varchar(255) DEFAULT NULL,
  `spracovane_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kucharky_subor` (`subor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Text kuchárok po stranách. `kluc` = text bez diakritiky a malými písmenami, hľadá sa v ňom (FULLTEXT).
CREATE TABLE IF NOT EXISTS `kucharky_strany` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `kucharka_id` int(10) UNSIGNED NOT NULL,
  `strana` int(10) UNSIGNED NOT NULL,
  `text` mediumtext NOT NULL,
  `kluc` mediumtext NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ks_kucharka_strana` (`kucharka_id`, `strana`),
  FULLTEXT KEY `ft_ks_kluc` (`kluc`),
  CONSTRAINT `fk_ks_kucharka` FOREIGN KEY (`kucharka_id`) REFERENCES `kucharky` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
