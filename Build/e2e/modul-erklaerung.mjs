// Backend-Modul „Barrierefreiheit“ → Erklärung in beiden lokalen Instanzen, gegen die API-Attrappe:
// Untermodul über das Menü im Docheader wählen, „Jetzt abgleichen“, „Seite mit Erklärung anlegen“ und den Link ins
// Seitenmodul erreichen – alles nur mit Tab, Pfeiltasten und Enter –, an jedem Halt prüfen, dass der Fokus sichtbar ist;
// axe-core über den Modulrahmen; danach Ausfall und „nichts veröffentlicht“ (Attrappe) und je ein Bildschirmfoto hell und
// dunkel nach ergebnisse/.
//
//   node stub-api.mjs &   (Attrappe auf :8091)
//   node modul-erklaerung.mjs [v13|v14]
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { anmelden, attrappe, AUS_DEM_CONTAINER, fokus, tabBis, inDenRahmen, enterUndWarten, farbschema, axeModul, typo3Befehl, modulOeffnen, neuGeladen, keinQuerscrollen } from './hilfen.mjs';

const ERGEBNISSE = new URL('./ergebnisse/', import.meta.url).pathname;

const instanzen = [
  { name: 'v13', basis: 'http://localhost:8089', siteId: '01STUBSITE0000000000000000', container: 'barrierepruefung-lokal-v13-1' },
  { name: 'v14', basis: 'http://localhost:8090', siteId: '01STUBSITE0000000000000014', container: 'barrierepruefung-lokal-v14-1' },
].filter((i) => !process.argv[2] || i.name === process.argv[2]);

mkdirSync(ERGEBNISSE, { recursive: true });

// Der stündliche Abgleich per Befehl: so merkt die Registry einen Ausfall oder ein 404 des Dienstes (das Modul fragt beim Anzeigen nicht).
function abgleichen(inst) {
  return typo3Befehl(inst, 'barrierepruefung:aktualisieren');
}

const MODUL = 'barrierepruefung_erklaerung';

const browser = await chromium.launch();
let fehler = 0;
for (const inst of instanzen) {
  const kontext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await kontext.newPage();
  const protokoll = { halte: [], unsichtbar: [] };
  const pruefe = (bedingung, satz) => { if (!bedingung) throw new Error(satz); console.log(`  ok ${satz}`); };
  const seitenzeilen = (frame) => frame.locator('#barrierepruefung-seiten tbody tr').count();
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
    // Die Übersicht liest die Erklärung aus der Registry; ohne bisherigen Abruf steht dort „noch nicht abgerufen“ oder die letzte Fassung.
    pruefe(await frame.locator('dt:has-text("Erklärung"), dt:has-text("Statement")').count() === 1, 'Übersicht hat die Zeile „Erklärung“');

    // --- Tastatur: Untermodul „Erklärung“ über das Menü im Docheader wählen ---
    await inDenRahmen(page);
    const menue = await tabBis(page, frame, (f) => f.id === 'moduleMenu' || (f.tag === 'button' && /^(Module action|Modulaktion)/.test(f.text)), protokoll);
    if (menue.id === 'moduleMenu') {
      pruefe(true, 'Untermodul-Menü per Tab erreicht (Select #moduleMenu)');
      const beschriftung = (await frame.locator('#moduleMenu option').allInnerTexts()).map((t) => t.trim()).find((t) => /Erklärung|Statement/.test(t));
      frame = await neuGeladen(page, () => frame.locator('#moduleMenu').selectOption({ label: beschriftung }));
    } else {
      pruefe(true, 'Untermodul-Menü per Tab erreicht (Knopf „' + menue.text + '“)');
      await page.keyboard.press('Enter');
      await tabBis(page, frame, (f) => f.href.includes('/module/barrierepruefung/erklaerung'), protokoll, 6);
      pruefe(true, 'Eintrag „Erklärung“ im geöffneten Menü per Tab erreicht');
      frame = await enterUndWarten(page);
    }
    pruefe(/\/erklaerung/.test(frame.url()), 'Menü wählt „Erklärung“: ' + frame.url().replace(/token=[^&]+/, 'token=…'));
    pruefe(await frame.locator('h1').count() === 1 && (await frame.locator('h2').count()) === 3, 'eine h1, drei h2 (Erklärung, Seiten, Anlegen)');

    // --- „Jetzt abgleichen“ per Tastatur: der Dienst veröffentlicht Fassung 4 ---
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.id === '' && /Jetzt abgleichen|Compare now/.test(f.text), protokoll, 40);
    frame = await enterUndWarten(page);
    pruefe(await frame.locator('.callout-success[role=status]').count() === 1, 'Rückmeldung nach dem Abgleich als callout role=status (kein Toast)');
    pruefe(await frame.locator('dd .badge-success:has-text("veröffentlicht"), dd .badge-success:has-text("published")').count() === 1, 'Zustand als Wort: veröffentlicht');
    pruefe(/\(Fassung 4\)|\(version 4\)/.test(await frame.locator('dl').innerText()), 'Stand und Fassung 4 stehen da');
    await page.screenshot({ path: `${ERGEBNISSE}erklaerung-${inst.name}-veroeffentlicht-hell.png` });
    const stand = await axeModul(frame, inst.name);
    pruefe(stand.length === 0, 'axe-core Stand: keine Verstöße' + (stand.length ? ': ' + stand.join('; ') : ''));

    // --- „Seite mit Erklärung anlegen“ per Tastatur ---
    const vorher = await seitenzeilen(frame);
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.id === '' && /Seite mit Erklärung anlegen|Create page with statement/.test(f.text), protokoll, 40 + 2 * vorher); // der Knopf steht hinter der Seitenliste
    frame = await enterUndWarten(page);
    const meldung = frame.locator('.callout-success[role=status]');
    pruefe(await meldung.count() === 1, 'Rückmeldung nach dem Anlegen als callout role=status');
    pruefe(/verborgen|hidden/.test(await meldung.innerText()) && /Fußzeile|footer/.test(await meldung.innerText()), 'Rückmeldung nennt „verborgen“ und die Fußzeile');
    pruefe(await seitenzeilen(frame) === vorher + 1, `neue Seite steht in der Liste (${vorher} → ${vorher + 1})`);
    pruefe(await frame.locator('#barrierepruefung-seiten .badge-warning:has-text("verborgen"), #barrierepruefung-seiten .badge-warning:has-text("hidden")').count() >= 1, 'verborgen steht als Wort im badge');
    await page.screenshot({ path: `${ERGEBNISSE}erklaerung-${inst.name}-angelegt-hell.png` });
    // Den Link im callout und den Link der Tabelle erreicht man per Tab.
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.href.includes('/module/web/layout') || f.href.includes('web_layout') || /layout/.test(f.href), protokoll, 30);
    pruefe(true, 'Link ins Seitenmodul per Tab erreicht');
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => /Im Seitenmodul öffnen|Open in the page module/.test(f.text), protokoll, 40);
    pruefe(true, 'Link der Tabelle „Im Seitenmodul öffnen“ per Tab erreicht');
    const liste = await axeModul(frame, inst.name);
    pruefe(liste.length === 0, 'axe-core mit Seitenliste: keine Verstöße' + (liste.length ? ': ' + liste.join('; ') : ''));
    pruefe(await keinQuerscrollen(page), 'kein Querscrollen bei 1280 px');
    pruefe(protokoll.unsichtbar.length === 0, `Fokus an allen ${protokoll.halte.length} Halten sichtbar`);
    if (protokoll.unsichtbar.length) console.log(protokoll.unsichtbar.join('\n'));

    // --- Ausfall des Dienstes: der Befehl merkt ihn, das Modul zeigt die letzte gute Fassung und den Grund ---
    await attrappe('ausfall', { an: true });
    console.log('  Befehl: ' + abgleichen(inst).split('\n').join(' | '));
    frame = await modulOeffnen(page, inst.basis, MODUL);
    const warnungen = await frame.locator('.callout-warning').allInnerTexts();
    pruefe(warnungen.length === 1 && /Zuletzt erreichbar am|Last reachable on/.test(warnungen[0]), 'Ausfall: „Zuletzt erreichbar am …“' + (warnungen.length === 1 && /Zuletzt erreichbar am|Last reachable on/.test(warnungen[0]) ? '' : ` – gefunden: ${JSON.stringify(warnungen)}`));
    pruefe(/Grund|Reason/.test(await frame.locator('.callout-warning').innerText()), 'Ausfall: Grund steht dabei');
    pruefe(await frame.locator('dd .badge:has-text("veröffentlicht"), dd .badge:has-text("published")').count() === 1, 'Ausfall: die letzte gute Fassung bleibt „veröffentlicht“');
    await page.screenshot({ path: `${ERGEBNISSE}erklaerung-${inst.name}-ausfall-hell.png` });
    const ausfallAxe = await axeModul(frame, inst.name);
    pruefe(ausfallAxe.length === 0, 'axe-core Ausfall: keine Verstöße' + (ausfallAxe.length ? ': ' + ausfallAxe.join('; ') : ''));
    await farbschema(page, 'dark');
    frame = await modulOeffnen(page, inst.basis, MODUL);
    await page.screenshot({ path: `${ERGEBNISSE}erklaerung-${inst.name}-ausfall-dunkel.png` });
    await farbschema(page, 'light');
    await attrappe('ausfall', { an: false });

    // --- Im Dienst ist nichts (mehr) veröffentlicht: 404 ---
    await attrappe('unveroeffentlicht', { an: true });
    console.log('  Befehl: ' + abgleichen(inst).split('\n').join(' | '));
    frame = await modulOeffnen(page, inst.basis, MODUL);
    const text404 = await frame.locator('.callout-info').innerText();
    pruefe(/Im Dienst ist derzeit keine Erklärung veröffentlicht|No statement is currently published/.test(text404), '404: „Im Dienst ist derzeit keine Erklärung veröffentlicht.“');
    pruefe(/Auf der Website steht weiter die Fassung vom|keeps showing the version of/.test(text404), '404: sagt, was auf der Website steht');
    await page.screenshot({ path: `${ERGEBNISSE}erklaerung-${inst.name}-nicht-veroeffentlicht-hell.png` });
    const a404 = await axeModul(frame, inst.name);
    pruefe(a404.length === 0, 'axe-core 404: keine Verstöße' + (a404.length ? ': ' + a404.join('; ') : ''));
    await attrappe('unveroeffentlicht', { an: false });
    abgleichen(inst);

    // --- dunkel und schmal ---
    await farbschema(page, 'dark');
    frame = await modulOeffnen(page, inst.basis, MODUL);
    await page.screenshot({ path: `${ERGEBNISSE}erklaerung-${inst.name}-veroeffentlicht-dunkel.png` });
    await page.setViewportSize({ width: 390, height: 800 });
    frame = await modulOeffnen(page, inst.basis, MODUL);
    await page.screenshot({ path: `${ERGEBNISSE}erklaerung-${inst.name}-schmal-dunkel.png` });
    const schmal = await axeModul(frame, inst.name);
    pruefe(schmal.length === 0, 'axe-core bei 390 px: keine Verstöße' + (schmal.length ? ': ' + schmal.join('; ') : ''));
    await farbschema(page, 'auto');
    console.log(`ok ${inst.name}`);
  } catch (e) {
    fehler++;
    console.error(`FEHLER ${inst.name}: ${e.message}`);
    if (protokoll.halte.length) console.error('  Halte: ' + protokoll.halte.slice(-8).join(' → '));
    await page.screenshot({ path: `${ERGEBNISSE}erklaerung-${inst.name}-fehler.png` }).catch(() => {});
  }
  await kontext.close();
}
await browser.close();
process.exit(fehler ? 1 : 0);
