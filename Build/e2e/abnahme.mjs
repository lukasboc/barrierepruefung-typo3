// Abnahme gegen den ECHTEN Dienst (nicht die Attrappe), je Instanz und LTS:
// verbinden, Domain bestätigen, „Jetzt prüfen“ (Lauf im Dienst), veröffentlichte Erklärung im Inhaltselement,
// Rückfall bei nicht erreichbarem Dienst.
//
// Zugangsdaten nur aus der Umgebung, nie aus dem Repository:
//   ABNAHME_TOKEN      Token der Website mit Plugin-Rechten (Reiter „Einbindung“ der Website im Dienst)
//   ABNAHME_SITE_ID    Kennung der Website im Dienst (ULID)
//   ABNAHME_DIENST     Adresse der API von diesem Rechner aus (Standard http://127.0.0.1:8001/api/v1)
//   ABNAHME_WARTEN     Sekunden, die auf das Ende des Laufs gewartet wird (Standard 0: Lauf nur anlegen lassen)
//
//   ABNAHME_TOKEN=… ABNAHME_SITE_ID=… node abnahme.mjs v13|v14
//
// Die Instanz erreicht den Dienst über einen kleinen Durchreicher auf Port 8092 (aus Docker:
// host.docker.internal). So lässt sich der Ausfall nachstellen, ohne den Dienst anzuhalten: Der Durchreicher
// wird geschlossen. Die Dienst-Website trägt als Adresse http://127.0.0.1:<Port der Instanz>.
import { chromium } from 'playwright';
import net from 'node:net';
import { execFile, execFileSync } from 'node:child_process';
import { promisify } from 'node:util';
import { mkdirSync } from 'node:fs';
import { anmelden, modulOeffnen, neuGeladen } from './hilfen.mjs';

const ERGEBNISSE = new URL('./ergebnisse/', import.meta.url).pathname;
const LOKAL = new URL('../lokal/', import.meta.url).pathname;
const DURCHREICHER = 8092;

const token = process.env.ABNAHME_TOKEN;
const siteId = process.env.ABNAHME_SITE_ID;
const dienst = (process.env.ABNAHME_DIENST ?? 'http://127.0.0.1:8001/api/v1').replace(/\/$/, '');
const warten = Number(process.env.ABNAHME_WARTEN ?? 0);
if (!token || !siteId) {
  console.error('ABNAHME_TOKEN und ABNAHME_SITE_ID müssen gesetzt sein (Umgebungsvariablen, nie im Repository).');
  process.exit(2);
}

const instanzen = {
  v13: { name: 'v13', fassung: '13', basis: 'http://localhost:8089', echt: 'http://127.0.0.1:8089', seite: 103, host: '127.0.0.1:8089' },
  v14: { name: 'v14', fassung: '14', basis: 'http://localhost:8090', echt: 'http://127.0.0.1:8090', seite: 103, host: '127.0.0.1:8090' },
};
const inst = instanzen[process.argv[2]];
if (!inst) {
  console.error('Aufruf: node abnahme.mjs v13|v14');
  process.exit(2);
}
mkdirSync(ERGEBNISSE, { recursive: true });

const pruefe = (bedingung, satz) => {
  if (!bedingung) throw new Error(satz);
  console.log(`  ok ${satz}`);
};
// Asynchron, nicht execFileSync: der Durchreicher läuft in diesem Prozess, und ein blockierter Ereignisfluss ließe den
// Befehl in der Instanz ins Leere fragen (Zeitlimit 3 s, Rückfall).
const typo3 = async (...befehl) => (await promisify(execFile)(`${LOKAL}lokal.sh`, ['typo3', inst.fassung, ...befehl], { encoding: 'utf8' })).stdout;
const sql = (abfrage) => {
  const php = '$p=new PDO("sqlite:".(require "config/system/settings.php")["DB"]["Connections"]["Default"]["path"]);$p->exec($argv[1]);';
  execFileSync('docker', ['compose', 'exec', '-T', `v${inst.fassung}`, 'php', '-r', php, '--', abfrage], { cwd: LOKAL, encoding: 'utf8' });
};

// Aufruf der API des Dienstes von diesem Rechner aus, mit demselben Token (nur lesend, zum Gegenprüfen).
async function api(pfad) {
  const antwort = await fetch(`${dienst}${pfad}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
  return { status: antwort.status, daten: antwort.status === 204 ? null : await antwort.json().catch(() => null) };
}

// Durchreicher zum Dienst: einfaches TCP, damit der Ausfall ohne Eingriff in den Dienst nachstellbar ist.
const ziel = new URL(dienst);
const verbindungen = new Set();
const durchreicher = net.createServer((aus) => {
  const ein = net.connect(Number(ziel.port || 80), ziel.hostname);
  verbindungen.add(aus).add(ein);
  aus.pipe(ein).pipe(aus);
  const zu = () => { aus.destroy(); ein.destroy(); verbindungen.delete(aus); verbindungen.delete(ein); };
  aus.on('error', zu).on('close', zu);
  ein.on('error', zu).on('close', zu);
});
await new Promise((ok) => durchreicher.listen(DURCHREICHER, '0.0.0.0', ok));
const schliesseDurchreicher = () => new Promise((ok) => {
  durchreicher.close(ok);
  for (const v of verbindungen) v.destroy();
});

const browser = await chromium.launch();
const kontext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await kontext.newPage();
let fehler = 0;
try {
  console.log(`== Abnahme ${inst.name} gegen ${dienst} (Website ${siteId})`);
  const vorher = await api(`/sites/${siteId}`);
  pruefe(vorher.status === 200, `Dienst antwortet auf GET /sites/{id} mit 200 (${vorher.status})`);
  pruefe(vorher.daten.data.base_url.replace(/\/$/, '') === inst.echt, `Website im Dienst trägt die Adresse ${inst.echt}`);

  // --- Verbinden ---
  await anmelden(page, inst.basis);
  // Die zweite Site der Instanz (Adresse 127.0.0.1:<Port>) über den Parameter wählen, nicht über das Docheader-Menü.
  let frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_uebersicht', { site: 'zweite-site' });
  pruefe((await frame.locator('h1').innerText()).includes(inst.host), `Modul zeigt die Site ${inst.host}`);
  if (await frame.locator('#barrierepruefung-trennen').count()) {
    frame = await neuGeladen(page, () => frame.locator('#barrierepruefung-trennen button').click());
    await frame.waitForSelector('#barrierepruefung-verbinden');
  }
  await frame.fill('#barrierepruefung-dienst-url', `http://host.docker.internal:${DURCHREICHER}/api/v1`);
  await frame.fill('#barrierepruefung-site-id', siteId);
  await frame.fill('#barrierepruefung-token', token);
  frame = await neuGeladen(page, () => frame.locator('#barrierepruefung-verbinden button[type=submit]').click());
  await frame.waitForSelector('.callout');
  pruefe((await frame.locator('.callout[role="status"]').count()) === 1, `Verbinden: ${(await frame.locator('.callout').first().innerText()).trim()}`);
  pruefe((await frame.locator('dl').count()) === 1, 'Übersicht zeigt den Stand der Verbindung');

  // --- Domain bestätigen (erst Meta-Element, dann Datei; der Dienst liest beides von der Instanz) ---
  if (await frame.locator('#barrierepruefung-bestaetigen').count()) {
    frame = await neuGeladen(page, () => frame.locator('#barrierepruefung-bestaetigen button').click());
    await frame.waitForSelector('.callout');
    console.log(`     „${(await frame.locator('.callout').first().innerText()).trim()}“`);
  }
  const nachBestaetigen = await api(`/sites/${siteId}`);
  pruefe(nachBestaetigen.daten.data.verified === true, 'Dienst führt die Website als bestätigt (verified: true)');
  await page.screenshot({ path: `${ERGEBNISSE}abnahme-${inst.name}-bestaetigt.png` });

  // --- Jetzt prüfen: ein Lauf im Dienst ---
  const lauefeVorher = new Set([nachBestaetigen.daten.data.latest_scan?.id, nachBestaetigen.daten.data.running_scan?.id].filter(Boolean));
  const schluessel = await frame.locator('#barrierepruefung-pruefen input[name="idempotency_key"]').getAttribute('value');
  frame = await neuGeladen(page, () => frame.locator('button[form="barrierepruefung-pruefen"]').first().click());
  await frame.waitForSelector('.callout');
  console.log(`     „${(await frame.locator('.callout').first().innerText()).trim()}“ (Idempotency-Key ${schluessel})`);
  let lauf = (await api(`/sites/${siteId}`)).daten.data;
  const neuerLauf = lauf.running_scan?.id ?? (lauf.latest_scan && !lauefeVorher.has(lauf.latest_scan.id) ? lauf.latest_scan.id : null);
  pruefe(neuerLauf !== null || lauefeVorher.size > 0, `Dienst führt einen Lauf (neu: ${neuerLauf ?? 'keiner, ein früherer läuft oder ist fertig'}; Status ${lauf.running_scan?.status ?? lauf.latest_scan?.status})`);
  for (let t = 0; t < warten && lauf.running_scan; t += 5) {
    await new Promise((ok) => setTimeout(ok, 5000));
    lauf = (await api(`/sites/${siteId}`)).daten.data;
  }
  if (warten) console.log(`     Lauf nach Wartezeit: ${JSON.stringify(lauf.running_scan ?? lauf.latest_scan)}`);

  // --- Veröffentlichte Erklärung im Inhaltselement ---
  const erklaerung = await api(`/sites/${siteId}/declaration`);
  pruefe(erklaerung.status === 200, `Der Dienst hat für die Website eine veröffentlichte Erklärung (GET …/declaration: ${erklaerung.status}); sonst vorher im Dienst veröffentlichen`);
  const fassung = erklaerung.daten.declaration.version;
  sql(`DELETE FROM tt_content WHERE pid=${inst.seite} AND CType='barrierepruefung_erklaerung'`);
  sql(`INSERT INTO tt_content (pid, colPos, CType, sys_language_uid, tx_barrierepruefung_teil, tx_barrierepruefung_ueberschrift, tx_barrierepruefung_stand, tstamp, crdate) VALUES (${inst.seite}, 0, 'barrierepruefung_erklaerung', 0, 'komplett', 2, 1, strftime('%s','now'), strftime('%s','now'))`);
  console.log(`     ${(await typo3('barrierepruefung:aktualisieren')).trim().replace(/\n/g, ' | ')}`);
  await typo3('cache:flush');
  const lesen = async () => {
    const seite = await kontext.newPage();
    const antwort = await seite.goto(`${inst.echt}/barrierefreiheit`);
    const element = await seite.evaluate(() => {
      const e = document.querySelector('.a11y-erklaerung');
      return e ? { text: e.innerText, ebenen: [...e.querySelectorAll('h1,h2,h3,h4,h5,h6')].map((h) => Number(h.tagName[1])), stand: e.querySelector('.a11y-erklaerung-stand time')?.getAttribute('datetime') ?? null } : null;
    });
    await seite.screenshot({ path: `${ERGEBNISSE}abnahme-${inst.name}-frontend-${lesen.zaehler++}.png`, fullPage: true });
    await seite.close();
    return { status: antwort.status(), element };
  };
  lesen.zaehler = 0;
  const frisch = await lesen();
  pruefe(frisch.status === 200 && frisch.element !== null, 'Frontend zeigt .a11y-erklaerung');
  pruefe(frisch.element.text.length > 500, `Element trägt den Text der Erklärung (Fassung ${fassung}, ${frisch.element.text.length} Zeichen)`);
  pruefe(!frisch.element.ebenen.includes(1) && Math.min(...frisch.element.ebenen) === 2, `Überschriften ab h2 (${[...new Set(frisch.element.ebenen)].join('/')})`);
  pruefe(frisch.element.stand !== null, `Stand-Zeile mit Datum (${frisch.element.stand})`);

  // --- Rückfall: Dienst für die Instanz nicht erreichbar ---
  await schliesseDurchreicher();
  console.log(`     ${(await typo3('barrierepruefung:aktualisieren')).trim().replace(/\n/g, ' | ')}`);
  await typo3('cache:flush');
  const ausfall = await lesen();
  pruefe(ausfall.status === 200 && ausfall.element !== null, 'Dienst nicht erreichbar: Seite antwortet 200 und zeigt weiter die Erklärung');
  pruefe(ausfall.element.text === frisch.element.text && ausfall.element.stand === frisch.element.stand, 'Dienst nicht erreichbar: derselbe Text, dasselbe Datum wie vorher');
  console.log(`ok ${inst.name}`);
} catch (e) {
  fehler++;
  console.error(`FEHLER ${inst.name}: ${e.message}`);
  await page.screenshot({ path: `${ERGEBNISSE}abnahme-${inst.name}-fehler.png` }).catch(() => {});
}
await schliesseDurchreicher().catch(() => {});
await browser.close();
process.exit(fehler ? 1 : 0);
