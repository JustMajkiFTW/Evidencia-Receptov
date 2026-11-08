-- ======================================================================
-- Evidencia receptov — aktualizácia databázy na verziu 1.8.
--
-- Novinka: recept sa dá vyplniť z fotky cez AI. Táto tabuľka počíta, koľko
-- fotiek kto v daný deň poslal na rozpoznanie (denný limit, AI_DENNY_LIMIT
-- v includes/.env), a pamätá si chyby AI služieb.
--
-- Spusti RAZ nad existujúcou databázou:
--   phpMyAdmin → vyber databázu → Import → tento súbor,
--   alebo:  mysql -u POUZIVATEL -p NAZOV_DATABAZY < sql/migracia-1.8.sql
--
-- Dá sa spustiť aj opakovane, nič sa nezdvojí ani nepokazí. Recepty sa nemenia.
-- Kým migrácia nie je spustená, appka beží a limit počíta len v rámci
-- prihlásenia (session).
-- Pri novej inštalácii netreba, tabuľka je už v sql/instalacia.sql.
-- ======================================================================

SET NAMES utf8mb4;

-- Rozpoznania receptu z fotky: jeden riadok = jedna poslaná fotka.
CREATE TABLE IF NOT EXISTS `ai_rozpoznania` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,                   -- users.id, kto fotku poslal
  `poskytovatel` varchar(20) DEFAULT NULL,      -- gemini | claude | openai, NULL = žiadna služba neuspela
  `uspech` tinyint(1) NOT NULL DEFAULT 0,       -- do denného limitu sa počítajú len úspešné
  `chyba` varchar(255) DEFAULT NULL,            -- prečo zlyhali služby skúšané predtým
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_user_den` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
