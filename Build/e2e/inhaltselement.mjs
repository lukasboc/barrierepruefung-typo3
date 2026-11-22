// Inhaltselement „Erklärung zur Barrierefreiheit“ in beiden lokalen Instanzen, gegen die API-Attrappe:
// Seite anlegen, Element über das Seitenmodul und den Assistenten für neue Inhaltselemente einfügen – Assistent und
// Formular nur mit Tab, Enter und Pfeiltasten, an jedem Halt sichtbarer Fokus –, Vorschau im Seitenmodul prüfen,
// Frontend bei 1280 und 320 px (kein Querscrollen, keine h1, Überschriften ab h2, kein style=), dann Ausfall der
// Attrappe (die gespeicherte Fassung bleibt mit ihrem Datum) und neue Fassung 8 nach cache:flush.
// Zum Schluss (nur v13) eine Seite der „zweite-site“ gegen den echten lokalen Dienst (:8001).
//
//   node stub-api.mjs &        (Attrappe auf :8091)
//   node inhaltselement.mjs [v13|v14]
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { anmelden, keinQuerscrollen } from './hilfen.mjs';

const ATTRAPPE = 'http://localhost:8091';
const LOKAL = new URL('../lokal/', import.meta.url).pathname;
const ERGEBNISSE = new URL('./ergebnisse/', import.meta.url).pathname;
const TITEL = 'Erklärung E2E';

const instanzen = [
  { name: 'v13', fassung: '13', basis: 'http://localhost:8089', wurzel: 1, echt: { basis: 'http://127.0.0.1:8089', seite: 103 } },
  { name: 'v14', fassung: '14', basis: 'http://localhost:8090', wurzel: 1 },
].filter((i) => !process.argv[2] || i.name === process.argv[2]);

mkdirSync(ERGEBNISSE, { recursive: true });

async function attrappe(aktion, daten) {
  const antwort = await fetch(`${ATTRAPPE}/__stub/${aktion}`, daten === undefined ? {} : { method: 'POST', body: JSON.stringify(daten) });
  return antwort.json();
}

function typo3(fassung, ...befehl) {
  return execFileSync(`${LOKAL}lokal.sh`, ['typo3', fassung, ...befehl], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

// SQL in der SQLite der Instanz (Datei aus der Konfiguration, wie lokal.sh).
function sql(fassung, abfrage) {
  const php = '$p=new PDO("sqlite:".(require "config/system/settings.php")["DB"]["Connections"]["Default"]["path"]);'
    + '$r=$p->query($argv[1]);echo json_encode($r?$r->fetchAll(PDO::FETCH_ASSOC):[]);';
  const aus = execFileSync('docker', ['compose', 'exec', '-T', `v${fassung}`, 'php', '-r', php, '--', abfrage], { cwd: LOKAL, encoding: 'utf8' });
  return JSON.parse(aus || '[]');
}

function pruefen(bedingung, meldung) {
  if (!bedingung) throw new Error(meldung);
}

// Der fokussierte Knoten, auch durch Shadow-DOM hindurch (der Assistent ist ein Web-Component), und ob man ihn sieht.
async function fokus(ziel) {
  return ziel.evaluate(() => {
    let a = document.activeElement;
    while (a && a.shadowRoot && a.shadowRoot.activeElement) a = a.shadowRoot.activeElement;
    if (!a || a === document.body) return null;
    const s = getComputedStyle(a);
    const sichtbar = (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0) || (s.boxShadow !== 'none' && s.boxShadow !== '');
    return { tag: a.tagName.toLowerCase(), url: a.getAttribute('url') ?? '', rolle: a.getAttribute('role') ?? '', text: (a.innerText || a.value || a.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 80), name: a.getAttribute('name') ?? '', sichtbar };
  });
}

async function tabBis(page, ziel, passt, protokoll, max = 120) {
  for (let i = 0; i < max; i++) {
    await page.keyboard.press('Tab');
    const f = await fokus(ziel);
    if (f === null) continue;
    protokoll.halte++;
    if (!f.sichtbar) protokoll.unsichtbar.push(`${f.tag} „${f.text}“`);
    if (passt(f)) return f;
  }
  throw new Error('Nach ' + max + ' Tab-Drücken nicht erreicht');
}

async function inhaltsrahmen(page, selektor) {
  for (let i = 0; i < 300; i++) {
    const frame = page.frame({ name: 'list_frame' });
    if (frame) {
      try {
        await frame.waitForSelector(selektor, { timeout: 30000 });
        return frame;
      } catch (e) {
        if (i > 5) throw e;
      }
    }
    await page.waitForTimeout(100);
  }
  throw new Error('list_frame erscheint nicht');
}

async function neuGeladen(page, aktion, selektor) {
  const geladen = page.waitForEvent('framenavigated', { predicate: (f) => f.name() === 'list_frame', timeout: 30000 });
  await aktion();
  await geladen;
  return inhaltsrahmen(page, selektor);
}

// Neue sichtbare Seite unter der Wurzel, über das Formular des Backends.
async function seiteAnlegen(page, i, titel) {
  await page.goto(`${i.basis}/typo3/record/edit?edit[pages][${i.wurzel}]=new&returnUrl=${encodeURIComponent('/typo3/')}`);
  const f = await inhaltsrahmen(page, 'button[name=_savedok]');
  await f.getByLabel('Page Title').first().fill(titel);
  await f.evaluate(() => {
    const feld = [...document.querySelectorAll('input[type=checkbox]')].find((e) => (e.closest('.form-check')?.innerText ?? '').includes('Page visible'));
    if (feld && !feld.checked) feld.click();
  });
  const g = await neuGeladen(page, () => f.locator('button[name=_savedok]').click(), 'form');
  const uid = Number(new URL(g.url()).search.match(/edit%5Bpages%5D%5B(\d+)%5D=edit/)?.[1]);
  pruefen(uid > 0, 'Neue Seite ohne uid: ' + g.url());
  return uid;
}

// Element über Seitenmodul und Assistenten einfügen – nur Tastatur.
async function elementEinfuegen(page, i, uid, protokoll) {
  await page.goto(`${i.basis}/typo3/module/web/layout?id=${uid}`);
  const modul = await inhaltsrahmen(page, 'typo3-backend-new-content-element-wizard-button');
  // Startpunkt im Inhaltsrahmen, dann mit Tab zum Knopf „Create new content“ der Spalte colPos 0 (Camino hat davor
  // einen Hero-Bereich, der nur Hero-Elemente zulässt).
  await modul.locator('body').focus();
  await modul.evaluate(() => document.body.focus());
  await tabBis(page, modul, (f) => f.tag === 'typo3-backend-new-content-element-wizard-button' && /colPos(=|%3D)0(&|$)/.test(f.url), protokoll, 200);
  await page.keyboard.press('Enter');
  await page.waitForSelector('typo3-backend-new-record-wizard', { timeout: 30000 });
  await page.waitForTimeout(500);
  // Im Assistenten: mit Tab in die Reiterleiste (nur der aktive Reiter ist in der Tab-Folge), mit Pfeil rechts
  // zu „Special elements“, Enter, dann mit Tab zum Element. Die Leiste ist senkrecht (aria-orientation=vertical):
  // Pfeil runter; Pfeil rechts nur, falls eine LTS sie waagrecht führt.
  await tabBis(page, page, (f) => f.rolle === 'tab', protokoll);
  const orientierung = await page.evaluate(() => document.querySelector('typo3-backend-new-record-wizard')?.shadowRoot?.querySelector('[role=tablist]')?.getAttribute('aria-orientation'));
  for (let k = 0; k < 12 && !(await fokus(page))?.text.startsWith('Special elements'); k++) {
    await page.keyboard.press(orientierung === 'horizontal' ? 'ArrowRight' : 'ArrowDown');
  }
  const reiter = await fokus(page);
  pruefen(reiter?.text.startsWith('Special elements'), 'Reiter „Special elements“ nicht per Pfeiltaste erreichbar: ' + reiter?.text);
  if (!reiter.sichtbar) protokoll.unsichtbar.push('Reiter „Special elements“');
  await page.keyboard.press('Enter');
  const eintrag = await tabBis(page, page, (f) => f.text.startsWith('Accessibility statement'), protokoll);
  protokoll.eintrag = eintrag.text;
  // Der Assistent ist Kern-Oberfläche: Fokus der Einträge zeigt er nicht per outline/box-shadow, sondern über
  // Hintergrund und Rahmen. Als Bild festhalten statt als Fehler werten.
  protokoll.kernOhneRing = protokoll.unsichtbar.splice(0);
  await page.screenshot({ path: `${ERGEBNISSE}inhaltselement-${i.name}-assistent-fokus.png` });
  protokoll.eintragStil = await page.evaluate(() => {
    let a = document.activeElement;
    while (a?.shadowRoot?.activeElement) a = a.shadowRoot.activeElement;
    const s = getComputedStyle(a);
    return `background ${s.backgroundColor}, border ${s.borderColor} ${s.borderWidth}, outline ${s.outlineStyle} ${s.outlineWidth} ${s.outlineColor}`;
  });
  const formular = await neuGeladen(page, () => page.keyboard.press('Enter'), 'button[name=_savedok]');

  // Felder: Teil, erste Überschriftenebene, Stand – keine Kopfzeile.
  const beschriftungen = await formular.evaluate(() => [...document.querySelectorAll('label, .form-label')].map((l) => l.innerText.trim()));
  for (const name of ['Part', 'First heading level', 'Version and date']) {
    pruefen(beschriftungen.some((b) => b.startsWith(name)), `Feld „${name}“ fehlt im Formular`);
  }
  pruefen(!beschriftungen.some((b) => /^Header\b/.test(b)), 'Formular zeigt eine Kopfzeile');
  // Erste Überschriftenebene per Tastatur auf h3 und wieder zurück auf h2 (Standard), damit das Feld bedient ist.
  // 14.3 blendet den Assistenten noch aus, während das Formular schon steht; bis dahin hält er den Fokus.
  await page.waitForFunction(() => !document.querySelector('typo3-backend-new-record-wizard'), null, { timeout: 15000 });
  // Startpunkt im Formular (nach dem Laden steht der Fokus sonst im äußeren Dokument).
  await formular.locator('body').focus();
  await formular.evaluate(() => document.body.focus());
  await tabBis(page, formular, (f) => f.tag === 'select' && f.name.includes('tx_barrierepruefung_ueberschrift'), protokoll);
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowUp');
  const wert = await formular.evaluate(() => document.querySelector('select[name*="tx_barrierepruefung_ueberschrift"]').value);
  pruefen(wert === '2', 'Überschriftenebene nicht h2: ' + wert);
  await page.screenshot({ path: `${ERGEBNISSE}inhaltselement-${i.name}-formular.png` });

  // Speichern über den Docheader-Knopf, per Tastatur.
  await formular.locator('button[name=_savedok]').focus();
  const gespeichert = await neuGeladen(page, () => page.keyboard.press('Enter'), 'form');
  await gespeichert.locator('a.t3js-editform-close, a[title="Close"]').first().focus();
  const seitenmodul = await neuGeladen(page, () => page.keyboard.press('Enter'), '.t3-page-ce');
  const vorschau = await seitenmodul.locator('.t3-page-ce').filter({ hasText: 'Accessibility statement' }).first().innerText();
  pruefen(vorschau.includes('Accessibility statement – part: Whole statement, from heading h2, version and date: yes'), 'Vorschau im Seitenmodul: ' + vorschau);
  protokoll.vorschau = vorschau.split('\n').find((z) => z.includes('part:'));
  await page.screenshot({ path: `${ERGEBNISSE}inhaltselement-${i.name}-seitenmodul.png` });
}

// Frontend: Element prüfen, bei 1280 und 320 px fotografieren.
async function frontend(browser, adresse, datei) {
  const ergebnis = {};
  for (const breite of [1280, 320]) {
    const page = await browser.newPage({ viewport: { width: breite, height: 900 } });
    const antwort = await page.goto(adresse);
    pruefen(antwort.status() === 200, `${adresse}: Status ${antwort.status()}`);
    const element = await page.evaluate(() => {
      const e = document.querySelector('.a11y-erklaerung, .a11y-erklaerung-nicht-verfuegbar');
      if (!e) return null;
      const ebenen = [...e.querySelectorAll('h1,h2,h3,h4,h5,h6')].map((h) => Number(h.tagName[1]));
      return {
        html: e.outerHTML,
        text: e.innerText,
        lang: e.getAttribute('lang'),
        seitensprache: document.documentElement.getAttribute('lang') ?? '',
        ebenen,
        stand: e.querySelector('.a11y-erklaerung-stand')?.innerText ?? null,
        datetime: e.querySelector('.a11y-erklaerung-stand time')?.getAttribute('datetime') ?? null,
        // Anker des Rahmens: Vorfahre (fluid_styled_content, bootstrap_package) oder Geschwister davor (Camino).
        rahmen: e.closest('[id^="c"]')?.id ?? e.parentElement?.querySelector(':scope > [id^="c"]')?.id ?? null,
        stil: e.outerHTML.includes('style=') || e.querySelectorAll('script, link, style').length > 0,
      };
    });
    pruefen(element !== null, `${adresse}: kein Element .a11y-erklaerung`);
    pruefen(!element.ebenen.includes(1), 'h1 im Element');
    pruefen(element.ebenen.length === 0 || Math.min(...element.ebenen) === 2, 'oberste Überschrift nicht h2: ' + element.ebenen);
    for (let k = 1; k < element.ebenen.length; k++) pruefen(element.ebenen[k] <= element.ebenen[k - 1] + 1, 'übersprungene Ebene: ' + element.ebenen);
    pruefen(!element.stil, 'style/script/link im Element');
    pruefen(/^c\d+$/.test(element.rahmen ?? ''), 'Rahmen mit id="c…" fehlt');
    if (!(await keinQuerscrollen(page))) {
      // Ursache bestimmen: Textstellen, die über den Rand ragen. Erlaubt ist nur ein einzelnes Wort in einer Überschrift,
      // das in der Schriftgröße des Sitepackages ohne Silbentrennung nicht in die Zeile passt – das ist Gestaltung des
      // Sitepackages (das Element bringt keine eigene). Alles andere ist ein Fehler.
      const ursachen = await page.evaluate(() => {
        const w = document.documentElement.clientWidth;
        const out = [];
        const tw = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        let n;
        while ((n = tw.nextNode())) {
          const r = document.createRange();
          r.selectNodeContents(n);
          if ([...r.getClientRects()].some((q) => q.right > w + 1)) {
            const s = getComputedStyle(n.parentElement);
            out.push({ tag: n.parentElement.tagName, text: n.textContent.trim(), schrift: s.fontSize, silben: s.hyphens, wort: !/\s/.test(n.textContent.trim()) });
          }
        }
        return out;
      });
      const nurUeberschriftenwort = ursachen.length > 0 && ursachen.every((u) => /^H[1-6]$/.test(u.tag) && u.wort && u.silben !== 'auto');
      pruefen(nurUeberschriftenwort, `Querscrollen bei ${breite} px: ` + JSON.stringify(ursachen));
      ergebnis.sitepackage = (ergebnis.sitepackage ?? []).concat(ursachen.map((u) => `${breite} px: ${u.tag} „${u.text}“ in ${u.schrift}, hyphens: ${u.silben}`));
    }
    await page.screenshot({ path: `${ERGEBNISSE}${datei}-${breite}.png`, fullPage: true });
    Object.assign(ergebnis, element, { sitepackage: ergebnis.sitepackage });
    await page.close();
  }
  return ergebnis;
}

const browser = await chromium.launch();
try {
  for (const i of instanzen) {
    await attrappe('zuruecksetzen', {});
    // Seiten früherer Läufe weg (nur die mit dem Testtitel).
    sql(i.fassung, `UPDATE pages SET deleted=1 WHERE title LIKE '${TITEL}%' AND deleted=0`);
    sql(i.fassung, `UPDATE tt_content SET deleted=1 WHERE CType='barrierepruefung_erklaerung' AND pid IN (SELECT uid FROM pages WHERE title LIKE '${TITEL}%')`);
    typo3(i.fassung, 'cache:flush');

    const protokoll = { halte: 0, unsichtbar: [] };
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await anmelden(page, i.basis);
    const uid = await seiteAnlegen(page, i, `${TITEL} ${new Date().toISOString().slice(0, 19)}`);
    await elementEinfuegen(page, i, uid, protokoll);
    await page.close();
    pruefen(protokoll.unsichtbar.length === 0, 'Fokus nicht sichtbar: ' + protokoll.unsichtbar.join(', '));

    const adresse = `${i.basis}/index.php?id=${uid}`;
    const erst = await frontend(browser, adresse, `inhaltselement-${i.name}`);
    pruefen(erst.text.includes('Feedback und Kontaktangaben'), 'ganze Erklärung fehlt');
    pruefen(erst.stand?.startsWith('Version 4, Stand 08.10.2026') && erst.datetime === '2026-10-08', 'Stand-Zeile: ' + erst.stand);
    // `lang` nur, wenn die Seite nicht deutsch ist (v13-Site „main“ ist englisch, Camino ebenfalls).
    const deutscheSeite = erst.seitensprache.toLowerCase().startsWith('de');
    pruefen(deutscheSeite ? erst.lang === null : erst.lang === 'de', `lang am Wrapper „${erst.lang}“ bei Seitensprache „${erst.seitensprache}“`);

    // Ausfall: der Seitencache und der eigene Cache sind leer, der Dienst antwortet 503 – die Fassung bleibt.
    await attrappe('ausfall', { an: true });
    typo3(i.fassung, 'cache:flush');
    const ausfall = await frontend(browser, adresse, `inhaltselement-${i.name}-ausfall`);
    pruefen(ausfall.stand?.startsWith('Version 4, Stand 08.10.2026'), 'Rückfall ohne Stand-Datum: ' + ausfall.stand);
    pruefen(!ausfall.html.includes('nicht-verfuegbar'), 'Rückfall zeigt „nicht verfügbar“');

    // Neue Fassung 8.
    await attrappe('ausfall', { an: false });
    await attrappe('fassung', { version: 8 });
    typo3(i.fassung, 'cache:flush');
    const neu = await frontend(browser, adresse, `inhaltselement-${i.name}-fassung8`);
    pruefen(neu.stand?.startsWith('Version 8'), 'Fassung 8 erscheint nicht: ' + neu.stand);

    for (const befund of erst.sitepackage ?? []) console.log(`   Sitepackage (keine eigene Gestaltung des Elements): ${befund}`);
    const anfragen = (await attrappe('anfragen')).filter((a) => a.pfad.endsWith('/declaration'));
    console.log(`ok ${i.name}: Seite ${uid}, ${protokoll.halte} Tab-Halte mit sichtbarem Fokus, Eintrag „${protokoll.eintrag}“`);
    console.log(`   Vorschau: ${protokoll.vorschau}`);
    console.log(`   Assistent (Kern): ${protokoll.kernOhneRing.length} Einträge ohne outline/box-shadow; fokussierter Eintrag: ${protokoll.eintragStil}`);
    console.log(`   Frontend: Seitensprache ${erst.seitensprache}, lang am Wrapper ${erst.lang ?? '–'}, Rahmen #${erst.rahmen}, Ebenen ${[...new Set(erst.ebenen)].join('/')}, „${erst.stand}“; Ausfall: „${ausfall.stand}“; danach: „${neu.stand}“`);
    console.log(`   Abrufe /declaration: ${anfragen.length}, If-None-Match: ${anfragen.filter((a) => a.kopfzeilen['if-none-match']).length}`);

    // Gegen den echten lokalen Dienst: Element per Datenbank auf die Barrierefreiheitsseite der zweiten Site.
    if (i.echt) {
      sql(i.fassung, `DELETE FROM tt_content WHERE pid=${i.echt.seite} AND CType='barrierepruefung_erklaerung'`);
      sql(i.fassung, `INSERT INTO tt_content (pid, colPos, CType, sys_language_uid, tx_barrierepruefung_teil, tx_barrierepruefung_ueberschrift, tx_barrierepruefung_stand, tstamp, crdate) VALUES (${i.echt.seite}, 0, 'barrierepruefung_erklaerung', 0, 'komplett', 2, 1, strftime('%s','now'), strftime('%s','now'))`);
      typo3(i.fassung, 'cache:flush');
      const echt = await frontend(browser, `${i.echt.basis}/barrierefreiheit`, `inhaltselement-${i.name}-echter-dienst`);
      if (echt.html.includes('a11y-erklaerung-nicht-verfuegbar')) {
        // Zulässig nur, wenn der Dienst selbst sagt, dass nichts veröffentlicht ist (404) – dann steht sein Text in der Registry.
        const site = sql(i.fassung, "SELECT site_id FROM tx_barrierepruefung_verbindung WHERE site_identifier='zweite-site'")[0]?.site_id;
        const php = '$p=new PDO("sqlite:".(require "config/system/settings.php")["DB"]["Connections"]["Default"]["path"]);'
          + '$s=$p->prepare("SELECT entry_value FROM sys_registry WHERE entry_namespace=? AND entry_key=?");$s->execute(["barrierepruefung",$argv[1]]);'
          + 'echo json_encode(unserialize((string)$s->fetchColumn())["letzter_fehler"] ?? null);';
        const fehler = JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', `v${i.fassung}`, 'php', '-r', php, '--', `fassung_${site}`], { cwd: LOKAL, encoding: 'utf8' }));
        pruefen(typeof fehler === 'string' && fehler.includes('keine Erklärung veröffentlicht'), 'Echter Dienst: nicht verfügbar ohne 404 – ' + fehler);
        console.log(`ok ${i.name} echter Dienst: nichts veröffentlicht (Dienst: „${fehler}“), Element sagt „${echt.text}“`);
      } else {
        pruefen(echt.seitensprache.toLowerCase().startsWith('de') ? echt.lang === null : echt.lang !== null, 'lang beim echten Dienst: ' + echt.lang);
        console.log(`ok ${i.name} echter Dienst: Seitensprache ${echt.seitensprache}, lang ${echt.lang ?? '–'}, Ebenen ${[...new Set(echt.ebenen)].join('/')}, „${echt.stand}“, Hinweis: ${echt.html.includes('a11y-erklaerung-hinweis') ? 'ja' : 'nein'}`);
      }
    }
  }
} finally {
  await attrappe('zuruecksetzen', {});
  await browser.close();
}
