# Fixtures für die Gleichlauf-Tests

HTML, wie es `GET /sites/{id}/declaration` als `html` liefert
(`DeclarationMarkdown::render($content_md)`). Ein Fall je Datei.

| Datei | Herkunft |
|---|---|
| `oeffentlich-bund.html` | Vorlage `public-sector-statement`, Stelle des Bundes (BITV 2.0), mit Mängelliste und Kontakt |
| `oeffentlich-land.html` | Vorlage `public-sector-statement`, Stelle in Niedersachsen (NBGG/NBITVO) |
| `bfsg.html` | Vorlage `eaa-service-information` (§ 14 BFSG): „Bekannte Einschränkungen", „Kontakt bei Barrieren" |
| `freiwillig.html` | Vorlage `voluntary-accessibility-statement` |
| `ohne-maengel.html` | von Hand: Erklärung ohne Mängelabschnitt |
| `ohne-ueberschriften.html` | von Hand: nur Absätze |
| `tiefe-ebenen.html` | von Hand: `h1`, `h5`, `h6` (Verschieben über h6 hinaus) |
| `nur-mittlere-ebene.html` | von Hand: eine einzelne `h3` |
| `abschnitt-fehlt.html` | von Hand: weder Mängel- noch Kontaktabschnitt |

Die vier Vorlagenfälle wurden am 22.11.2026 mit einem Wegwerfskript aus den
Fabriken über `DeclarationGenerator` erzeugt (zwei Befunde, `image-alt` und
`color-contrast`; Kontakt `barrierefreiheit@beispiel.test`, alle Namen und
Adressen sind Platzhalter unter `beispiel.test`).

Es lesen sie: der Gleichlauf-Test zum WordPress-Plugin
(`tests/Unit/WordPressShortcodeTest.php`), der zur TYPO3-Extension, und die
Extension selbst mit ihrer Kopie unter `Tests/Fixtures/fragment/`.
**Wer hier eine Datei ändert, ändert auch die Kopie der Extension.**
