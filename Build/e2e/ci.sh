#!/usr/bin/env bash
# Die CI-Läufe (.github/workflows/e2e.yml) und ihr lokaler Trockenlauf: eine TYPO3-14.3-Instanz mit SQLite unter
# `php -S` (ohne Docker), dazu die API-Attrappe, dann `npm test`. Dieselben Schritte wie Build/lokal/lokal.sh.
#
#   Build/e2e/ci.sh <leerer Projektordner>      (Ordner wird angelegt; Port 8090 und 8091 müssen frei sein)
set -euo pipefail

PROJEKT="${1:?Projektordner angeben}"
HIER="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT="$(cd "$HIER/../.." && pwd)"
SEED="$EXT/Build/lokal/seed"
PORT=8090
SET='typo3/theme-camino'
ADMIN_PASSWORT='Lokal-Admin-1!'
log() { printf '==> %s\n' "$*"; }

mkdir -p "$PROJEKT"
PROJEKT="$(cd "$PROJEKT" && pwd)"
cd "$PROJEKT"

log "composer install (TYPO3 14.3, Erweiterung als Pfad-Repository $EXT)"
# Pfad-Repository auf das Checkout statt /ext im Container.
perl -pe "s#\"/ext\"#\"$EXT\"#" "$EXT/Build/lokal/v14/composer.json" > composer.json
composer install --no-interaction --no-progress --prefer-dist

log "typo3 setup (SQLite, Site http://localhost:$PORT/)"
export TYPO3_CONTEXT=Development   # wie im Docker-Container: Verbinden darf dann http:// zur Attrappe
php vendor/bin/typo3 setup --no-interaction --driver=sqlite \
  --admin-username=admin --admin-user-password="$ADMIN_PASSWORT" --admin-email=admin@example.org \
  --project-name="Barrierepruefung CI" --create-site="http://localhost:$PORT/" --server-type=other --force
php vendor/bin/typo3 extension:setup --no-interaction

# php -S meldet den Host mit Port; die Standardprüfung des Einrichtungsbefehls lässt ihn nicht zu.
printf "<?php\n\$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';\n" > config/system/additional.php

SITE="$(ls config/sites | head -n1)"
log "Site '$SITE': Basis http://localhost:$PORT/, Set $SET"
perl -pi -e "s#^base: .*#base: 'http://localhost:$PORT/'#" "config/sites/$SITE/config.yaml"
grep -q '^dependencies:' "config/sites/$SITE/config.yaml" || printf 'dependencies:\n  - %s\n' "$SET" >> "config/sites/$SITE/config.yaml"

log "zweite Site und Seiten"
mkdir -p config/sites/zweite-site
perl -pe "s/__PORT__/$PORT/; s#__SET__#$SET#" "$SEED/zweite-site.yaml" > config/sites/zweite-site/config.yaml
{
  cat "$SEED/seiten.sql"
  printf "UPDATE pages SET backend_layout='pagets__CaminoStartpage' WHERE uid=100;\nUPDATE pages SET backend_layout='pagets__CaminoContentpage' WHERE uid IN (101,102,103);\n"
  cat "$SEED/erklaerungsseiten.sql"
  printf "UPDATE pages SET backend_layout='pagets__CaminoContentpage' WHERE uid BETWEEN 301 AND 318;\n"
} > var/seiten.sql
php -r '
  $pdo = new PDO("sqlite:" . (require "config/system/settings.php")["DB"]["Connections"]["Default"]["path"]);
  $pdo->exec("UPDATE sys_template SET hidden=1 WHERE config LIKE \"%Welcome to a default website%\"");
  $pdo->exec(file_get_contents("var/seiten.sql"));
  echo "Seiten: ", $pdo->query("select count(*) from pages")->fetchColumn(), PHP_EOL;
'
php vendor/bin/typo3 cache:flush

log "Server starten: TYPO3 auf :$PORT (php -S), Attrappe auf :8091"
PHP_CLI_SERVER_WORKERS=4 php -d memory_limit=512M -d max_execution_time=240 -S "0.0.0.0:$PORT" -t public "$HIER/ci-router.php" > "$PROJEKT/php-s.log" 2>&1 &
TYPO3_PID=$!
(cd "$HIER" && node stub-api.mjs > "$PROJEKT/stub.log" 2>&1) &
STUB_PID=$!
# Mit PHP_CLI_SERVER_WORKERS bleiben die Arbeitsprozesse nach dem Beenden des Elternprozesses stehen.
trap 'kill $STUB_PID 2>/dev/null || true; pkill -f "php.*-S 0.0.0.0:$PORT" || true' EXIT

for i in $(seq 1 60); do
  curl -fs -o /dev/null "http://localhost:$PORT/typo3/" && curl -fs -o /dev/null "http://localhost:8091/__stub/anfragen" && break
  [ "$i" = 60 ] && { echo 'Server kommt nicht hoch'; tail -20 "$PROJEKT/php-s.log" "$PROJEKT/stub.log"; exit 1; }
  sleep 1
done

log "npm test (nur v14)"
cd "$HIER"
mkdir -p ergebnisse
INSTANZ=v14 E2E_TYPO3_DIR="$PROJEKT" E2E_DIENST_URL='http://127.0.0.1:8091/api/v1' npm test
