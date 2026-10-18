-- ======================================================================
-- Evidencia receptov — aktualizácia databázy z verzie 1.0 na 1.1.
--
-- Novinka: galéria fotiek. Ku každému vareniu receptu môže pribudnúť
-- fotka a staršie fotky ostávajú, každá so svojím dátumom.
--
-- Spusti RAZ nad existujúcou databázou:
--   phpMyAdmin → vyber databázu → Import → tento súbor,
--   alebo:  mysql -u POUZIVATEL -p NAZOV_DATABAZY < sql/migracia-1.1.sql
--
-- Dá sa spustiť aj opakovane, nič sa nezdvojí ani nepokazí.
-- Pri novej inštalácii netreba, tabuľka je už v sql/instalacia.sql.
-- ======================================================================

SET NAMES utf8mb4;

-- Fotky receptu: ku každému vareniu môže pribudnúť fotka a staršie ostávajú.
-- Na karte receptu je najnovšia; jej cesta je skopírovaná aj v recipes.image_path.
CREATE TABLE IF NOT EXISTS `recipe_photos` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipe_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,           -- napr. uploads/recipes/recipe_xxx.jpg
  `photo_date` date NOT NULL,                   -- kedy sa jedlo varilo (dátum pod fotkou)
  `uploaded_by` int(11) DEFAULT NULL,           -- users.id, kto fotku pridal
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rp_recipe_date` (`recipe_id`,`photo_date`),
  CONSTRAINT `fk_rp_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Doterajšia fotka každého receptu sa stane prvou fotkou v jeho galérii.
--
-- Dátum fotky: appka pomenúva fotky podľa času nahratia (recipe_ + 8 znakov
-- = sekundy v šestnástkovej sústave, napr. recipe_6a52553916d832....jpg), takže
-- sa dá zistiť presne, kedy bola nahratá. Ak názov tomu nezodpovedá, použije
-- sa deň pridania receptu a ak ani ten nie je známy, dnešný deň.
--
-- Kto ju pridal: ten, kto v rovnakej chvíli (±2 minúty) recept pridal alebo
-- upravil — fotka sa vždy nahrávala spolu s uložením receptu.
INSERT INTO `recipe_photos` (`recipe_id`, `image_path`, `photo_date`, `uploaded_by`, `created_at`)
SELECT t.id,
       t.image_path,
       DATE(t.nahrana),
       (SELECT l.user_id
          FROM `recipe_logs` l
         WHERE l.recipe_id = t.id
           AND l.action IN ('created', 'updated')
           AND l.created_at BETWEEN t.nahrana - INTERVAL 2 MINUTE AND t.nahrana + INTERVAL 2 MINUTE
         ORDER BY ABS(TIMESTAMPDIFF(SECOND, l.created_at, t.nahrana))
         LIMIT 1),
       t.nahrana
FROM (
    SELECT r.id,
           r.image_path,
           CASE
               WHEN SUBSTRING_INDEX(r.image_path, '/', -1) REGEXP '^recipe_[0-9a-f]{13}'
                   THEN FROM_UNIXTIME(CONV(SUBSTRING(SUBSTRING_INDEX(r.image_path, '/', -1), 8, 8), 16, 10))
               ELSE COALESCE(
                   (SELECT MIN(l.created_at) FROM `recipe_logs` l WHERE l.recipe_id = r.id AND l.action = 'created'),
                   NOW())
           END AS nahrana
      FROM `recipes` r
     WHERE r.image_path IS NOT NULL
       AND r.image_path <> ''
       AND NOT EXISTS (
           SELECT 1 FROM `recipe_photos` p WHERE p.recipe_id = r.id AND p.image_path = r.image_path
       )
) t;
