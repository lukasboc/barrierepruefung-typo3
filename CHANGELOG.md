# Changelog

## 0.1.0 – 2026-11-24

Erste Fassung (beta): verbinden, Domain bestätigen, prüfen, Befunde ansehen, Erklärung einbetten, den Weg zur
Erklärung gehen und die Erklärung freigeben.

### Neu

- **Verbinden:** Backend-Modul „Barrierefreiheit“ unter „Site-Verwaltung“. Eine Site wird mit ihrer Website im Dienst
  verbunden (API-Adresse, Website-Kennung, Token). Der Token liegt verschlüsselt in der Datenbank, nie in der
  Site-Konfiguration. Die Adresse der Site muss zum selben Host gehören wie die der Website im Dienst.
- **Domain bestätigen:** Die Extension liefert den Nachweis für jede verbundene Site (Meta-Element der Startseite und
  Datei `/.well-known/a11y-site-verification.txt`, je mit eigenem Token) und löst die Bestätigung im Dienst aus.
- **Prüfen:** „Jetzt prüfen“ legt einen Lauf im Dienst an (doppelte Klicks sind wirkungslos); die Übersicht zeigt Stand
  der Verbindung, Seitenkontingent und den letzten Lauf.
- **Befunde:** „Befunde“ zeigt die offenen Befunde des letzten Laufs je Regel.
- **Einbetten:** Inhaltselement „Erklärung zur Barrierefreiheit“ (`barrierepruefung_erklaerung`) mit den Feldern
  Teil (komplett, nur Mängel, nur Kontakt), erste Überschriftenebene (h2 bis h4) und Stand. Gezeigt wird die
  veröffentlichte Fassung des Dienstes in der Sprache, in der sie freigegeben wurde. Ohne eigene Gestaltung, ohne Skript.
- **Die Erklärung verschwindet nie:** Fällt der Dienst aus oder ist der Token ungültig, bleibt die letzte gute
  Fassung mit ihrem eigenen Datum stehen.
- **Seite für die Erklärung** lässt sich aus dem Untermodul „Erklärung“ anlegen (verborgen, mit dem Element).
- **Weg zur Erklärung:** Untermodul „Weg zur Erklärung“ (`barrierepruefung_weg`). Es zeigt die Schritte, wie der Dienst
  sie nennt, und erfasst Prüfschritte, wer erklärt, den Betroffenheits-Check und die Pflichtangaben im Backend. Das
  braucht einen Token, dem eine Administratorin oder ein Administrator im Dienst das Recht zum Vorbereiten und
  Veröffentlichen erteilt hat; ohne dieses Recht stehen statt der Formulare Sätze mit dem Weg zum Recht.
- **Freigeben:** Am Ende des Wegs lässt sich die Erklärung aus TYPO3 veröffentlichen: Vorschau des Entwurfs, Aussage
  zur Konformität (eine Abweichung von der ermittelten braucht eine Begründung), dann „Fassung n jetzt
  veröffentlichen“. Ein Doppelklick ergibt eine Fassung, ein inzwischen geänderter Entwurf wird nicht still
  veröffentlicht, sondern neu gezeigt. Seiten mit dem Element zeigen die neue Fassung sofort. In der Fassung steht der
  Name der Backend-Person (`realName`, sonst Benutzername) als die Person, die freigegeben hat.
- Konsolenbefehl `barrierepruefung:aktualisieren` gleicht die Fassung ab und leert genau die Seiten im Seitencache,
  die sie zeigen.
- Getestet mit TYPO3 13.4 LTS und 14.3 LTS (PHP 8.2 bis 8.5), einschließlich einer Abnahme gegen den echten Dienst.

### Hinweise für Betreiber

- **Der Composer-Name lautet `barrierepruefungde/typo3`** (Extension-Key `barrierepruefung`).
- **Backend-Gruppen brauchen das Modulrecht für jedes der vier Untermodule einzeln:** `barrierepruefung_uebersicht`,
  `barrierepruefung_befunde`, `barrierepruefung_erklaerung` und `barrierepruefung_weg`. Eine Gruppe, die nur die
  Übersicht hat, sieht die anderen drei nicht.
  Zusätzlich braucht die Person einen Web-Mount, der die Site enthält.
- **Scheduler-Aufgabe einrichten:** Für zeitnahe Aktualisierung die Aufgabe „Konsolenbefehl ausführen“ mit
  `barrierepruefung:aktualisieren` stündlich anlegen. Ohne sie ist eine Seite höchstens so lange alt, wie die Fassung
  im Cache gültig ist (höchstens eine Stunde).
- **Nach der Installation und nach jedem Update** `vendor/bin/typo3 extension:setup` bzw. „Datenbank vergleichen“
  ausführen.
- **TYPO3 14.3 meldet eine Deprecation zu `ext_emconf.php`.** Sie ist bekannt. Die Datei bleibt, weil 13.4 und der
  Upload ins TER sie brauchen.
- **Site mit Pfad als Basis** (`https://example.org/museum/`): Die Nachweisdatei löst dort nicht auf die Site auf, der
  Dienst fragt sie immer am Wurzelpfad des Hosts. Die Domain lässt sich nur über das Meta-Element bestätigen.
- **Nach einem Wechsel des `encryptionKey`** lässt sich der Token nicht mehr entschlüsseln: Die Seiten zeigen weiter die
  letzte Fassung, der Befehl bricht nicht ab, das Modul bittet darum, die Verbindung neu herzustellen.
- **Sprache der Fachtexte:** Backend-Personen mit der Sprache „default“ (Englisch) bekommen die Texte des Dienstes
  englisch.
- **Gestaltung ist Sache des Sitepackages.** Das Element bringt kein CSS mit. In Camino (Standard-Theme von 14.3) ist
  `h2` klein und `h3` 40 px groß, und lange Wörter in Überschriften laufen bei 320 px über den Rand (z. B.
  `hyphens: auto` setzen). Das gilt nur, wenn die Site das Set `typo3/theme-camino` direkt als Abhängigkeit führt.

### Entwicklung

- Entwicklungsabhängigkeiten aufgelöst mit: `typo3/testing-framework` 9.7 (beide LTS), `typo3/coding-standards` ^0.8,
  `phpstan/phpstan` ^2, `saschaegerer/phpstan-typo3` ^2 || ^3 (3.1 auf beiden LTS).
- Abnahme gegen den echten Dienst: `Build/e2e/abnahme.mjs`.
