// Backend-Modul „Barrierefreiheit“ → Weg zur Erklärung in beiden lokalen Instanzen, gegen die API-Attrappe:
// Untermodul über das Menü im Docheader wählen, die Schritte lesen, Prüfschritte beantworten, „Bund oder Land“ wählen,
// einen Feldfehler bei den Pflichtangaben provozieren – alles nur mit Tab, Pfeiltasten, Tippen und Enter –, an jedem Halt
// prüfen, dass der Fokus sichtbar ist; axe-core über den Modulrahmen; danach ein Token ohne Recht (nur Sätze, kein
// Formular) und je ein Bildschirmfoto hell und dunkel nach ergebnisse/.
//
//   node stub-api.mjs &   (Attrappe auf :8091)
//   node modul-weg.mjs [v13|v14]
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { anmelden, attrappe, AUS_DEM_CONTAINER, tabBis, inDenRahmen, enterUndWarten, farbschema, axeModul, modulOeffnen, neuGeladen, keinQuerscrollen, fokus } from './hilfen.mjs';

const ERGEBNISSE = new URL('./ergebnisse/', import.meta.url).pathname;
const MODUL = 'barrierepruefung_weg';

const instanzen = [
  { name: 'v13', basis: 'http://localhost:8089', siteId: '01STUBSITE0000000000000000', site: 'main' },
  { name: 'v14', basis: 'http://localhost:8090', siteId: '01STUBSITE0000000000000014', site: 'camino' },
].filter((i) => !process.argv[2] || i.name === process.argv[2]);

mkdirSync(ERGEBNISSE, { recursive: true });

// Die PUT-Anfragen, die bei der Attrappe angekommen sind (Pfad und JSON-Rumpf).
async function gesendet(endung) {
  const anfragen = await attrappe('anfragen');
  return anfragen.filter((a) => a.methode === 'PUT' && a.pfad.endsWith(endung)).map((a) => JSON.parse(a.rumpf));
}

const browser = await chromium.launch();
let fehler = 0;
for (const inst of instanzen) {
  const kontext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await kontext.newPage();
  const protokoll = { halte: [], unsichtbar: [] };
  const pruefe = (bedingung, satz) => { if (!bedingung) throw new Error(satz); console.log(`  ok ${satz}`); };
  const axe = async (frame, wo) => { const v = await axeModul(frame, inst.name); pruefe(v.length === 0, `axe-core ${wo}: keine Verstöße` + (v.length ? ': ' + v.join('; ') : '')); };
  const bild = (name) => page.screenshot({ path: `${ERGEBNISSE}weg-${inst.name}-${name}.png` });
  try {
    console.log(`== ${inst.name}`);
    await attrappe('zuruecksetzen', {});
    await attrappe('weg_offen', { an: true });
    await anmelden(page, inst.basis);
    await farbschema(page, 'light');
    const host = new URL(inst.basis).host;

    // Ausgangslage: Site auf localhost, verbunden mit der Attrappe (mit der Maus, das ist nicht der Prüfgegenstand).
    // Die Site über den Parameter wählen (das Modul merkt sie sich für die folgenden Aufrufe). Ihre Kennung hängt von
    // der Einrichtung ab (lokal main/camino, in der CI die erste Site aus `typo3 setup`): die erste, deren Titel den Host trägt.
    let frame;
    for (const kennung of [...new Set([process.env.E2E_SITE, inst.site, 'main', 'camino'].filter(Boolean))]) {
      frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_uebersicht', { site: kennung });
      if ((await frame.locator('h1').innerText()).includes(host)) break;
    }
    pruefe((await frame.locator('h1').innerText()).includes(host), `Site ${host} gewählt`);
    if (await frame.locator('#barrierepruefung-trennen').count()) {
      frame = await neuGeladen(page, () => frame.locator('#barrierepruefung-trennen button').click());
    }
    await frame.fill('#barrierepruefung-dienst-url', AUS_DEM_CONTAINER);
    await frame.fill('#barrierepruefung-site-id', inst.siteId);
    await frame.fill('#barrierepruefung-token', 'beliebiges-token-der-attrappe');
    frame = await neuGeladen(page, () => frame.locator('#barrierepruefung-verbinden button[type=submit]').click());
    pruefe(await frame.locator('dl').count() === 1, 'verbunden, Übersicht zeigt den Stand');

    // --- Tastatur: Untermodul „Weg zur Erklärung“ über das Menü im Docheader wählen ---
    await inDenRahmen(page);
    const menue = await tabBis(page, frame, (f) => f.id === 'moduleMenu' || (f.tag === 'button' && /^(Module action|Modulaktion)/.test(f.text)), protokoll);
    if (menue.id === 'moduleMenu') {
      const beschriftung = (await frame.locator('#moduleMenu option').allInnerTexts()).map((t) => t.trim()).find((t) => /Weg zur Erklärung|Way to the statement/.test(t));
      frame = await neuGeladen(page, () => frame.locator('#moduleMenu').selectOption({ label: beschriftung }));
    } else {
      await page.keyboard.press('Enter');
      await tabBis(page, frame, (f) => f.href.includes('/module/barrierepruefung/weg'), protokoll, 8);
      frame = await enterUndWarten(page);
    }
    pruefe(/\/weg/.test(frame.url()), 'Menü wählt „Weg zur Erklärung“');
    pruefe(await frame.locator('h1').count() === 1 && await frame.locator('h2').count() === 1, 'eine h1, eine h2');
    const zeilen = frame.locator('#barrierepruefung-schritte tbody tr');
    pruefe(await zeilen.count() === 7, 'sieben Schritte wie vom Dienst, in ihrer Reihenfolge');
    pruefe(/offen/.test(await zeilen.nth(1).innerText()) && /Fokusreihenfolge/.test(await zeilen.nth(1).innerText()), 'Zustand als Wort und Grund des Dienstes stehen in der Zeile');
    pruefe(/PDF-Dokumente nennen/.test(await zeilen.nth(4).innerText()), 'ein Schritt, den die Extension nicht kennt, steht trotzdem da');
    pruefe(/4 von 7 Schritten erledigt|4 of 7 steps done/.test(await frame.locator('dl').innerText()), 'Stand „4 von 7“');
    await bild('uebersicht-hell');
    await axe(frame, 'Übersicht');
    pruefe(await keinQuerscrollen(page), 'kein Querscrollen bei 1280 px');

    // --- Nächster Schritt per Tastatur: Prüfschritte beantworten ---
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => /Nächster Schritt: Prüfschritte beantworten|Next step:/.test(f.text), protokoll, 40);
    frame = await enterUndWarten(page);
    pruefe(await frame.locator('#barrierepruefung-pruefschritte').count() === 1, 'Schritt „Prüfschritte“ zeigt das Formular');
    pruefe(await frame.locator('h3:has-text("Ist die Fokusreihenfolge sinnvoll?")').count() === 1, 'Frage im Wortlaut des Dienstes als h3');
    await bild('pruefschritte-hell');
    await axe(frame, 'Prüfschritte');
    // In die Gruppe der ersten Frage: Tab landet auf der gewählten Antwort („offen“), Pfeil hoch wählt bis „erfüllt“.
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.name === 'checks[11][answer]', protokoll, 40);
    for (let i = 0; i < 3; i++) await page.keyboard.press('ArrowUp');
    pruefe(await frame.locator('input[name="checks[11][answer]"][value=passed]').isChecked(), 'Pfeiltasten wählen „erfüllt“');
    await tabBis(page, frame, (f) => /Antworten speichern|Save answers/.test(f.text), protokoll, 10);
    frame = await enterUndWarten(page);
    const stapel = await gesendet('/manual-checks');
    pruefe(stapel.length === 1 && stapel[0].checks.length === 1 && stapel[0].checks[0].id === 11 && stapel[0].checks[0].answer === 'passed', 'ein PUT, nur die geänderte Frage: ' + JSON.stringify(stapel.map((s) => s.checks)));
    pruefe(stapel[0].actor_name !== undefined && stapel[0].actor_name !== '', `actor_name gesetzt („${stapel[0].actor_name}“)`);
    pruefe(await frame.locator('.callout-success[role=status]').count() === 1, 'Rückmeldung „Gespeichert“ als callout role=status');

    // --- Wer erklärt: Bund oder Land per Tastatur ---
    frame = await modulOeffnen(page, inst.basis, MODUL, { schritt: 'verantwortliche_stelle' });
    pruefe(await frame.locator('select[name=subdivision] option').count() === 18, 'Länder aus der API (17 und „Bitte wählen“)');
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.name === 'subdivision', protokoll, 40);
    await page.keyboard.type('Nieder');
    await tabBis(page, frame, (f) => /^(Speichern|Save)$/.test(f.text), protokoll, 5);
    await bild('stelle-hell');
    frame = await enterUndWarten(page);
    const stelle = await gesendet('/declaring-party');
    pruefe(stelle.length === 1 && stelle[0].subdivision === 'NI' && stelle[0].applies_to === 'public_body', 'PUT declaring-party mit subdivision NI: ' + JSON.stringify(stelle[0]));

    // --- Betroffenheit: die Attrappe stellt die Frage einer öffentlichen Stelle nicht ---
    frame = await modulOeffnen(page, inst.basis, MODUL, { schritt: 'betroffenheit' });
    pruefe(/stellt sich für diese Website nicht|does not arise/.test(await frame.locator('.module-body').innerText()), 'Betroffenheit: „Diese Frage stellt sich … nicht“');

    // --- Pflichtangaben: Kontakt leeren, der Dienst weist ab, Fehler am Feld ---
    await attrappe('eingabefehler', { an: true });
    frame = await modulOeffnen(page, inst.basis, MODUL, { schritt: 'pflichtangaben' });
    pruefe(await frame.locator('#bp-dgs_ls_url').count() === 0, 'nicht geltende Angabe (applies = false) steht nicht im Formular');
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.id === 'bp-contact', protokoll, 40);
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.press('Backspace');
    await tabBis(page, frame, (f) => /Angaben speichern|Save details/.test(f.text), protokoll, 10);
    frame = await enterUndWarten(page);
    pruefe(await frame.locator('.callout-danger[role=alert] a[href="#bp-contact"]').count() === 1, 'Fehlerliste im callout role=alert verweist aufs Feld');
    pruefe(await frame.locator('#bp-contact[aria-invalid=true]').count() === 1, 'Feld trägt aria-invalid');
    pruefe((await frame.locator('#bp-contact').getAttribute('aria-describedby')).includes('bp-contact-fehler'), 'Fehlertext über aria-describedby');
    const f = await fokus(frame);
    pruefe(f?.id === 'bp-contact' && f.sichtbar, 'Fokus steht sichtbar im fehlerhaften Feld');
    const details = await gesendet('/declaration/details');
    pruefe(details.length === 1 && details[0].contact === '' && details[0].dgs_ls_url === 'http://localhost:8089/leichte-sprache'.replace('8089', inst.name === 'v14' ? '8090' : '8089'), 'PUT details: leerer Kontakt, nicht gezeigte Angabe mit ihrem Wert');
    await bild('pflichtangaben-fehler-hell');
    await axe(frame, 'Pflichtangaben mit Fehler');
    await farbschema(page, 'dark');
    frame = await modulOeffnen(page, inst.basis, MODUL, { schritt: 'pflichtangaben' });
    await bild('pflichtangaben-dunkel');
    pruefe(await frame.locator('#bp-contact-fehler').count() === 0, 'Fehler steht genau einmal da (nach dem nächsten Aufruf weg)');
    await farbschema(page, 'light');
    await attrappe('eingabefehler', { an: false });

    // --- Unbekannter Schritt ---
    frame = await modulOeffnen(page, inst.basis, MODUL, { schritt: 'neu_im_dienst' });
    pruefe(await frame.locator('.module-body a[href="https://dienst.example/pruefung/01STUBSCAN0000000000000000/pdf"]').count() === 1, 'unbekannter Schritt: Titel, Grund und Link in den Dienst');
    await axe(frame, 'unbekannter Schritt');

    // --- Ohne Recht: nur Sätze ---
    await attrappe('ohne_recht', { an: true });
    for (const schritt of ['pruefschritte', 'verantwortliche_stelle', 'pflichtangaben']) {
      frame = await modulOeffnen(page, inst.basis, MODUL, { schritt });
      pruefe(await frame.locator('.module-body form').count() === 0 && /Reiter „Einbindung“|“Embedding” tab/.test(await frame.locator('.module-body').innerText()), `ohne Recht (${schritt}): kein Formular, Satz mit dem Weg`);
    }
    await bild('ohne-recht-hell');
    await axe(frame, 'ohne Recht');
    await attrappe('ohne_recht', { an: 'erloschen' });
    frame = await modulOeffnen(page, inst.basis, MODUL, { schritt: 'pruefschritte' });
    pruefe(/neu erteilen|grant it again/.test(await frame.locator('.module-body').innerText()), 'erloschen: „neu erteilen“');
    await farbschema(page, 'dark');
    frame = await modulOeffnen(page, inst.basis, MODUL, { schritt: 'pruefschritte' });
    await bild('ohne-recht-dunkel');
    await attrappe('ohne_recht', { an: false });

    // --- dunkel: Übersicht und Prüfschritte ---
    frame = await modulOeffnen(page, inst.basis, MODUL);
    await bild('uebersicht-dunkel');
    await axe(frame, 'Übersicht dunkel');
    frame = await modulOeffnen(page, inst.basis, MODUL, { schritt: 'pruefschritte' });
    await bild('pruefschritte-dunkel');
    await page.setViewportSize({ width: 390, height: 800 });
    frame = await modulOeffnen(page, inst.basis, MODUL);
    await bild('schmal-dunkel');
    await axe(frame, 'bei 390 px');
    await farbschema(page, 'auto');

    pruefe(protokoll.unsichtbar.length === 0, `Fokus an allen ${protokoll.halte.length} Halten sichtbar`);
    if (protokoll.unsichtbar.length) console.log(protokoll.unsichtbar.join('\n'));
    console.log(`ok ${inst.name}`);
  } catch (e) {
    fehler++;
    console.error(`FEHLER ${inst.name}: ${e.message}`);
    if (protokoll.halte.length) console.error('  Halte: ' + protokoll.halte.slice(-8).join(' → '));
    await page.screenshot({ path: `${ERGEBNISSE}weg-${inst.name}-fehler.png` }).catch(() => {});
  }
  await kontext.close();
}
await attrappe('zuruecksetzen', {});
await browser.close();
process.exit(fehler ? 1 : 0);
