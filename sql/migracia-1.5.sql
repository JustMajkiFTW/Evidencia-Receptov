-- ======================================================================
-- Evidencia receptov — aktualizácia databázy na verziu 1.5.
--
-- Novinka: kategórie receptov sa dajú spravovať v appke (Správa → Kategórie
-- receptov): pridať, premenovať, zmeniť poradie a zmazať nepoužívanú.
--
-- Spusti RAZ nad existujúcou databázou:
--   phpMyAdmin → vyber databázu → Import → tento súbor,
--   alebo:  mysql -u POUZIVATEL -p NAZOV_DATABAZY < sql/migracia-1.5.sql
--
-- Dá sa spustiť aj opakovane, nič sa nezdvojí ani nepokazí. Recepty sa
-- nemenia. Pozor: kategóriu zo základného zoznamu, ktorú admin medzitým
-- zmazal, opakované spustenie vráti späť.
-- Pri novej inštalácii netreba, tabuľka je už v sql/instalacia.sql.
-- ======================================================================

SET NAMES utf8mb4;

-- Kategórie receptov. `kluc` je hodnota uložená pri recepte (recipes.category),
-- `nazov` sa ukazuje na obrazovke. Premenovanie mení len názov.
CREATE TABLE IF NOT EXISTS `kategorie_receptov` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `kluc` varchar(50) NOT NULL,
  `nazov` varchar(50) NOT NULL,
  `poradie` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_kategoria_kluc` (`kluc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Doterajšie kategórie s rovnakými kľúčmi, aké majú uložené recepty.
INSERT IGNORE INTO `kategorie_receptov` (`kluc`, `nazov`, `poradie`) VALUES
  ('Polievky', 'Polievky', 1),
  ('Hlavné jedlá', 'Hlavné jedlá', 2),
  ('Bezmäsité', 'Bezmäsité jedlá', 3),
  ('Fit', 'Nízko kal. / Fit', 4),
  ('Dezerty', 'Dezerty a pečenie', 5),
  ('Rýchlovky', 'Rýchlovky / Večere', 6),
  ('Raňajky', 'Raňajky', 7);
