-- ======================================================================
-- Evidencia receptov — aktualizácia databázy na verziu 3.0.
--
-- Novinka: výživové hodnoty. Ku každej surovine sa uloží všeobecný odhad
-- na 100 g (kcal, bielkoviny, sacharidy, z toho cukry, tuky, z toho nasýtené,
-- vláknina, soľ). Hodnoty doplní AI samo (po uložení receptu, cronom alebo
-- tlačidlom v Správe → Suroviny) a appka z nich dopočíta recept na porciu.
--
-- Spusti RAZ nad existujúcou databázou:
--   phpMyAdmin → vyber databázu → Import → tento súbor,
--   alebo:  mysql -u POUZIVATEL -p NAZOV_DATABAZY < sql/migracia-3.0.sql
--
-- Dá sa spustiť aj opakovane, nič sa nezdvojí. Kým migrácia nie je spustená,
-- appka funguje ďalej, len bez výživových hodnôt. Pri novej inštalácii netreba,
-- tabuľka je už v sql/instalacia.sql.
-- ======================================================================

SET NAMES utf8mb4;

-- Výživové hodnoty surovín (3.0): všeobecný odhad na 100 g (doplní AI, opraviť sa dá v Správe → Suroviny).
-- Z nich appka dopočíta recept na porciu. hustota = g na 1 ml (prepočet ml, l, lyžíc, šálky),
-- gramy_kus = 1 ks / plátok / strúčik, gramy_balenie = 1 balenie; NULL = nevieme, takú dávku nezaráta.
CREATE TABLE IF NOT EXISTS `suroviny_vyziva` (
  `surovina_id` int(10) UNSIGNED NOT NULL,
  `kcal` decimal(6,1) NOT NULL DEFAULT 0,
  `bielkoviny` decimal(5,1) NOT NULL DEFAULT 0,
  `sacharidy` decimal(5,1) NOT NULL DEFAULT 0,
  `cukry` decimal(5,1) NOT NULL DEFAULT 0,
  `tuky` decimal(5,1) NOT NULL DEFAULT 0,
  `nasytene` decimal(5,1) NOT NULL DEFAULT 0,
  `vlaknina` decimal(5,1) NOT NULL DEFAULT 0,
  `sol` decimal(5,2) NOT NULL DEFAULT 0,
  `hustota` decimal(4,2) NOT NULL DEFAULT 1.00,
  `gramy_kus` decimal(7,1) DEFAULT NULL,
  `gramy_balenie` decimal(7,1) DEFAULT NULL,
  `zdroj` varchar(10) NOT NULL DEFAULT 'ai',       -- ai | rucne (ručne opravené sa AI neprepíše)
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`surovina_id`),
  CONSTRAINT `fk_sv_surovina` FOREIGN KEY (`surovina_id`) REFERENCES `suroviny` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
