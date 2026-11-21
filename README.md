# Barrierepruefung.de für TYPO3

TYPO3-Extension, die die Prüfung und die Barrierefreiheitserklärung von [barrierepruefung.de](https://barrierepruefung.de)
über die Public API des Dienstes einbindet. Sie enthält kein eigenes Rechtswissen: Texte, Pflichtangaben und
Fragen kommen vom Dienst.

- TYPO3 13.4 LTS und 14.3 LTS, PHP 8.2 bis 8.5
- Lizenz: GPL-2.0-or-later

## Installation

```bash
composer require lubomedia/barrierepruefung-typo3
```

Die Extension ist im Aufbau (Version 0.1, Gerüst). Funktionen kommen schrittweise; siehe `CHANGELOG.md`.

## Entwicklung

```bash
composer install
composer test:unit
composer test:functional
composer lint
composer stan
```
