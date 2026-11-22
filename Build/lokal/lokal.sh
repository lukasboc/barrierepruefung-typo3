#!/usr/bin/env bash
# Lokale TYPO3-Instanzen 13.4 (Port 8089) und 14.3 (Port 8090) für die Erweiterung.
#   ./lokal.sh hoch                  Container bauen/starten, nicht eingerichtete Instanzen einrichten
#   ./lokal.sh einrichten <13|14>    eine Instanz einrichten (idempotent: bereits eingerichtete bleiben)
#   ./lokal.sh zuruecksetzen [13|14] var/, SQLite und Einstellungen löschen und neu einrichten
#   ./lokal.sh runter                Container stoppen (Daten bleiben)
#   ./lokal.sh seiten <13|14>        die 18 Erklärungsseiten (neu) anlegen, auch in bestehenden Instanzen
#   ./lokal.sh typo3 <13|14> <args>  vendor/bin/typo3 im Container
set -euo pipefail

HIER="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$HIER"

ADMIN_NAME='admin'
ADMIN_PASSWORT='Lokal-Admin-1!'

port_von()  { case "$1" in 13) echo 8089 ;; 14) echo 8090 ;; *) echo "Unbekannte Fassung: $1 (13 oder 14)" >&2; exit 2 ;; esac; }
set_von()   { case "$1" in 13) echo 'bootstrap-package/full' ;; 14) echo 'typo3/theme-camino' ;; esac; }
log()       { printf '==> %s\n' "$*"; }
dc()        { docker compose "$@"; }
im()        { local v="$1"; shift; dc exec -T "v$v" "$@"; }

# Marke als allerletzter Schritt von einrichten(): ein Abbruch mittendrin (z. B. beim Seiten-SQL) bleibt so nicht unbemerkt.
MARKE="config/.einrichtung-fertig"
eingerichtet() { [ -f "v$1/$MARKE" ]; }

einrichten() {
  local v="$1" port set
  port="$(port_von "$v")"; set="$(set_von "$v")"
  if eingerichtet "$v"; then
    log "v$v ist bereits eingerichtet - übersprungen (zuruecksetzen für Neuanfang)"
    return 0
  fi
  if [ -f "v$v/config/system/settings.php" ]; then
    log "v$v: unvollständige Einrichtung gefunden (keine Marke) - wird mit --force neu aufgesetzt"
  fi
  log "v$v: composer install"
  im "$v" composer install --no-interaction --no-progress
  log "v$v: typo3 setup (SQLite, Administrator $ADMIN_NAME, Site http://localhost:$port/)"
  im "$v" vendor/bin/typo3 setup --no-interaction --driver=sqlite \
    --admin-username="$ADMIN_NAME" --admin-user-password="$ADMIN_PASSWORT" --admin-email=admin@example.org \
    --project-name="Barrierepruefung lokal $v" --create-site="http://localhost:$port/" \
    --server-type=apache --force
  # Tabellen der Erweiterungen (u. a. tx_barrierepruefung_verbindung): „setup“ legt nur die des Kerns an.
  log "v$v: extension:setup"
  im "$v" vendor/bin/typo3 extension:setup --no-interaction

  # Mit typo3/theme-camino (14.3) importiert "setup" die Distribution samt eigener Site (config/sites/camino,
  # Wurzelseite 1, Basis /camino/) und beachtet --create-site nicht: dort wird nur die Basis umgestellt.
  local erste
  erste="$(im "$v" sh -c 'ls config/sites | head -n1' | tr -d '\r')"
  log "v$v: Site '$erste': Basis http://localhost:$port/, Sitepackage-Set $set"
  im "$v" sh -c "sed -i \"s#^base: .*#base: 'http://localhost:$port/'#\" config/sites/$erste/config.yaml; grep -q '^dependencies:' config/sites/$erste/config.yaml || printf 'dependencies:\n  - %s\n' '$set' >> config/sites/$erste/config.yaml"

  # „setup --create-site“ legt auf der Wurzelseite ein sys_template („Welcome to a default website …“) an. Es verdrängt
  # das Sitepackage-Set, die Seite zeigt dann kein Sitepackage (und läuft bei 320 px quer). Verbergen, nicht löschen.
  im "$v" php -r '
    $pdo = new PDO("sqlite:" . (require "config/system/settings.php")["DB"]["Connections"]["Default"]["path"]);
    $abfrage = $pdo->prepare("UPDATE sys_template SET hidden=1 WHERE config LIKE ?");
    $abfrage->execute(["%Welcome to a default website%"]);
    echo "Begrüßungs-Template verborgen: ", $abfrage->rowCount(), PHP_EOL;
  '

  log "v$v: zweite Site (Wurzelseite 100, Basis http://127.0.0.1:$port/)"
  mkdir -p "v$v/config/sites/zweite-site"
  sed -e "s/__PORT__/$port/" -e "s#__SET__#$set#" seed/zweite-site.yaml > "v$v/config/sites/zweite-site/config.yaml"
  cp seed/seiten.sql "v$v/var/seiten.sql"
  if [ "$v" = 14 ]; then
    # Camino braucht je Seite ein Backend-Layout, sonst bricht die Ausgabe mit 500 ab.
    printf "UPDATE pages SET backend_layout='pagets__CaminoStartpage' WHERE uid=100;\nUPDATE pages SET backend_layout='pagets__CaminoContentpage' WHERE uid IN (101,102,103);\n" >> "v$v/var/seiten.sql"
  fi
  im "$v" php -r '
    // Die Datei aus der Konfiguration, nicht die erste in var/sqlite: nach einem Neuaufsetzen liegen dort alte.
    $db = (require "config/system/settings.php")["DB"]["Connections"]["Default"]["path"];
    $pdo = new PDO("sqlite:" . $db);
    $pdo->exec(file_get_contents("var/seiten.sql"));
    echo "Seiten in der Datenbank: ", $pdo->query("select count(*) from pages")->fetchColumn(), PHP_EOL;
  '
  rm -f "v$v/var/seiten.sql"

  log "v$v: Seiten für den axe-Test"
  erklaerungsseiten "$v"

  log "v$v: Erweiterung barrierepruefung aktiv?"
  im "$v" vendor/bin/typo3 extension:list 2>/dev/null | grep -i barrierepruefung || log "  (Hinweis: Erweiterung nicht in extension:list - in Composer-Instanzen ist sie über den Pfad aktiv)"
  log "v$v: Cache leeren"
  im "$v" vendor/bin/typo3 cache:flush
  date -u +%FT%TZ > "v$v/$MARKE"
  log "v$v: eingerichtet (Marke $MARKE)"
}

# 18 Seiten mit dem Inhaltselement (/erklaerung-{teil}-h{n}-{mit|ohne}-stand) für den axe-Test. Idempotent.
erklaerungsseiten() {
  local v="$1"
  cp seed/erklaerungsseiten.sql "v$v/var/erklaerungsseiten.sql"
  if [ "$v" = 14 ]; then
    # Camino braucht je Seite ein Backend-Layout, sonst bricht die Ausgabe mit 500 ab.
    printf "UPDATE pages SET backend_layout='pagets__CaminoContentpage' WHERE uid BETWEEN 301 AND 318;\n" >> "v$v/var/erklaerungsseiten.sql"
  fi
  im "$v" php -r '
    $db = (require "config/system/settings.php")["DB"]["Connections"]["Default"]["path"];
    $pdo = new PDO("sqlite:" . $db);
    $pdo->exec(file_get_contents("var/erklaerungsseiten.sql"));
    echo "Erklärungsseiten: ", $pdo->query("select count(*) from pages where slug like \"/erklaerung-%\"")->fetchColumn(), PHP_EOL;
  '
  rm -f "v$v/var/erklaerungsseiten.sql"
  im "$v" vendor/bin/typo3 cache:flush
}

hoch() {
  log "docker compose up -d --build"
  dc up -d --build
  einrichten 13
  einrichten 14
  log "Fertig: Backend http://localhost:8089/typo3 (13.4), http://localhost:8090/typo3 (14.3) - $ADMIN_NAME / $ADMIN_PASSWORT"
}

zuruecksetzen() {
  local liste="${1:-13 14}" v
  dc up -d
  for v in $liste; do
    port_von "$v" >/dev/null
    log "v$v: var/, SQLite und config/ löschen"
    # im Container löschen: die Dateien gehören dort root
    im "$v" sh -c 'rm -rf var/* config/system config/sites config/.einrichtung-fertig public/typo3temp public/_assets 2>/dev/null; true'
    einrichten "$v"
  done
}

kommando="${1:-}"; shift || true
case "$kommando" in
  hoch)          hoch ;;
  einrichten)    dc up -d; einrichten "${1:?Fassung 13 oder 14}" ;;
  zuruecksetzen) zuruecksetzen "$@" ;;
  runter)        log "docker compose down"; dc down ;;
  seiten)        erklaerungsseiten "${1:?Fassung 13 oder 14}" ;;
  typo3)         v="${1:?Fassung 13 oder 14}"; shift; port_von "$v" >/dev/null; im "$v" vendor/bin/typo3 "$@" ;;
  *) sed -n '2,9p' "$0"; exit 2 ;;
esac
