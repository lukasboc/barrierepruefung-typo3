# Lokale TYPO3-Instanzen

Zwei Docker-Instanzen (PHP 8.3, Apache, SQLite) zum Testen der Erweiterung, dazu eine Attrappe der API.

| Fassung | Backend | Frontend | zweite Site |
| --- | --- | --- | --- |
| TYPO3 13.4 | http://localhost:8089/typo3 | http://localhost:8089/ | http://127.0.0.1:8089/ |
| TYPO3 14.3 | http://localhost:8090/typo3 | http://localhost:8090/ | http://127.0.0.1:8090/ |

Zugang: `admin` / `Lokal-Admin-1!`. Die Erweiterung wird als Pfad-Repository (`/ext`, Symlink auf dieses
Repository) eingebunden: Änderungen wirken sofort, nach Änderungen an `Configuration/` hilft
`./lokal.sh typo3 13 cache:flush`.

## Bedienung

```sh
Build/lokal/lokal.sh hoch                   # bauen, starten, nicht eingerichtete Instanzen einrichten (idempotent)
Build/lokal/lokal.sh einrichten 13          # eine Instanz einrichten (bereits eingerichtete bleiben)
Build/lokal/lokal.sh zuruecksetzen [13|14]  # var/, SQLite und Einstellungen löschen, neu einrichten
Build/lokal/lokal.sh typo3 14 <befehl>      # vendor/bin/typo3 im Container
Build/lokal/lokal.sh runter                 # stoppen (Daten bleiben)
cd Build/lokal && docker compose down -v    # aufräumen
```

Jede Instanz hat zwei Sites: `main` in 13.4 bzw. `camino` in 14.3 (Basis `http://localhost:<Port>/`) und `zweite-site` (Basis
`http://127.0.0.1:<Port>/`, Wurzelseite 100 mit drei Unterseiten aus `seed/seiten.sql`).

## Sitepackage je LTS

- **13.4:** `bk2k/bootstrap-package` 16.0.1, Set `bootstrap-package/full`. `typo3 setup --create-site` legt die Site an.
- **14.3:** `typo3/theme-camino`, Set `typo3/theme-camino` (für 14.3 gab es die Wahl nicht zu treffen: das Camino-Theme
  ist das Standard-Sitepackage). Camino ist eine Distribution: `typo3 setup` importiert dafür (mit `typo3/cms-impexp`) seine
  Demo-Site `camino` (Wurzelseite 1) und beachtet `--create-site` nicht; `lokal.sh` stellt deren Basis auf
  `http://localhost:8090/` um. Die Seiten der zweiten Site tragen die Camino-Backend-Layouts, sonst antwortet die Ausgabe mit 500.

## API-Attrappe

```sh
cd Build/e2e && npm ci && npx playwright install chromium
node stub-api.mjs            # http://localhost:8091/api/v1 (aus Docker: http://host.docker.internal:8091/api/v1)
node rauchtest.mjs           # ok v13 / ok v14
```

Schalter: `POST /__stub/ausfall {"an": true}` (alles 503), `POST /__stub/fassung {"version": 5}` (Version der
veröffentlichten Erklärung), `POST /__stub/unbestaetigt {"an": true}` (Website gilt bis zum nächsten `POST …/verify` als nicht bestätigt), `GET /__stub/anfragen` (aufgezeichnete Anfragen mit Kopfzeilen und Rumpf),
`POST /__stub/zuruecksetzen`. Website-Kennung für 13.4: `01STUBSITE0000000000000000` (`base_url` `http://localhost:8089`),
für 14.3: `01STUBSITE0000000000000014` (dieselben Antworten mit `http://localhost:8090`) – der Adressabgleich vergleicht den Port. Die Antworten stehen in `Build/e2e/antworten/` (Herkunft: dort `README.md`).

## Gegen den echten lokalen Dienst

Der Dienst läuft auf `http://127.0.0.1:8001`, aus den Containern erreichbar als
`http://host.docker.internal:8001/api/v1`. Seine Site braucht als `base_url` die der TYPO3-Instanz
(`http://localhost:8089`); anlegen im Dienst-Repository per
`php artisan tinker --execute="require '<datei>';"` (nicht `tinker <datei>`, das hängt). Das Token mit
Plugin-Rechten kommt aus dem Reiter „Einbindung" der Website und gehört nie in eines der Repositorys.

## Wenn die Einrichtung mittendrin abbricht

`lokal.sh` schreibt als letzten Schritt die Marke `v<Fassung>/config/.einrichtung-fertig`. Fehlt sie, gilt die
Instanz als nicht eingerichtet: ein erneutes `./lokal.sh hoch` oder `einrichten <Fassung>` setzt sie mit `--force`
neu auf (Datenbank und Seiten der Instanz gehen dabei verloren). Bei einem Fehler also einfach noch einmal aufrufen;
`zuruecksetzen` räumt zusätzlich `var/` und `config/` vorher ab.
