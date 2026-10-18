<?php
/**
 * db.php — pomocník testov na prácu s databázou (namiesto klienta mysql, ktorý
 * nemusí byť všade nainštalovaný alebo sa môže správať inak).
 *
 * Pripojenie sa berie z premenných prostredia:
 *   TEST_DB_HOST, TEST_DB_PORT, TEST_DB_USER, TEST_DB_PASS, TEST_DB_NAME (nepovinná)
 *
 * Použitie:
 *   php testy/db.php sql "SQL príkaz"         vykoná príkaz, výsledok vypíše po riadkoch (stĺpce oddelené tabulátorom)
 *   php testy/db.php import subor.sql         naimportuje SQL súbor ako phpMyAdmin (viac príkazov naraz)
 *   php testy/db.php struktura                vypíše štruktúru databázy v tvare vhodnom na porovnanie
 *   php testy/db.php pocty                    vypíše počet riadkov v každej tabuľke
 *   php testy/db.php admin MENO HESLO         založí účet s rolou admin (ak ešte neexistuje)
 */

mysqli_report(MYSQLI_REPORT_OFF);

function db_pripoj(): mysqli
{
    $db = mysqli_init();
    $ok = @$db->real_connect(
        getenv('TEST_DB_HOST') ?: '127.0.0.1',
        getenv('TEST_DB_USER') ?: 'root',
        (string) getenv('TEST_DB_PASS'),
        getenv('TEST_DB_NAME') ?: null,
        (int) (getenv('TEST_DB_PORT') ?: 3306)
    );
    if (!$ok) {
        fwrite(STDERR, 'Pripojenie k databáze zlyhalo: ' . mysqli_connect_error() . PHP_EOL);
        exit(2);
    }
    $db->set_charset('utf8mb4');
    return $db;
}

function db_dotaz(mysqli $db, string $sql): array
{
    $vysledok = $db->query($sql);
    if ($vysledok === false) {
        fwrite(STDERR, 'Chyba SQL: ' . $db->error . PHP_EOL . $sql . PHP_EOL);
        exit(1);
    }
    return $vysledok === true ? [] : $vysledok->fetch_all(MYSQLI_NUM);
}

function vypis_riadky(array $riadky): void
{
    foreach ($riadky as $riadok) {
        echo implode("\t", array_map(static fn($v) => $v === null ? 'NULL' : (string) $v, $riadok)), PHP_EOL;
    }
}

$akcia = $argv[1] ?? '';
$db = db_pripoj();

switch ($akcia) {
    case 'sql':
        vypis_riadky(db_dotaz($db, (string) ($argv[2] ?? '')));
        break;

    case 'import':
        $subor = (string) ($argv[2] ?? '');
        $sql = is_file($subor) ? file_get_contents($subor) : false;
        if ($sql === false) {
            fwrite(STDERR, "Súbor $subor sa nedá prečítať." . PHP_EOL);
            exit(1);
        }
        // Rovnako ako import v phpMyAdmin: celý súbor naraz, príkaz po príkaze.
        $poradie = 1;
        if (!$db->multi_query($sql)) {
            fwrite(STDERR, "Import $subor zlyhal na príkaze č. 1: " . $db->error . PHP_EOL);
            exit(1);
        }
        while (true) {
            if ($vysledok = $db->store_result()) {
                $vysledok->free();
            }
            if (!$db->more_results()) {
                break;
            }
            $poradie++;
            if (!$db->next_result()) {
                fwrite(STDERR, "Import $subor zlyhal na príkaze č. $poradie: " . $db->error . PHP_EOL);
                exit(1);
            }
        }
        break;

    case 'struktura':
        // Poradie stĺpcov sa neporovnáva: ALTER TABLE ... ADD v migrácii pridá stĺpec
        // na koniec, kým v instalacia.sql môže byť v strede, a appke je to jedno.
        $schema = $db->real_escape_string((string) (getenv('TEST_DB_NAME') ?: ''));
        echo "# Tabuľky (engine, porovnávanie)\n";
        vypis_riadky(db_dotaz($db, "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = '$schema' ORDER BY TABLE_NAME"));
        echo "# Stĺpce (tabuľka, stĺpec, typ, NULL, predvolená hodnota, extra, porovnávanie)\n";
        vypis_riadky(db_dotaz($db, "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$schema' ORDER BY TABLE_NAME, COLUMN_NAME"));
        echo "# Indexy (tabuľka, index, unikátny, stĺpce)\n";
        vypis_riadky(db_dotaz($db, "SELECT TABLE_NAME, INDEX_NAME, IF(NON_UNIQUE = 0, 'unikátny', 'bežný'),
                GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$schema'
            GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME"));
        echo "# Cudzie kľúče (tabuľka, názov, stĺpce, odkazuje na, pri zmazaní)\n";
        vypis_riadky(db_dotaz($db, "SELECT k.TABLE_NAME, k.CONSTRAINT_NAME,
                GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION SEPARATOR ','),
                CONCAT(k.REFERENCED_TABLE_NAME, '(', GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION SEPARATOR ','), ')'),
                r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
            WHERE k.TABLE_SCHEMA = '$schema' AND k.REFERENCED_TABLE_NAME IS NOT NULL
            GROUP BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE
            ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME"));
        break;

    case 'pocty':
        foreach (db_dotaz($db, 'SHOW TABLES') as [$tabulka]) {
            $pocet = db_dotaz($db, 'SELECT COUNT(*) FROM `' . str_replace('`', '``', $tabulka) . '`')[0][0];
            echo $tabulka, "\t", $pocet, PHP_EOL;
        }
        break;

    case 'admin':
        $meno = (string) ($argv[2] ?? '');
        $heslo = (string) ($argv[3] ?? '');
        $stmt = $db->prepare("INSERT IGNORE INTO users (username, password_hash, role, full_name) VALUES (?, ?, 'admin', ?)");
        $hash = password_hash($heslo, PASSWORD_DEFAULT);
        $cele = 'Testovací Admin';
        $stmt->bind_param('sss', $meno, $hash, $cele);
        if (!$stmt->execute()) {
            fwrite(STDERR, 'Účet sa nepodarilo založiť: ' . $stmt->error . PHP_EOL);
            exit(1);
        }
        break;

    default:
        fwrite(STDERR, "Neznáma akcia „{$akcia}“. Pozri popis na začiatku testy/db.php." . PHP_EOL);
        exit(2);
}
