// Backend-Modul „Barrierefreiheit“ → Übersicht in beiden lokalen Instanzen, gegen die API-Attrappe:
// Site wechseln, verbinden, Domain bestätigen und „Jetzt prüfen“ – alles nur mit Tab und Enter –, dabei an jedem
// Halt prüfen, dass der Fokus sichtbar ist; danach Nachweisdatei und Meta-Element abrufen und Bildschirmfotos im hellen
// und dunklen Schema (Benutzereinstellung „Farbschema“, in 13.4 und 14.3 vorhanden) nach ergebnisse/.
//
//   node stub-api.mjs &   (Attrappe auf :8091)
//   node modul-uebersicht.mjs [v13|v14]
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { anmelden, modulOeffnen, modulRahmen, neuGeladen } from './hilfen.mjs';

const ATTRAPPE = 'http://localhost:8091';
const AUS_DEM_CONTAINER = 'http://host.docker.internal:8091/api/v1';
const ERGEBNISSE = new URL('./ergebnisse/', import.meta.url).pathname;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

const instanzen = [
  { name: 'v13', basis: 'http://localhost:8089', siteId: '01STUBSITE0000000000000000' },
  { name: 'v14', basis: 'http://localhost:8090', siteId: '01STUBSITE0000000000000014' },
].filter((i) => !process.argv[2] || i.name === process.argv[2]);

mkdirSync(ERGEBNISSE, { recursive: true });

async function attrappe(aktion, daten) {
  const antwort = await fetch(`${ATTRAPPE}/__stub/${aktion}`, daten === undefined ? {} : { method: 'POST', body: JSON.stringify(daten) });
  return antwort.json();
}

// Was gerade den Fokus hat (im Modulrahmen), und ob man es sieht.
async function fokus(frame) {
  return frame.evaluate(() => {
    const a = document.activeElement;
    if (!a || a === document.body) return null;
    const s = getComputedStyle(a);
    const outline = s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0;
    const schatten = s.boxShadow !== 'none' && s.boxShadow !== '';
    return {
      beschreibung: `${a.tagName.toLowerCase()}${a.id ? '#' + a.id : ''}${a.name ? '[name=' + a.name + ']' : ''} „${(a.innerText || a.value || a.getAttribute('aria-label') || '').trim().slice(0, 50)}“`,
      text: (a.innerText || '').trim(),
      id: a.id,
      name: a.getAttribute('name') ?? '',
      href: a.getAttribute('href') ?? '',
      form: a.getAttribute('form') ?? '',
      sichtbar: outline || schatten,
      stil: `outline: ${s.outlineStyle} ${s.outlineWidth}; box-shadow: ${s.boxShadow}`,
    };
  });
}

// Drückt Tab, bis passt(fokus) gilt; jeder Halt im Modul muss sichtbar fokussiert sein.
async function tabBis(page, frame, passt, protokoll, max = 60) {
  for (let i = 0; i < max; i++) {
    await page.keyboard.press('Tab');
    const f = await fokus(frame);
    if (f === null) continue;
    protokoll.halte.push(f.beschreibung);
    if (!f.sichtbar) protokoll.unsichtbar.push(`${f.beschreibung} (${f.stil})`);
    if (passt(f)) return f;
  }
  throw new Error('Nach ' + max + ' Tab-Drücken nicht erreicht');
}

// Fokus in den Modulrahmen setzen, ohne zu klicken: das iframe selbst fokussieren, dann führt Tab hinein.
async function inDenRahmen(page) {
  await page.locator('iframe[name="list_frame"]').focus();
}

// Enter auf Link oder Knopf, dann den neu geladenen Modulrahmen.
async function enterUndWarten(page) {
  return neuGeladen(page, () => page.keyboard.press('Enter'));
}

async function farbschema(page, schema) {
  // Derselbe Aufruf wie der Schalter „Farbschema“ im Benutzermenü (uc.colorScheme).
  const status = await page.evaluate(async (s) => {
    const antwort = await fetch(TYPO3.settings.ajaxUrls.color_scheme_update, { method: 'POST', body: new URLSearchParams({ colorScheme: s }) });
    return antwort.status;
  }, schema);
  if (status !== 200) throw new Error(`Farbschema ${schema}: Status ${status}`);
}

const browser = await chromium.launch();
let fehler = 0;
for (const inst of instanzen) {
  const kontext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await kontext.newPage();
  const protokoll = { halte: [], unsichtbar: [] };
  const pruefe = (bedingung, satz) => { if (!bedingung) throw new Error(satz); console.log(`  ok ${satz}`); };
  try {
    console.log(`== ${inst.name}`);
    await attrappe('zuruecksetzen', {});
    await attrappe('unbestaetigt', { an: true });
    await anmelden(page, inst.basis);
    await farbschema(page, 'light');

    let frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_uebersicht');
    const host = new URL(inst.basis).host;

    // Ausgangslage: die Site auf localhost wählen und eine alte Verbindung trennen (Wiederholbarkeit).
    if (!(await frame.locator('h1').innerText()).includes(host)) {
      await frame.locator('.module-docheader .dropdown-toggle').first().click();
      frame = await neuGeladen(page, () => frame.locator(`.dropdown-menu a:has-text("${host}")`).first().click());
      await frame.waitForSelector(`h1:has-text("${host}")`);
    }
    if (await frame.locator('#barrierepruefung-trennen').count()) {
      frame = await neuGeladen(page, () => frame.locator('#barrierepruefung-trennen button').click());
      await frame.waitForSelector('#barrierepruefung-verbinden');
    }
    await page.screenshot({ path: `${ERGEBNISSE}uebersicht-${inst.name}-verbinden-hell.png` });

    // --- Tastatur: Site wechseln (Docheader-Dropdown) und zurück ---
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.text.startsWith('Site:'), protokoll);
    await page.keyboard.press('Enter');
    await tabBis(page, frame, (f) => f.href.includes('site=') && !f.text.includes(host), protokoll, 10);
    frame = await enterUndWarten(page);
    pruefe(!(await frame.locator('h1').innerText()).includes(host), 'Tastatur: andere Site gewählt');
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.text.startsWith('Site:'), protokoll);
    await page.keyboard.press('Enter');
    await tabBis(page, frame, (f) => f.href.includes('site=') && f.text.includes(host), protokoll, 10);
    frame = await enterUndWarten(page);
    pruefe((await frame.locator('h1').innerText()).includes(host), 'Tastatur: zurück auf der Site ' + host);

    // --- Tastatur: Verbinden, erst ohne Token (Fehler am Feld), dann vollständig ---
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.name === 'dienst_url', protokoll);
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type(AUS_DEM_CONTAINER);
    await tabBis(page, frame, (f) => f.name === 'site_id', protokoll, 3);
    await page.keyboard.type(inst.siteId);
    await tabBis(page, frame, (f) => f.text === 'Connect' || f.text === 'Verbinden', protokoll, 6);
    frame = await enterUndWarten(page);
    pruefe(await frame.locator('.callout[role="alert"]').count() === 1, 'ohne Token: Rückmeldung als callout role="alert"');
    pruefe(await frame.locator('#barrierepruefung-token[aria-invalid="true"][aria-describedby~="barrierepruefung-token-fehler"]').count() === 1, 'ohne Token: Feld mit aria-invalid und aria-describedby auf den Fehler');
    pruefe(await frame.locator('#barrierepruefung-site-id').inputValue() === inst.siteId, 'ohne Token: Website-Kennung bleibt im Formular');
    await page.screenshot({ path: `${ERGEBNISSE}uebersicht-${inst.name}-verbinden-fehler-hell.png` });
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.name === 'api_token', protokoll);
    await page.keyboard.type('beliebiges-token-der-attrappe');
    await tabBis(page, frame, (f) => f.text === 'Connect' || f.text === 'Verbinden', protokoll, 3);
    frame = await enterUndWarten(page);
    const meldung = frame.locator('.callout[role="status"]');
    pruefe(await meldung.count() === 1, 'Verbunden: Rückmeldung als callout role="status"');
    console.log(`     „${(await meldung.innerText()).trim()}“`);
    pruefe(await frame.locator('dl').count() === 1, 'Übersicht zeigt den Stand als <dl>');

    // --- Tastatur: Domain bestätigen ---
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.text === 'Confirm domain' || f.text === 'Domain bestätigen', protokoll);
    frame = await enterUndWarten(page);
    pruefe(await frame.locator('.callout[role="status"]').count() === 1, 'Domain bestätigt: Rückmeldung');
    const anfragenVerify = (await attrappe('anfragen')).filter((a) => a.methode === 'POST' && a.pfad.endsWith('/verify'));
    pruefe(anfragenVerify.length >= 1 && JSON.parse(anfragenVerify[0].rumpf).method === 'meta_tag', '„Domain bestätigen“ ruft POST …/verify mit method=meta_tag');

    // Nachweise im Frontend der Instanz.
    const datei = await kontext.request.get(`${inst.basis}/.well-known/a11y-site-verification.txt`);
    pruefe(datei.status() === 200 && (await datei.text()) === 'STUB-PLATZHALTER', 'Nachweisdatei liefert den Datei-Token');
    const start = await (await kontext.request.get(`${inst.basis}/`)).text();
    pruefe(/<meta name="a11y-site-verification" content="STUB-PLATZHALTER">/.test(start), 'Startseite trägt das Meta-Element');

    // --- Tastatur: Jetzt prüfen (Knopf im Docheader) ---
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.form === 'barrierepruefung-pruefen', protokoll);
    const schluessel = await frame.locator('#barrierepruefung-pruefen input[name="idempotency_key"]').getAttribute('value');
    frame = await enterUndWarten(page);
    pruefe(await frame.locator('.callout[role="status"]').count() === 1, 'Jetzt prüfen: Rückmeldung');
    const starts = (await attrappe('anfragen')).filter((a) => a.methode === 'POST' && a.pfad.endsWith('/scans'));
    pruefe(starts.length === 1 && starts[0].kopfzeilen['idempotency-key'] === schluessel && UUID.test(schluessel), 'POST …/scans trägt den Idempotency-Key aus dem Formular');

    pruefe(protokoll.unsichtbar.length === 0, `Fokus an allen ${protokoll.halte.length} Halten sichtbar`);
    if (protokoll.unsichtbar.length) console.log(protokoll.unsichtbar.join('\n'));

    const html = await frame.content();
    pruefe(!/http-equiv="refresh"/i.test(html), 'kein Meta-Refresh');

    await page.screenshot({ path: `${ERGEBNISSE}uebersicht-${inst.name}-hell.png` });
    await farbschema(page, 'dark');
    frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_uebersicht');
    await page.screenshot({ path: `${ERGEBNISSE}uebersicht-${inst.name}-dunkel.png` });
    await farbschema(page, 'auto');
    console.log(`ok ${inst.name}`);
  } catch (e) {
    fehler++;
    console.error(`FEHLER ${inst.name}: ${e.message}`);
    if (protokoll.halte.length) console.error('  Halte: ' + protokoll.halte.slice(-8).join(' → '));
    await page.screenshot({ path: `${ERGEBNISSE}uebersicht-${inst.name}-fehler.png` }).catch(() => {});
  }
  await kontext.close();
}
await browser.close();
process.exit(fehler ? 1 : 0);
