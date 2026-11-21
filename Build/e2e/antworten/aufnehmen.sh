#!/usr/bin/env bash
# Nimmt die GET-Antworten vom lokalen Dienst auf und bereinigt sie (bereinigen.mjs).
#   TOKEN=… SITE=<Kennung> SCAN=<Kennung des letzten Laufs> ./aufnehmen.sh [Dienst-Basis]
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
BASIS="${1:-http://127.0.0.1:8001/api/v1}"
: "${TOKEN:?TOKEN fehlt}" "${SITE:?SITE fehlt}" "${SCAN:?SCAN fehlt}"

aufnehmen() {
  local name="$1" pfad="$2"
  echo "==> $name"
  curl -fsS -H "Authorization: Bearer $TOKEN" -H 'Accept-Language: de' -H 'Accept: application/json' "$BASIS/$pfad" \
    | node bereinigen.mjs "$SITE" "$SCAN" > "$name.json.neu"
  mv "$name.json.neu" "$name.json"
}

aufnehmen site "sites/$SITE"
aufnehmen verification "sites/$SITE/verification"
aufnehmen declaration "sites/$SITE/declaration"
aufnehmen scan "scans/$SCAN"
aufnehmen findings "scans/$SCAN/findings"
aufnehmen findings-regel "scans/$SCAN/findings/axe.color-contrast"
aufnehmen path "sites/$SITE/declaration/path"
aufnehmen draft "sites/$SITE/declaration/draft"
aufnehmen applicability "sites/$SITE/applicability"
aufnehmen declaring-party "sites/$SITE/declaring-party"
aufnehmen manual-checks "scans/$SCAN/manual-checks"
