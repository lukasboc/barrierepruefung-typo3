// Backend-Modul „Barrierefreiheit“ → Befunde in beiden lokalen Instanzen, gegen die API-Attrappe:
// Untermodul über das Menü im Docheader wählen, Regel öffnen, „Weitere laden“, zurück zur Liste – alles nur mit Tab,
// Pfeiltasten und Enter –, an jedem Halt prüfen, dass der Fokus sichtbar ist; axe-core über den Modulrahmen;
// Bildschirmfotos hell und dunkel nach ergebnisse/.
//
//   node stub-api.mjs &   (Attrappe auf :8091)
//   node modul-befunde.mjs [v13|v14]
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { anmelden, attrappe, AUS_DEM_CONTAINER, fokus, tabBis, inDenRahmen, enterUndWarten, farbschema, axeModul, modulOeffnen, neuGeladen, keinQuerscrollen } from './hilfen.mjs';

const ERGEBNISSE = new URL('./ergebnisse/', import.meta.url).pathname;

const instanzen = [
  { name: 'v13', basis: 'http://localhost:8089', siteId: '01STUBSITE0000000000000000' },
  { name: 'v14', basis: 'http://localhost:8090', siteId: '01STUBSITE0000000000000014' },
].filter((i) => !process.argv[2] || i.name === process.argv[2]);

mkdirSync(ERGEBNISSE, { recursive: true });

const zaehler = (frame) => frame.locator('p', { hasText: /of 267|von 267/ }).first().innerText();
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
    await anmelden(page, inst.basis);
    await farbschema(page, 'light');
    const host = new URL(inst.basis).host;

    // Ausgangslage: Site auf localhost, verbunden mit der Attrappe (mit der Maus, das ist nicht der Prüfgegenstand).
    let frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_uebersicht');
    if (!(await frame.locator('h1').innerText()).includes(host)) {
      await frame.locator('.module-docheader .dropdown-toggle').first().click();
      frame = await neuGeladen(page, () => frame.locator(`.dropdown-menu a:has-text("${host}")`).first().click());
    }
    if (await frame.locator('#barrierepruefung-trennen').count()) {
      frame = await neuGeladen(page, () => frame.locator('#barrierepruefung-trennen button').click());
    }
    await frame.fill('#barrierepruefung-dienst-url', AUS_DEM_CONTAINER);
    await frame.fill('#barrierepruefung-site-id', inst.siteId);
    await frame.fill('#barrierepruefung-token', 'beliebiges-token-der-attrappe');
    frame = await neuGeladen(page, () => frame.locator('#barrierepruefung-verbinden button[type=submit]').click());
    pruefe(await frame.locator('dl').count() === 1, 'verbunden, Übersicht zeigt den Stand');
    pruefe(await frame.locator('a:has-text("findings"), a:has-text("Befunde")').count() >= 1, 'Übersicht verlinkt die Befunde');

    // --- Tastatur: Untermodul über das Menü im Docheader wählen ---
    await inDenRahmen(page);
    // 13.4: Select „moduleMenu“ im Docheader; 14.3: Knopf „Module action“, der das Menü der Untermodule öffnet.
    const menue = await tabBis(page, frame, (f) => f.id === 'moduleMenu' || (f.tag === 'button' && /^(Module action|Modulaktion)/.test(f.text)), protokoll);
    if (menue.id === 'moduleMenu') {
      pruefe(true, 'Untermodul-Menü per Tab erreicht (Select #moduleMenu)');
      // Das Auswählen im geöffneten Native-Select ist Sache des Browsers; Chromium headless auf macOS bedient es nicht mit
      // Pfeiltasten (Wert bleibt). selectOption löst dieselben input-/change-Ereignisse aus wie die Wahl der Person.
      frame = await neuGeladen(page, () => frame.locator('#moduleMenu').selectOption({ index: 1 }));
    } else {
      pruefe(true, 'Untermodul-Menü per Tab erreicht (Knopf „' + menue.text + '“)');
      await page.keyboard.press('Enter');
      await tabBis(page, frame, (f) => f.href.includes('/module/barrierepruefung/befunde'), protokoll, 5);
      pruefe(true, 'Eintrag „Befunde“ im geöffneten Menü per Tab erreicht');
      frame = await enterUndWarten(page);
    }
    pruefe(/\/befunde/.test(frame.url()), 'Menü wählt „Befunde“: ' + frame.url().replace(/token=[^&]+/, 'token=…'));
    pruefe((await frame.locator('h2').first().innerText()).length > 0 && (await frame.locator('table').count()) === 1, 'Liste mit Tabelle steht');
    const zeilen = await frame.locator('table tbody tr').count();
    pruefe(zeilen === 6, `sechs offene verbindliche Regeln (${zeilen})`);
    pruefe(await frame.locator('table thead th[scope="col"]').count() === 6, 'sechs Spaltenköpfe mit scope="col"');
    for (const t of await frame.locator('table .badge').allInnerTexts()) pruefe(t.trim().length > 0, `Schweregrad als Wort: „${t.trim()}“`);
    pruefe(await keinQuerscrollen(page), 'kein Querscrollen bei 1280 px');
    await page.screenshot({ path: `${ERGEBNISSE}befunde-${inst.name}-liste-hell.png` });
    const listeAxe = await axeModul(frame, inst.name);
    pruefe(listeAxe.length === 0, 'axe-core Liste: keine Verstöße' + (listeAxe.length ? ': ' + listeAxe.join('; ') : ''));

    // --- Tastatur: Regel „Zu geringer Kontrast“ öffnen ---
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.href.includes('regel=axe.color-contrast'), protokoll);
    frame = await enterUndWarten(page);
    pruefe(await frame.locator('h2:has-text("Zu geringer Kontrast")').count() === 1, 'Regelansicht: Überschrift mit dem Namen der Regel');
    pruefe(await frame.locator('.callout-info').count() === 1, 'Reparaturhinweis als callout');
    pruefe((await frame.locator('table tbody tr').count()) === 20, 'zwanzig Fundstellen auf der ersten Seite');
    pruefe(/1.*20.*267/.test(await zaehler(frame)), 'Zähler „1 bis 20 von 267“: ' + (await zaehler(frame)));
    await page.screenshot({ path: `${ERGEBNISSE}befunde-${inst.name}-regel-hell.png` });

    // --- Tastatur: Weitere laden, dann zurück zur Liste ---
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => /Load more|Weitere laden/.test(f.text), protokoll, 100);
    frame = await enterUndWarten(page);
    pruefe(/seite=2/.test(frame.url()), 'Weitere laden → seite=2');
    pruefe(/21.*40.*267/.test(await zaehler(frame)), 'Zähler „21 bis 40 von 267“');
    const regelAxe = await axeModul(frame, inst.name);
    pruefe(regelAxe.length === 0, 'axe-core Regelansicht: keine Verstöße' + (regelAxe.length ? ': ' + regelAxe.join('; ') : ''));
    await page.screenshot({ path: `${ERGEBNISSE}befunde-${inst.name}-regel-seite2-hell.png` });
    const anfragen = (await attrappe('anfragen')).filter((a) => a.pfad.includes('/findings/axe.color-contrast'));
    pruefe(anfragen.some((a) => /offset=20/.test(a.query ?? a.pfad)), 'Attrappe sah offset=20');
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => /Back to the list|Zurück zur Liste/.test(f.text), protokoll);
    frame = await enterUndWarten(page);
    pruefe(await frame.locator('table tbody tr').count() === 6 && !/regel=/.test(frame.url()), 'zurück auf der Liste');

    pruefe(protokoll.unsichtbar.length === 0, `Fokus an allen ${protokoll.halte.length} Halten sichtbar`);
    if (protokoll.unsichtbar.length) console.log(protokoll.unsichtbar.join('\n'));

    // --- dunkel ---
    await farbschema(page, 'dark');
    frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_befunde');
    await page.screenshot({ path: `${ERGEBNISSE}befunde-${inst.name}-liste-dunkel.png` });
    frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_befunde', { regel: 'axe.color-contrast' });
    await page.screenshot({ path: `${ERGEBNISSE}befunde-${inst.name}-regel-dunkel.png` });
    await page.setViewportSize({ width: 390, height: 800 });
    frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_befunde');
    await page.screenshot({ path: `${ERGEBNISSE}befunde-${inst.name}-liste-schmal-dunkel.png` });
    const schmalAxe = await axeModul(frame, inst.name);
    pruefe(schmalAxe.length === 0, 'axe-core Liste bei 390 px: keine Verstöße' + (schmalAxe.length ? ': ' + schmalAxe.join('; ') : ''));
    await farbschema(page, 'auto');
    console.log(`ok ${inst.name}`);
  } catch (e) {
    fehler++;
    console.error(`FEHLER ${inst.name}: ${e.message}`);
    if (protokoll.halte.length) console.error('  Halte: ' + protokoll.halte.slice(-8).join(' → '));
    await page.screenshot({ path: `${ERGEBNISSE}befunde-${inst.name}-fehler.png` }).catch(() => {});
  }
  await kontext.close();
}
await browser.close();
process.exit(fehler ? 1 : 0);
