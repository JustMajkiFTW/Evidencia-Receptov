-- ======================================================================
-- Evidencia receptov — aktualizácia databázy na verziu 2.6.
--
-- Novinka: pripojenie k AI asistentovi (Claude, neskôr aj iné) cez MCP.
-- Každý používateľ si v appke (Viac / Nastavenia → Pripojiť k Claude) vytvorí
-- vlastnú súkromnú adresu, ktorú vloží do asistenta. Asistent potom na jeho
-- pokyn hľadá recepty, pridáva ich, zapisuje varenie a pridáva suroviny do
-- nákupu — pod menom toho, komu adresa patrí. V databáze je z adresy uložený
-- len odtlačok (SHA-256), samotnú adresu appka ukáže iba raz.
--
-- Spusti RAZ nad existujúcou databázou:
--   phpMyAdmin → vyber databázu → Import → tento súbor,
--   alebo:  mysql -u POUZIVATEL -p NAZOV_DATABAZY < sql/migracia-2.6.sql
--
-- Dá sa spustiť aj opakovane, nič sa nezdvojí. Kým migrácia nie je spustená,
-- appka funguje ďalej, len bez pripojenia k AI asistentovi.
-- Pri novej inštalácii netreba, tabuľka je už v sql/instalacia.sql.
-- ======================================================================

SET NAMES utf8mb4;

-- Pripojenia k AI asistentom (MCP): jeden riadok = jedna súkromná adresa.
CREATE TABLE IF NOT EXISTS `ai_pripojenia` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `nazov` varchar(50) NOT NULL DEFAULT 'Claude',  -- pomenovanie od používateľa (ukazuje sa aj v histórii)
  `kluc_hash` char(64) NOT NULL,                 -- SHA-256 odtlačok tajného kľúča z adresy
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ai_pripojenia_hash` (`kluc_hash`),
  KEY `idx_ai_pripojenia_user` (`user_id`),
  CONSTRAINT `fk_ai_pripojenia_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
