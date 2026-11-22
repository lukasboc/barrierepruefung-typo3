# Aufgenommene Antworten der API-Attrappe

Die Dateien sind GET-Antworten des lokalen Dienstes (`http://127.0.0.1:8001/api/v1`), bereinigt:
Website-Kennung `01STUBSITE0000000000000000`, Prüflauf `01STUBSCAN0000000000000000`,
`base_url` `http://localhost:8089`, Dienst-Adresse `https://dienst.example`, keine Token,
keine echten Adressen oder Namen. `scan-start.json`, `verify.json` und `publish.json` sind von Hand nach
`docs/08` des Dienstes geschrieben (`"_von_hand": true`): echte Aufrufe würden den Dienst verändern.

## Neu aufnehmen

Mit einem Token der Website (Reiter „Einbindung", Plugin-Rechte):

```sh
cd Build/e2e/antworten
TOKEN=… SITE=<Kennung der Website> SCAN=<Kennung des letzten Laufs> ./aufnehmen.sh [http://127.0.0.1:8001/api/v1]
```

`aufnehmen.sh` holt jede Antwort und leitet sie durch `bereinigen.mjs`: Kennungen, Adressen, Verifikationswerte,
Namen und der Text der geprüften Fremdseite (HTML-Ausschnitte der Befunde) werden ersetzt, die Form bleibt. Das Skript
bricht ab, wenn danach ein anderer Host als `localhost`, `127.0.0.1`, `beispiel.test`, `dienst.example` (und die
öffentliche Stelle im Vorlagentext) oder eine andere Adresse als `…@beispiel.test` übrig ist. Die geprüfte Website
im Dienst sollte `base_url` `http://localhost:8089` tragen; ein Fremdhost kommt nie in dieses Repository.

Nach Aufgabe 3 (Block `data.token` an `sites/{id}`) ist `site.json` mit demselben Befehl neu aufzunehmen.
