# smalot/pdfparser 2.13.1 (pribalená kópia)

Čítanie textu z PDF kuchárok (verzia 2.8, `includes/kucharky.php`). Knižnica je
pribalená priamo v appke namiesto `vendor/`, aby sa na server dostala aj
s automatickým nasadením a netreba ju nahrávať ručne.

- Zdroj: https://github.com/smalot/pdfparser (tag v2.13.1), priečinok `src/` bez zmien.
- Licencia: LGPL-3.0, pozri `LICENSE.txt`.
- Načítanie: vlastný autoloader v `includes/kucharky.php` (PSR-0, menný priestor `Smalot\PdfParser`).
  Potrebuje rozšírenia PHP `zlib`, `iconv` a `mbstring` (appka ich už vyžaduje).

Pri aktualizácii nahraď celý priečinok `src/` novou verziou a uprav číslo verzie vyššie.
