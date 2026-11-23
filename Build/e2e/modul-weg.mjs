// Backend-Modul „Barrierefreiheit“ → Weg zur Erklärung in beiden lokalen Instanzen, gegen die API-Attrappe:
// Untermodul über das Menü im Docheader wählen, die Schritte lesen, Prüfschritte beantworten, „Bund oder Land“ wählen,
// einen Feldfehler bei den Pflichtangaben provozieren – alles nur mit Tab, Pfeiltasten, Tippen und Enter –, an jedem Halt
// prüfen, dass der Fokus sichtbar ist; axe-core über den Modulrahmen; danach die Freigabe (Vorschau, Abweichung ohne
// Begründung, Veröffentlichen, Doppelklick, geänderter Entwurf, nicht bereit), ein Token ohne Recht (nur Sätze, kein
// Formular) und je ein Bildschirmfoto hell und dunkel nach ergebnisse/.
//
//   node stub-api.mjs &   (Attrappe auf :8091)
//   node modul-weg.mjs [v13|v14]
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { anmelden, attrappe, typo3Befehl, AUS_DEM_CONTAINER, tabBis, inDenRahmen, enterUndWarten, farbschema, axeModul, modulOeffnen, neuGeladen, keinQuerscrollen, fokus } from './hilfen.mjs';

const ERGEBNISSE = new URL('./ergebnisse/', import.meta.url).pathname;
const MODUL = 'barrierepruefung_weg';

const instanzen = [
  { name: 'v13', basis: 'http://localhost:8089', siteId: '01STUBSITE0000000000000000', site: 'main', container: 'barrierepruefung-lokal-v13-1' },
  { name: 'v14', basis: 'http://localhost:8090', siteId: '01STUBSITE0000000000000014', site: 'camino', container: 'barrierepruefung-lokal-v14-1' },
].filter((i) => !process.argv[2] || i.name === process.argv[2]);

mkdirSync(ERGEBNISSE, { recursive: true });

// Die PUT-Anfragen, die bei der Attrappe angekommen sind (Pfad und JSON-Rumpf).
async function freigaben() {
  const anfragen = await attrappe('anfragen');
  return anfragen.filter((a) => a.methode === 'POST' && a.pfad.endsWith('/declaration/publish')).map((a) => ({ schluessel: a.kopfzeilen['idempotency-key'], rumpf: JSON.parse(a.rumpf) }));
}

// Die Stand-Zeile der Frontend-Seite mit dem Element (Seiten aus Build/lokal/seed/erklaerungsseiten.sql).
async function standImFrontend(basis) {
  const html = await (await fetch(`${basis}/erklaerung-komplett-h2-mit-stand`)).text();
  return (html.match(/<p class="a11y-erklaerung-stand">(.*?)<\/p>/s)?.[1] ?? '').replace(/<[^>]+>/g, '');
}

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

    // --- Freigabe ---
    await attrappe('weg_offen', { an: false });
    // Ausgangslage: Fassung 4 der Attrappe im Frontend (ein früherer Lauf hat vielleicht 5 hinterlassen).
    typo3Befehl(inst, 'barrierepruefung:aktualisieren');
    const vorher = await standImFrontend(inst.basis);
    pruefe(/Version 4/.test(vorher), `Frontend vorher: „${vorher}“`);
    frame = await modulOeffnen(page, inst.basis, MODUL);
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => /Entwurf prüfen und freigeben|Review the draft and publish/.test(f.text), protokoll, 40);
    frame = await enterUndWarten(page);
    pruefe(/\/weg\/freigabe/.test(frame.url()), 'Knopf der Übersicht führt zur Freigabe');
    pruefe(await frame.locator('.module-body h1').count() === 1, 'eine h1');
    pruefe(await frame.locator('#barrierepruefung-vorschau h1, #barrierepruefung-vorschau h2').count() === 0, 'Vorschau ohne h1/h2');
    pruefe((await frame.locator('#barrierepruefung-vorschau :is(h3,h4,h5,h6)').first().evaluate((e) => e.tagName)) === 'H3', 'Vorschau beginnt bei h3');
    pruefe(await frame.locator('#barrierepruefung-freigabe button[type=submit]:has-text("5")').count() === 1, 'ein Knopf, er nennt Fassung 5');
    pruefe(await frame.locator('input[name=conformance_status]').count() === 2 && await frame.locator('input[name=conformance_status][value=none]').isChecked(), 'nur wählbare Aussagen, die ermittelte gewählt');
    await bild('freigabe-hell');
    await axe(frame, 'Freigabe');
    pruefe(await keinQuerscrollen(page), 'Freigabe: kein Querscrollen');

    // Abweichen ohne Begründung: Pfeil wählt „teilweise konform“, Enter auf dem Knopf – nichts geht raus.
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.name === 'conformance_status', protokoll, 60);
    await page.keyboard.press('ArrowUp');
    pruefe(await frame.locator('input[name=conformance_status][value=partial]').isChecked(), 'Pfeiltaste wählt „teilweise konform“');
    await tabBis(page, frame, (f) => f.tag === 'button' && /veröffentlichen|Publish version/.test(f.text), protokoll, 10);
    frame = await enterUndWarten(page);
    pruefe((await freigaben()).length === 0, 'Abweichung ohne Begründung: keine Anfrage');
    pruefe(await frame.locator('.callout-danger[role=alert] a[href="#bp-override_reason"]').count() === 1, 'Fehlerliste verweist auf die Begründung');
    const begruendungsfokus = await fokus(frame);
    pruefe(begruendungsfokus?.id === 'bp-override_reason' && begruendungsfokus.sichtbar, 'Fokus steht sichtbar in der Begründung');
    pruefe(await frame.locator('input[name=conformance_status][value=partial]').isChecked(), 'die Wahl bleibt');
    await frame.locator('#barrierepruefung-freigabe').scrollIntoViewIfNeeded();
    await bild('freigabe-fehler-formular-hell');
    await frame.evaluate(() => window.scrollTo(0, 0));
    await bild('freigabe-fehler-hell');
    await axe(frame, 'Freigabe mit Fehler');
    await page.keyboard.type('Die offenen Befunde betreffen nur das Archiv.');
    await tabBis(page, frame, (f) => f.tag === 'button' && /veröffentlichen|Publish version/.test(f.text), protokoll, 10);
    frame = await enterUndWarten(page);
    let gesendetFreigabe = await freigaben();
    pruefe(gesendetFreigabe.length === 1, 'ein POST …/declaration/publish');
    const [erste] = gesendetFreigabe;
    pruefe(/^[0-9a-f-]{36}$/.test(erste.schluessel ?? ''), `Idempotency-Key ${erste.schluessel}`);
    pruefe(erste.rumpf.expected_version === 5 && erste.rumpf.draft_etag === '8b32d44b7ca691a93d982f481d5fa5cf' && erste.rumpf.actor_name !== '', 'draft_etag, expected_version 5, actor_name: ' + JSON.stringify(erste.rumpf));
    pruefe(erste.rumpf.conformance_status === 'partial' && /Archiv/.test(erste.rumpf.override_reason), 'Abweichung mit Begründung gesendet');
    pruefe(await frame.locator('.callout-success[role=status]:has-text("5")').count() === 1, 'Rückmeldung „Fassung 5 ist veröffentlicht“');
    const nachher = await standImFrontend(inst.basis);
    pruefe(/Version 5/.test(nachher), `Frontend zeigt sofort die neue Fassung: „${nachher}“`);
    await bild('freigabe-erfolg-hell');

    // Doppelklick: zweimal dasselbe Formular, ein Schlüssel – der Dienst legt eine Fassung an, die zweite Antwort ist die Wiederholung.
    await attrappe('zuruecksetzen', {});
    frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_weg_freigabe');
    // Der Browser selbst schickt bei einem Doppelklick meist nur eine Anfrage; die zweite, die ein langsames Netz
    // durchlässt, bilden zwei gleichzeitige Sendungen desselben Formulars nach.
    frame = await neuGeladen(page, () => frame.locator('#barrierepruefung-freigabe button[type=submit]').dblclick());
    const imBrowser = (await freigaben()).length;
    pruefe(imBrowser >= 1 && await frame.locator('[role=status]').count() === 1, `Doppelklick im Browser: ${imBrowser} Anfrage(n), eine Rückmeldung`);
    await attrappe('zuruecksetzen', {});
    frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_weg_freigabe');
    await frame.evaluate(async () => {
      const form = document.getElementById('barrierepruefung-freigabe');
      const senden = () => fetch(form.action, { method: 'POST', body: new FormData(form), redirect: 'manual', credentials: 'same-origin' });
      await Promise.all([senden(), senden()]);
    });
    gesendetFreigabe = await freigaben();
    const ergebnis = await attrappe('anfragen');
    pruefe(gesendetFreigabe.length === 2 && gesendetFreigabe[0].schluessel === gesendetFreigabe[1].schluessel, `zweimal dasselbe Formular: ${gesendetFreigabe.length} Anfragen, ein Schlüssel`);
    pruefe(ergebnis.filter((a) => a.methode === 'POST' && a.pfad.endsWith('/declaration/publish')).length === 2, 'beide beim Dienst angekommen (die Attrappe legt unter einem Schlüssel eine Fassung an)');
    frame = await modulOeffnen(page, inst.basis, MODUL);
    pruefe(await frame.locator('[role=status]').count() === 1, 'danach eine Rückmeldung: ' + (await frame.locator('[role=status]').allInnerTexts()).join(' | '));

    // Geänderter Entwurf: zurück zur Freigabe, mit Satz und neuem Formular.
    await attrappe('entwurf_geaendert', { an: true });
    frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_weg_freigabe');
    const schluesselVorher = await frame.locator('input[name=idempotency_key]').inputValue();
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.tag === 'button' && /veröffentlichen|Publish version/.test(f.text), protokoll, 60);
    frame = await enterUndWarten(page);
    pruefe(/\/weg\/freigabe/.test(frame.url()) && await frame.locator('[role=alert]:has-text("Entwurf hat sich geändert"), [role=alert]:has-text("draft has changed")').count() === 1, '409: zurück zur Freigabe mit dem Satz');
    pruefe(await frame.locator('[role=status]').count() === 0, '409: keine Erfolgsmeldung');
    pruefe((await frame.locator('input[name=idempotency_key]').inputValue()) !== schluesselVorher, '409: neue Anzeige, neuer Schlüssel');
    await bild('freigabe-geaendert-hell');
    await attrappe('entwurf_geaendert', { an: false });

    // Nicht bereit: der Blocker mit dem Schritt, unter dem der Weg denselben Grund führt.
    await attrappe('nicht_bereit', { an: true });
    frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_weg_freigabe');
    await attrappe('weg_offen', { an: true });
    frame = await neuGeladen(page, () => frame.locator('#barrierepruefung-freigabe button[type=submit]').click());
    const blocker = frame.locator('[role=alert] li a');
    pruefe(await blocker.count() === 1 && (await blocker.getAttribute('href')).includes('schritt=verantwortliche_stelle'), '422: Blocker „land“ verweist auf „Wer erklärt“');
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.href.includes('schritt=verantwortliche_stelle') && /Bund oder zu einem Land/.test(f.text), protokoll, 20);
    await bild('freigabe-nicht-bereit-hell');
    await axe(frame, 'nicht bereit');
    await attrappe('nicht_bereit', { an: false });
    // Solange ein Schritt offen ist: Vorschau ja, Knopf nein.
    frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_weg_freigabe');
    pruefe(await frame.locator('#barrierepruefung-freigabe').count() === 0 && await frame.locator('#barrierepruefung-vorschau').count() === 1, 'offener Weg: Vorschau ohne Knopf');

    await farbschema(page, 'dark');
    await attrappe('weg_offen', { an: false });
    frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_weg_freigabe');
    await bild('freigabe-dunkel');
    await frame.locator('#barrierepruefung-freigabe').scrollIntoViewIfNeeded();
    await bild('freigabe-formular-dunkel');
    await axe(frame, 'Freigabe dunkel');
    await page.setViewportSize({ width: 390, height: 800 });
    frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_weg_freigabe');
    pruefe(await keinQuerscrollen(page), 'Freigabe bei 390 px ohne Querscrollen');
    await bild('freigabe-schmal-dunkel');
    await axe(frame, 'Freigabe bei 390 px');
    await page.setViewportSize({ width: 1280, height: 900 });
    await farbschema(page, 'light');
    await attrappe('zuruecksetzen', {});
    await attrappe('weg_offen', { an: true });

    // --- Ohne Recht: nur Sätze ---
    await attrappe('ohne_recht', { an: true });
    for (const schritt of ['pruefschritte', 'verantwortliche_stelle', 'pflichtangaben']) {
      frame = await modulOeffnen(page, inst.basis, MODUL, { schritt });
      pruefe(await frame.locator('.module-body form').count() === 0 && /Reiter „Einbindung“|“Embedding” tab/.test(await frame.locator('.module-body').innerText()), `ohne Recht (${schritt}): kein Formular, Satz mit dem Weg`);
    }
    await bild('ohne-recht-hell');
    await axe(frame, 'ohne Recht');
    frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_weg_freigabe');
    pruefe(await frame.locator('.module-body form, .module-body button').count() === 0 && /Reiter „Einbindung“|“Embedding” tab/.test(await frame.locator('.module-body').innerText()), 'ohne Recht (Freigabe): kein Formular, kein Knopf, Satz mit dem Weg');
    await bild('freigabe-ohne-recht-hell');
    frame = await modulOeffnen(page, inst.basis, MODUL);
    pruefe(!/Entwurf prüfen und freigeben|Review the draft and publish/.test(await frame.locator('.module-body').innerText()), 'ohne Recht: die Übersicht behauptet keine Freigabe');
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
