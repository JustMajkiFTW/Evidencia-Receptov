-- ======================================================================
-- Evidencia receptov — aktualizácia databázy na verziu 2.5.
--
-- Novinka: hodnotenie objednaného jedla hviezdičkami (ako pri receptoch).
-- Každý používateľ dá objednávke jedno hodnotenie 1 až 5, appka ukazuje
-- priemer a počet hodnotení. Recepty ani objednávky sa nemenia.
--
-- Spusti RAZ nad existujúcou databázou:
--   phpMyAdmin → vyber databázu → Import → tento súbor,
--   alebo:  mysql -u POUZIVATEL -p NAZOV_DATABAZY < sql/migracia-2.5.sql
--
-- Potrebuje tabuľku objednane_jedla z migracia-2.2.sql (ak si ju preskočil,
-- naimportuj najprv tú). Dá sa spustiť aj opakovane, nič sa nezdvojí.
-- Kým migrácia nie je spustená, Objednané jedlo funguje ďalej, len bez hodnotenia.
-- Pri novej inštalácii netreba, tabuľka je už v sql/instalacia.sql.
-- ======================================================================

SET NAMES utf8mb4;

-- Hodnotenie objednávky: jeden používateľ = jeden hlas na objednávku.
CREATE TABLE IF NOT EXISTS `objednavky_hodnotenia` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `objednavka_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `hodnotenie` tinyint(3) UNSIGNED NOT NULL,    -- 1 až 5
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_objednavka_user` (`objednavka_id`,`user_id`),
  KEY `fk_oh_user` (`user_id`),
  CONSTRAINT `fk_oh_objednavka` FOREIGN KEY (`objednavka_id`) REFERENCES `objednane_jedla` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oh_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
