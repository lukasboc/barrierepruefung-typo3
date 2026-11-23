# CLAUDE.md

TYPO3-Extension `barrierepruefung` (Composer: `barrierepruefungde/typo3`) zu barrierepruefung.de
(Prüfung digitaler Barrierefreiheit, BFSG/BITV 2.0). Sie ist ein dünner Client der Public API des Dienstes,
Geschwister des WordPress-Plugins. TYPO3 13.4 LTS und 14.3 LTS, PHP 8.2 bis 8.5.
Deutsch ist die Sprache für Kommentare, Commits und Dokumentation.

## Kommandos

```bash
composer install
composer test:unit         # PHPUnit, ohne TYPO3-Instanz
composer test:functional   # Funktionstests mit SQLite (typo3DatabaseDriver=pdo_sqlite)
composer lint              # php-cs-fixer --dry-run
composer lint:fix
composer stan              # PHPStan Stufe 8
```

Die CI läuft gegen 13.4 und 14.3; lokal gilt, was Composer auflöst. Eine andere LTS prüfen:
`composer require typo3/cms-core:^13.4 -W` (und die übrigen `typo3/cms-*`), danach die Tests.

## Was hier nicht verhandelbar ist

- **Die Erklärung verschwindet nie.** Fällt der Dienst aus, bleibt die letzte gute Fassung mit ihrem eigenen Datum stehen.
- **Die Extension kennt kein Recht.** Alle Rechtstexte, Pflichtangaben und Fragen kommen vom Dienst; eine Rechtsänderung darf kein Release verlangen.
- **Der Token steht nie in Git** und nicht in der Site-Konfiguration (`config.yaml`): verschlüsselt in der Datenbank.
- **Der Seiten-Cache lässt Seiten los**, sobald eine neue Fassung veröffentlicht wird (Cache-Tags).
- **Die Extension ist selbst barrierefrei:** keine `h1` mitten auf der Seite, das Backend-Modul per Tastatur bedienbar.
- **Nie ein Veröffentlichungsrecht behaupten**, das der Token nicht hat.
- **Keine Telemetrie, kein Nachladen von Code.**

## Der Vertrag mit dem Dienst

Das HTML-Fragment (Überschriftenebenen, Abschnitte) ist eine Portierung von `DeclarationFragment` des Dienstes.
Ein Gleichlauf-Test im Dienst-Repository hält beide zusammen und liest die Fixtures aus
`Tests/Fixtures/fragment/` des Tag-Archivs (deshalb nimmt `.gitattributes` `Tests/` nicht als Ganzes
aus). Änderung = beide Seiten.

## Lokale Umgebung

Siehe `Build/lokal/README.md` (kommt mit Aufgabe 2).
