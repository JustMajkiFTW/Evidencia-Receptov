-- ======================================================================
-- Evidencia receptov — aktualizácia databázy na verziu 2.4.
--
-- Novinka: fotka pri objednanom jedle. Do tabuľky objednane_jedla pribudne
-- stĺpec s cestou k fotke (súbory sú v priečinku uploads/objfood/, ten si
-- appka pri prvej fotke vytvorí sama). Recepty sa nemenia.
--
-- Spusti RAZ nad existujúcou databázou:
--   phpMyAdmin → vyber databázu → Import → tento súbor,
--   alebo:  mysql -u POUZIVATEL -p NAZOV_DATABAZY < sql/migracia-2.4.sql
--
-- Potrebuje tabuľku objednane_jedla z migracia-2.2.sql (ak si ju preskočil,
-- naimportuj najprv tú). Dá sa spustiť aj opakovane, nič sa nezdvojí.
-- Kým migrácia nie je spustená, Objednané jedlo funguje ďalej, len bez fotiek.
-- Pri novej inštalácii netreba, stĺpec je už v sql/instalacia.sql.
-- ======================================================================

SET NAMES utf8mb4;

ALTER TABLE `objednane_jedla`
  ADD COLUMN IF NOT EXISTS `foto` varchar(255) DEFAULT NULL AFTER `cena`;  -- napr. uploads/objfood/objfood_….jpg
