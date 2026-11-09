-- ======================================================================
-- Evidencia receptov — aktualizácia databázy na verziu 2.2.
--
-- Novinka: Objednané jedlo. Vlastná tabuľka na jedlo objednané cez Wolt,
-- Bolt Food, foodora a podobne — kedy, odkiaľ, z akej reštaurácie a čo sme
-- mali. Recepty ani ostatné tabuľky sa nemenia.
--
-- Spusti RAZ nad existujúcou databázou:
--   phpMyAdmin → vyber databázu → Import → tento súbor,
--   alebo:  mysql -u POUZIVATEL -p NAZOV_DATABAZY < sql/migracia-2.2.sql
--
-- Dá sa spustiť aj opakovane, nič sa nezdvojí ani nepokazí.
-- Kým migrácia nie je spustená, appka beží ďalej, len bez Objednaného jedla.
-- Pri novej inštalácii netreba, tabuľka je už v sql/instalacia.sql.
-- ======================================================================

SET NAMES utf8mb4;

-- Objednané jedlo: jeden riadok = jedna objednávka.
CREATE TABLE IF NOT EXISTS `objednane_jedla` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `datum` date NOT NULL,                        -- kedy sme jedlo mali
  `sluzba` varchar(20) NOT NULL DEFAULT 'ine',  -- wolt | bolt | foodora | ine
  `restauracia` varchar(150) DEFAULT NULL,
  `jedlo` text DEFAULT NULL,                    -- čo sme mali (voľný text)
  `cena` decimal(8,2) DEFAULT NULL,             -- spolu v eurách, nepovinné
  `pridal` int(11) DEFAULT NULL,                -- users.id, kto objednávku zapísal
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_oj_datum` (`datum`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
