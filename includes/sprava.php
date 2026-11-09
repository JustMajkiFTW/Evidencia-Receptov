<?php
/**
 * sprava.php — spoločné rozloženie stránok Správy (verzia 2.1).
 *
 * Správa vyzerá rovnako ako zvyšok appky:
 *  - PC a tablet (od 768 px): bočná ponuka ako na stránke receptov, pod položkou
 *    Správa sú jej časti (Používatelia, Kategórie, Suroviny, Prevod surovín),
 *    vpravo veľký nadpis a obsah.
 *  - Telefón: veľký nadpis, štyri tlačidlá s časťami Správy a spodná lišta
 *    (Recepty, Pridať, Nákup, Viac). Lišta vedie späť do appky, kde sa cez
 *    ?otvor=… otvorí to isté okno ako z lišty na stránke receptov (script.js).
 *    Objednané jedlo je stránka v appke (?otvor=objednavky): na PC v bočnej
 *    ponuke, na telefóne v spodnej lište (od 2.3: Recepty, Objednané, Pridať, Nákup, Viac).
 *
 * Použitie na stránke v admin/:
 *   sprava_zaciatok($pdo, 'suroviny', 'Suroviny');   // od <!DOCTYPE> po začiatok obsahu
 *   … obsah stránky …
 *   sprava_koniec($pdo);                              // koniec obsahu a spodná lišta
 *   … vlastné <script> stránky … </body></html>
 */

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/objednavky.php';

/** Časti Správy: kľúč => [súbor v admin/, ikona, názov v ponuke, krátky názov na telefóne]. */
const SPRAVA_CASTI = [
    'pouzivatelia' => ['index.php', 'bi-people', 'Používatelia', 'Používatelia'],
    'kategorie'    => ['kategorie.php', 'bi-tags', 'Kategórie receptov', 'Kategórie'],
    'suroviny'     => ['suroviny.php', 'bi-basket', 'Suroviny', 'Suroviny'],
    'prevod'       => ['prevod-surovin.php', 'bi-arrow-left-right', 'Prevod surovín', 'Prevod'],
];

/** Údaje pre ponuku: meno, iniciály, počet v nákupe, suroviny bez kategórie. */
function sprava_udaje(PDO $pdo): array
{
    static $udaje = null;
    if ($udaje !== null) {
        return $udaje;
    }
    $user = current_user() ?? ['username' => '', 'full_name' => '', 'role' => 'user'];
    $meno = trim((string) ($user['full_name'] ?: $user['username']));
    // Iniciály ako v okne Viac: „Marián Čambálik" → „MČ".
    $inicialy = '';
    foreach (preg_split('/\s+/u', $meno) ?: [] as $slovo) {
        if ($slovo !== '' && mb_strlen($inicialy) < 2) {
            $inicialy .= mb_strtoupper(mb_substr($slovo, 0, 1));
        }
    }
    $nakup = shopping_available($pdo);
    $udaje = [
        'meno'          => $meno,
        'inicialy'      => $inicialy,
        'admin'         => ($user['role'] ?? '') === 'admin',
        'nakup'         => $nakup,
        'nakupPocet'    => $nakup ? shopping_open_count($pdo) : 0,
        'bezKategorie'  => suroviny_available($pdo) ? count(get_suroviny_for_admin($pdo, '', 1000)) : 0,
        'ai'            => ai_dostupne(),
        'objednavky'    => objednavky_available($pdo),
    ];
    return $udaje;
}

/** Odznak pri časti Správy (len Suroviny: koľko surovín nemá kategóriu). */
function sprava_odznak(PDO $pdo, string $cast): int
{
    return $cast === 'suroviny' ? sprava_udaje($pdo)['bezKategorie'] : 0;
}

/**
 * Hlavička HTML, bočná ponuka (PC), nadpis a tlačidlá častí (telefón).
 * $aktivna = kľúč z SPRAVA_CASTI, $nadpis = veľký nadpis stránky.
 */
function sprava_zaciatok(PDO $pdo, string $aktivna, string $nadpis): void
{
    $u = sprava_udaje($pdo);
    $h = fn(string $text): string => htmlspecialchars($text, ENT_QUOTES);
    ?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <script src="../theme.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= $h($nadpis) ?> — Správa — Evidencia receptov</title>
    <link rel="manifest" href="../manifest.json?v=4">
    <meta name="theme-color" content="#f59e0b">
    <link rel="icon" type="image/png" sizes="192x192" href="../app-icons/icon-192.png?v=4">
    <link rel="apple-touch-icon" sizes="180x180" href="../app-icons/apple-touch-icon.png?v=4">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css?v=35">
</head>
<body class="ma-spodnu-listu sprava">

<div class="app-rozlozenie">
<!-- Bočná ponuka (PC a tablet) — tá istá ako na stránke receptov, pod Správou sú jej časti -->
<nav class="app-bocna d-none d-md-flex" aria-label="Hlavná ponuka">
    <div class="app-bocna-logo">
        <span class="app-bocna-logo-ikona" aria-hidden="true"><i class="bi bi-egg-fried"></i></span>
        <span class="app-bocna-logo-text">Evidencia<br>receptov</span>
    </div>
    <a href="../index.php?otvor=pridat" class="btn app-bocna-pridat">
        <i class="bi bi-plus-lg" aria-hidden="true"></i>Pridať recept
    </a>
    <a href="../index.php" class="app-bocna-polozka">
        <i class="bi bi-journal-text" aria-hidden="true"></i><span class="app-bocna-text">Recepty</span>
    </a>
    <?php if ($u['objednavky']): ?>
    <a href="../index.php?otvor=objednavky" class="app-bocna-polozka">
        <i class="bi bi-bag" aria-hidden="true"></i><span class="app-bocna-text">Objednané jedlo<small>Wolt, Bolt Food…</small></span>
    </a>
    <?php endif; ?>
    <?php if ($u['nakup']): ?>
    <a href="../index.php?otvor=nakup" class="app-bocna-polozka">
        <i class="bi bi-cart3" aria-hidden="true"></i><span class="app-bocna-text">Nákupný zoznam</span>
        <?php if ($u['nakupPocet']): ?>
            <span class="app-bocna-pocet" aria-label="Položiek na kúpenie: <?= $u['nakupPocet'] ?>"><?= $u['nakupPocet'] ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
    <a href="index.php" class="app-bocna-polozka je-rodic">
        <i class="bi bi-gear" aria-hidden="true"></i><span class="app-bocna-text">Správa</span>
    </a>
    <div class="app-bocna-podponuka">
        <?php foreach (SPRAVA_CASTI as $kluc => [$subor, $ikona, $nazov]): $odznak = sprava_odznak($pdo, $kluc); ?>
            <a href="<?= $subor ?>" class="app-bocna-podpolozka<?= $kluc === $aktivna ? ' is-active' : '' ?>"<?= $kluc === $aktivna ? ' aria-current="page"' : '' ?>>
                <i class="bi <?= $ikona ?>" aria-hidden="true"></i><span class="app-bocna-text"><?= $nazov ?></span>
                <?php if ($odznak): ?><span class="app-bocna-odznak" title="Suroviny bez kategórie"><?= $odznak ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="app-bocna-spodok">
        <div class="app-bocna-nadpis">Nastavenia</div>
        <!-- Prepínač motívu: theme.js (id themeToggle), stav prepínača ukazuje CSS podľa data-bs-theme -->
        <button type="button" id="themeToggle" class="app-bocna-polozka app-bocna-nastavenie" aria-pressed="true">
            <i class="bi bi-moon-stars" aria-hidden="true"></i><span class="app-bocna-text">Tmavý motív</span>
            <span class="app-bocna-prepinac" aria-hidden="true"></span>
        </button>
        <div class="app-bocna-pouzivatel">
            <span class="app-bocna-inicialy" aria-hidden="true"><?= $h($u['inicialy']) ?></span>
            <span class="app-bocna-meno">
                <span><?= $h($u['meno']) ?></span>
                <small><?= $u['admin'] ? 'Admin' : 'Používateľ' ?></small>
            </span>
            <a href="../logout.php" class="app-bocna-odhlasit" aria-label="Odhlásiť" title="Odhlásiť">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</nav>

<main class="app-obsah">
<!-- Hlavička na PC -->
<div class="app-hlavicka d-none d-md-flex">
    <div class="min-w-0">
        <div class="app-pozdrav">Správa</div>
        <h1 class="app-nadpis"><?= $h($nadpis) ?></h1>
    </div>
</div>
<!-- Hlavička na telefóne a časti Správy ako veľké tlačidlá -->
<div class="mobil-hlavicka d-md-none">
    <div class="min-w-0">
        <div class="mobil-pozdrav">Správa</div>
        <h1 class="mobil-nadpis"><?= $h($nadpis) ?></h1>
    </div>
</div>
<nav class="sprava-casti d-md-none" aria-label="Časti správy">
    <?php foreach (SPRAVA_CASTI as $kluc => [$subor, $ikona, , $kratky]): $odznak = sprava_odznak($pdo, $kluc); ?>
        <a href="<?= $subor ?>" class="sprava-cast<?= $kluc === $aktivna ? ' is-active' : '' ?>"<?= $kluc === $aktivna ? ' aria-current="page"' : '' ?>>
            <i class="bi <?= $ikona ?>" aria-hidden="true"></i><span><?= $kratky ?></span>
            <?php if ($odznak): ?><span class="sprava-cast-pocet" title="Suroviny bez kategórie"><?= $odznak ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
<div class="sprava-obsah">
    <?php
}

/** Koniec obsahu a spodná lišta (telefón). Stránka potom pridá svoje skripty a </body></html>. */
function sprava_koniec(PDO $pdo): void
{
    $u = sprava_udaje($pdo);
    ?>
</div>
</main>
</div>

<!-- Spodná lišta (telefón) — tie isté položky ako na stránke receptov, Správa patrí pod Viac -->
<nav class="spodna-lista d-md-none<?= $u['objednavky'] && $u['nakup'] ? ' ma-5-poloziek' : '' ?>" aria-label="Hlavné menu">
    <a href="../index.php" class="spodna-lista-polozka">
        <i class="bi bi-journal-text" aria-hidden="true"></i><span>Recepty</span>
    </a>
    <?php if ($u['objednavky']): ?>
    <a href="../index.php?otvor=objednavky" class="spodna-lista-polozka">
        <i class="bi bi-bag" aria-hidden="true"></i><span>Objednané</span>
    </a>
    <?php endif; ?>
    <a href="../index.php?otvor=pridat" class="spodna-lista-polozka">
        <span class="spodna-lista-pridat" aria-hidden="true"><i class="bi <?= $u['ai'] ? 'bi-camera' : 'bi-plus-lg' ?>"></i></span><span>Pridať</span>
    </a>
    <?php if ($u['nakup']): ?>
    <a href="../index.php?otvor=nakup" class="spodna-lista-polozka">
        <span class="spodna-lista-ikona" aria-hidden="true"><i class="bi bi-cart3"></i>
            <?php if ($u['nakupPocet']): ?><span class="spodna-lista-pocet"><?= $u['nakupPocet'] ?></span><?php endif; ?></span><span>Nákup</span>
    </a>
    <?php endif; ?>
    <a href="../index.php?otvor=viac" class="spodna-lista-polozka is-active" aria-current="page">
        <i class="bi bi-list" aria-hidden="true"></i><span>Viac</span>
    </a>
</nav>
<script>
// Prepínač motívu v bočnej ponuke: aria-pressed podľa motívu (theme.js ho prepne vo vlastnom poslucháčovi).
(function () {
    const tlacidlo = document.getElementById('themeToggle');
    if (!tlacidlo) return;
    const nastav = () => tlacidlo.setAttribute('aria-pressed',
        document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'true' : 'false');
    nastav();
    tlacidlo.addEventListener('click', () => setTimeout(nastav));
})();
</script>
    <?php
}

/** „1 recept", „3 recepty", „7 receptov" */
function sprava_pocet_receptov(int $pocet): string
{
    return $pocet . ' ' . ($pocet === 1 ? 'recept' : ($pocet >= 2 && $pocet <= 4 ? 'recepty' : 'receptov'));
}
