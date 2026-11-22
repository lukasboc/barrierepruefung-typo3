# Barrierepruefung.de für TYPO3

## Deutsch

TYPO3-Extension, die die Prüfung und die Barrierefreiheitserklärung von [barrierepruefung.de](https://barrierepruefung.de)
einbindet. Sie ist ein dünner Client der Public API des Dienstes und enthält kein eigenes Rechtswissen: Texte,
Pflichtangaben und Fragen kommen vom Dienst.

- **Prüfen:** Site mit ihrer Website im Dienst verbinden, Domain bestätigen, „Jetzt prüfen“ und offene Befunde im Backend-Modul.
- **Einbetten:** Das Inhaltselement „Erklärung zur Barrierefreiheit“ zeigt die veröffentlichte Erklärung der Site. Fällt der Dienst aus, bleibt die letzte gute Fassung mit ihrem Datum stehen.
- **Nicht dabei:** kein Overlay, keine eigene Prüfmaschine, keine automatische Reparatur, keine Telemetrie.

Voraussetzungen: TYPO3 13.4 LTS oder 14.3 LTS, PHP 8.2 bis 8.5, ein Konto bei barrierepruefung.de.

```bash
composer require lubomedia/barrierepruefung-typo3
vendor/bin/typo3 extension:setup
```

Danach im Backend unter „Site-Verwaltung > Barrierefreiheit“ verbinden und für die Aktualisierung die Scheduler-Aufgabe
`barrierepruefung:aktualisieren` stündlich einrichten. Dokumentation (englisch): [`Documentation/`](Documentation/Index.rst),
Schritt-für-Schritt-Anleitungen im Hilfebereich des Dienstes: <https://barrierepruefung.de/hilfe>. Änderungen: [CHANGELOG.md](CHANGELOG.md).

## English

TYPO3 extension that brings the accessibility checks and the accessibility statement of
[barrierepruefung.de](https://barrierepruefung.de) into TYPO3. It is a thin client of the service's public API and has
no legal knowledge of its own: texts, mandatory items and questions come from the service.

- **Check:** connect a site to its website in the service, confirm the domain, start a check and read the open findings in the backend module.
- **Embed:** the content element "Accessibility statement" shows the site's published statement. If the service is down, the last good version stays on the page with its own date.
- **Not included:** no overlay, no local checking engine, no automatic repair, no telemetry.

Requirements: TYPO3 13.4 LTS or 14.3 LTS, PHP 8.2 to 8.5, an account at barrierepruefung.de.

```bash
composer require lubomedia/barrierepruefung-typo3
vendor/bin/typo3 extension:setup
```

Then connect the site in the backend under "Site Management > Accessibility" and schedule the command
`barrierepruefung:aktualisieren` hourly. Documentation: [`Documentation/`](Documentation/Index.rst).

## Entwicklung / Development

```bash
composer install
composer test:unit
composer test:functional
composer lint
composer stan
```

Lokale Instanzen und Abnahme: `Build/lokal/README.md`.

## Lizenz / License

GPL-2.0-or-later
