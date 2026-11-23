// Abnahme „Weg zur Erklärung“ gegen den ECHTEN Dienst (nicht die Attrappe), je Instanz und LTS, auf der zweiten Site
// (http://127.0.0.1:<Port>): jeden Schritt einmal ausfüllen – Prüfschritt (Notiz), Wer erklärt (Land und zurück zu
// Bund), Betroffenheits-Check (dafür kurz „Privatwirtschaft“, danach zurück), Pflichtangaben mit provoziertem
// Feldfehler (Kontakt leer) und danach dem alten Wert –, alles per Tastatur, mit axe und Bildschirmfotos hell und dunkel.
// Mit ABNAHME_FREIGEBEN=1 danach die Freigabe: Vorschau, dann Enter auf „Fassung n jetzt veröffentlichen“ und im selben
// Augenblick ein zweites Absenden desselben Formulars (Doppelklick) – der Dienst muss genau EINE neue Fassung anlegen, die
// Frontend-Seite mit dem Element zeigt sie sofort. Ohne den Schalter wird nichts veröffentlicht. In 14.3 trägt die
// Backend-Person dafür kurz einen realName (danach wieder leer), in 13.4 steht ihr Benutzername in der Fassung.
// Danach mit einem Token OHNE Veröffentlichungsrecht: nur Sätze, kein Formular, keine Freigabe. Zum Schluss wieder mit
// dem Token mit Recht verbunden.
//
// Zugangsdaten nur aus der Umgebung, nie aus dem Repository:
//   ABNAHME_TOKEN             Token der Website MIT Veröffentlichungsrecht
//   ABNAHME_TOKEN_OHNE_RECHT  Token derselben Website ohne das Recht
//   ABNAHME_SITE_ID           Kennung der Website im Dienst (ULID)
//   ABNAHME_DIENST            Adresse der API von diesem Rechner aus (Standard http://127.0.0.1:8001/api/v1)
//
//   ABNAHME_TOKEN=… ABNAHME_TOKEN_OHNE_RECHT=… ABNAHME_SITE_ID=… node abnahme-weg.mjs v13|v14
//
// Die Instanz erreicht den Dienst über den Durchreicher auf Port 8092 (aus Docker: host.docker.internal); läuft dort
// keiner, startet das Skript einen.
import { chromium } from 'playwright';
import net from 'node:net';
import { mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { anmelden, modulOeffnen, neuGeladen, tabBis, inDenRahmen, enterUndWarten, farbschema, axeModul, fokus } from './hilfen.mjs';

const ERGEBNISSE = new URL('./ergebnisse/', import.meta.url).pathname;
const DURCHREICHER = 8092;
const MODUL = 'barrierepruefung_weg';

const token = process.env.ABNAHME_TOKEN;
const ohneRecht = process.env.ABNAHME_TOKEN_OHNE_RECHT;
const siteId = process.env.ABNAHME_SITE_ID;
const dienst = (process.env.ABNAHME_DIENST ?? 'http://127.0.0.1:8001/api/v1').replace(/\/$/, '');
if (!token || !ohneRecht || !siteId) {
  console.error('ABNAHME_TOKEN, ABNAHME_TOKEN_OHNE_RECHT und ABNAHME_SITE_ID müssen gesetzt sein (Umgebungsvariablen, nie im Repository).');
  process.exit(2);
}
const instanzen = {
  v13: { name: 'v13', fassung: '13', basis: 'http://localhost:8089', host: '127.0.0.1:8089', echt: 'http://127.0.0.1:8089', realName: '' },
  v14: { name: 'v14', fassung: '14', basis: 'http://localhost:8090', host: '127.0.0.1:8090', echt: 'http://127.0.0.1:8090', realName: 'Rita Redaktion (Abnahme)' },
};
const inst = instanzen[process.argv[2]];
if (!inst) {
  console.error('Aufruf: node abnahme-weg.mjs v13|v14');
  process.exit(2);
}
mkdirSync(ERGEBNISSE, { recursive: true });

// Durchreicher 8092 → Dienst, falls noch keiner läuft.
const ziel = new URL(dienst);
let durchreicher = null;
await new Promise((fertig) => {
  const s = net.createServer((a) => { const b = net.connect(Number(ziel.port || 80), ziel.hostname); a.pipe(b).pipe(a); a.on('error', () => b.destroy()); b.on('error', () => a.destroy()); });
  s.once('error', () => fertig());
  s.listen(DURCHREICHER, '0.0.0.0', () => { durchreicher = s; fertig(); });
});

// Der Dienst begrenzt auf 60 Anfragen je Minute und Token; jede Ansicht des Moduls stellt zwei bis drei. Ein Abstand vor
// jeder Ansicht und jedem eigenen Abruf hält den Lauf darunter (sonst 429, das das Modul korrekt als Fehler zeigt).
const ABSTAND_MS = Number(process.env.ABNAHME_ABSTAND_MS ?? 6000);
const atemholen = () => new Promise((r) => setTimeout(r, ABSTAND_MS));

async function api(pfad, sprache = 'de') {
  await atemholen();
  const antwort = await fetch(`${dienst}/${pfad}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Accept-Language': sprache } });
  if (!antwort.ok) throw new Error(`GET ${pfad}: ${antwort.status}`);
  return (await antwort.json()).data;
}

const browser = await chromium.launch();
const kontext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await kontext.newPage();
const protokoll = { halte: [], unsichtbar: [] };
const pruefe = (bedingung, satz) => { if (!bedingung) throw new Error(satz); console.log(`  ok ${satz}`); };
const axe = async (frame, wo) => { const v = await axeModul(frame, inst.name); pruefe(v.length === 0, `axe-core ${wo}: keine Verstöße` + (v.length ? ': ' + v.join('; ') : '')); };
const bild = (name) => page.screenshot({ path: `${ERGEBNISSE}abnahme-weg-${inst.name}-${name}.png` });
const schritt = async (s) => { await atemholen(); return modulOeffnen(page, inst.basis, MODUL, { site: 'zweite-site', schritt: s }); };

async function verbinden(mit) {
  await atemholen();
  let frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_uebersicht', { site: 'zweite-site' });
  pruefe((await frame.locator('h1').innerText()).includes(inst.host), `Site ${inst.host} gewählt`);
  if (await frame.locator('#barrierepruefung-trennen').count()) {
    frame = await neuGeladen(page, () => frame.locator('#barrierepruefung-trennen button').click());
  }
  await frame.fill('#barrierepruefung-dienst-url', `http://host.docker.internal:${DURCHREICHER}/api/v1`);
  await frame.fill('#barrierepruefung-site-id', siteId);
  await frame.fill('#barrierepruefung-token', mit);
  frame = await neuGeladen(page, () => frame.locator('#barrierepruefung-verbinden button[type=submit]').click());
  pruefe(await frame.locator('#barrierepruefung-trennen').count() === 1, 'verbunden');
}

// SQL in der Instanz (SQLite, Pfad aus den Einstellungen) – nur für den realName der Backend-Person.
const sql = (abfrage) => {
  const php = '$p=new PDO("sqlite:".(require "config/system/settings.php")["DB"]["Connections"]["Default"]["path"]);$p->exec($argv[1]);';
  execFileSync('docker', ['compose', 'exec', '-T', `v${inst.fassung}`, 'php', '-r', php, '--', abfrage], { cwd: new URL('../lokal/', import.meta.url).pathname, encoding: 'utf8' });
};

// Die Stand-Zeile der Seite mit dem Element auf der zweiten Site.
async function standImFrontend() {
  const html = await (await fetch(`${inst.echt}/barrierefreiheit`)).text();
  return (html.match(/<p class="a11y-erklaerung-stand">(.*?)<\/p>/s)?.[1] ?? '').replace(/<[^>]+>/g, '');
}

async function versionen() {
  await atemholen();
  const antwort = await fetch(`${dienst}/sites/${siteId}/declaration/versions`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
  return antwort.ok ? (await antwort.json()).data : [];
}

// Tab bis zum Knopf mit diesem Text, Enter, neu geladener Rahmen.
async function absenden(frame, text) {
  await atemholen();
  await tabBis(page, frame, (f) => text.test(f.text), protokoll, 30);
  return enterUndWarten(page);
}

let fehler = 0;
try {
  console.log(`== ${inst.name} gegen ${dienst}`);
  await anmelden(page, inst.basis);
  await farbschema(page, 'light');
  await verbinden(token);

  // --- Übersicht: dieselben Schritte wie die API ---
  // Die Texte folgen der Sprache der Backend-Person (Accept-Language): beide Fassungen holen.
  const weg = await api(`sites/${siteId}/declaration/path`);
  const wegEn = await api(`sites/${siteId}/declaration/path`, 'en');
  let frame = (await atemholen(), await modulOeffnen(page, inst.basis, MODUL, { site: 'zweite-site' }));
  const zeilen = frame.locator('#barrierepruefung-schritte tbody tr');
  pruefe(await zeilen.count() === weg.steps.length, `${weg.steps.length} Schritte wie die API`);
  for (let i = 0; i < weg.steps.length; i++) {
    const zeile = await zeilen.nth(i).innerText();
    const titel = [weg.steps[i].title, wegEn.steps[i].title].find((t) => zeile.includes(t));
    pruefe(titel !== undefined, `Zeile ${i + 1}: „${titel ?? weg.steps[i].title}“ (Wortlaut des Dienstes)`);
  }
  await bild('uebersicht-hell');
  await axe(frame, 'Übersicht');

  // --- Prüfschritte: Notiz der ersten Frage ändern, dann zurück ---
  const lauf = weg.scan.id;
  const vorher = (await api(`scans/${lauf}/manual-checks`)).filter((c) => true);
  frame = await schritt('pruefschritte');
  if (vorher.length) {
    const erste = vorher[0];
    const ersteEn = (await api(`scans/${lauf}/manual-checks`, 'en')).find((c) => c.id === erste.id);
    pruefe(await frame.locator(`h3:has-text("${erste.title}"), h3:has-text("${ersteEn.title}")`).count() === 1, `Frage im Wortlaut des Dienstes: „${erste.title}“ / „${ersteEn.title}“`);
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.name === `checks[${erste.id}][note]`, protokoll, 40);
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type('Aus TYPO3 geprüft (Abnahme Task 17).');
    frame = await absenden(frame, /^(Antworten speichern|Save answers)$/);
    let nachher = (await api(`scans/${lauf}/manual-checks`)).find((c) => c.id === erste.id);
    pruefe(nachher.note === 'Aus TYPO3 geprüft (Abnahme Task 17).' && nachher.answer === erste.answer, 'Dienst hat die Notiz, die Antwort ist unverändert');
    pruefe(await frame.locator('.callout-success[role=status]').count() === 1, 'Rückmeldung „Gespeichert“');
    frame = await schritt('pruefschritte');
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.name === `checks[${erste.id}][note]`, protokoll, 40);
    await page.keyboard.press('ControlOrMeta+a');
    if (erste.note) await page.keyboard.type(erste.note); else await page.keyboard.press('Backspace');
    frame = await absenden(frame, /^(Antworten speichern|Save answers)$/);
    nachher = (await api(`scans/${lauf}/manual-checks`)).find((c) => c.id === erste.id);
    pruefe((nachher.note ?? '') === (erste.note ?? ''), 'Notiz wieder wie vorher');
  } else {
    pruefe(/Keine Frage|No question/.test(await frame.locator('.module-body').innerText()), 'keine sperrende Frage: Satz statt Formular');
  }

  // --- Wer erklärt: Land wählen, dann zurück zu Bund ---
  const stelleVorher = await api(`sites/${siteId}/declaring-party`);
  frame = await schritt('verantwortliche_stelle');
  await inDenRahmen(page);
  await tabBis(page, frame, (f) => f.name === 'subdivision', protokoll, 40);
  await page.keyboard.type('Nieder');
  frame = await absenden(frame, /^(Speichern|Save)$/);
  pruefe((await api(`sites/${siteId}/declaring-party`)).subdivision === 'NI', 'Dienst: subdivision NI');
  frame = await schritt('verantwortliche_stelle');
  await bild('stelle-hell');
  await axe(frame, 'Wer erklärt');

  // --- Betroffenheit: als Privatwirtschaft beantworten (die Frage stellt sich öffentlichen Stellen nicht) ---
  await inDenRahmen(page);
  await tabBis(page, frame, (f) => f.name === 'applies_to', protokoll, 40);
  await page.keyboard.press('ArrowDown');
  frame = await absenden(frame, /^(Speichern|Save)$/);
  pruefe((await api(`sites/${siteId}/declaring-party`)).applies_to === 'business', 'Dienst: Privatwirtschaft');
  frame = await schritt('betroffenheit');
  pruefe(await frame.locator('#barrierepruefung-betroffenheit input[name="services[]"]').count() > 0, 'Betroffenheit: Katalog aus der API als Kästchen');
  await bild('betroffenheit-hell');
  await axe(frame, 'Betroffenheit');
  frame = await absenden(frame, /^(Antwort speichern|Save answer)$/);
  const betroffen = await api(`sites/${siteId}/applicability`);
  pruefe(betroffen.answered_at !== null, `Dienst: Betroffenheit beantwortet (${betroffen.answered_at})`);
  // Zurück: öffentliche Stelle, Bund.
  frame = await schritt('verantwortliche_stelle');
  await inDenRahmen(page);
  await tabBis(page, frame, (f) => f.name === 'applies_to', protokoll, 40);
  await page.keyboard.press('ArrowUp');
  await tabBis(page, frame, (f) => f.name === 'subdivision', protokoll, 6);
  await page.keyboard.type('Bund');
  frame = await absenden(frame, /^(Speichern|Save)$/);
  const stelleNachher = await api(`sites/${siteId}/declaring-party`);
  pruefe(stelleNachher.applies_to === stelleVorher.applies_to && stelleNachher.subdivision === stelleVorher.subdivision, `Wer erklärt wieder wie vorher (${stelleNachher.applies_to}, ${stelleNachher.subdivision})`);

  // --- Pflichtangaben: Kontakt leeren → Fehler am Feld; dann der alte Wert ---
  const entwurf = await api(`sites/${siteId}/declaration/draft`);
  const kontakt = entwurf.fields.find((f) => f.key === 'contact').value;
  frame = await schritt('pflichtangaben');
  await inDenRahmen(page);
  await tabBis(page, frame, (f) => f.id === 'bp-contact', protokoll, 40);
  await page.keyboard.press('ControlOrMeta+a');
  await page.keyboard.press('Backspace');
  frame = await absenden(frame, /^(Angaben speichern|Save details)$/);
  pruefe(await frame.locator('.callout-danger[role=alert] a[href="#bp-contact"]').count() === 1, 'Fehler des Dienstes in der Liste, mit Sprungmarke');
  pruefe(await frame.locator('#bp-contact[aria-invalid=true]').count() === 1 && /bp-contact-fehler/.test(await frame.locator('#bp-contact').getAttribute('aria-describedby')), 'Feld mit aria-invalid und aria-describedby');
  console.log(`  Fehlertext des Dienstes: „${(await frame.locator('#bp-contact-fehler').innerText()).trim()}“`);
  const f = await fokus(frame);
  pruefe(f?.id === 'bp-contact' && f.sichtbar, 'Fokus sichtbar im fehlerhaften Feld');
  await bild('pflichtangaben-fehler-hell');
  await axe(frame, 'Pflichtangaben mit Fehler');
  await farbschema(page, 'dark');
  await page.reload();
  frame = await schritt('pflichtangaben');
  await inDenRahmen(page);
  await tabBis(page, frame, (f) => f.id === 'bp-contact', protokoll, 40);
  await page.keyboard.press('ControlOrMeta+a');
  await page.keyboard.type(kontakt);
  await bild('pflichtangaben-dunkel');
  frame = await absenden(frame, /^(Angaben speichern|Save details)$/);
  pruefe((await api(`sites/${siteId}/declaration/draft`)).fields.find((x) => x.key === 'contact').value === kontakt, 'Kontakt wieder wie vorher');
  frame = (await atemholen(), await modulOeffnen(page, inst.basis, MODUL, { site: 'zweite-site' }));
  await bild('uebersicht-dunkel');
  await farbschema(page, 'light');

  // --- Freigabe (nur mit ABNAHME_FREIGEBEN=1: legt im Dienst eine Fassung an) ---
  if (process.env.ABNAHME_FREIGEBEN === '1') {
    sql(`UPDATE be_users SET realName='${inst.realName}' WHERE username='admin'`);
    const erwarteterName = inst.realName || 'admin';
    const vorherListe = await versionen();
    const vorherFassung = vorherListe[0]?.version ?? 0;
    const vorherFrontend = await standImFrontend();
    pruefe(vorherFrontend.includes(`Version ${vorherFassung}`), `Frontend vorher: „${vorherFrontend}“`);
    const entwurfFreigabe = await api(`sites/${siteId}/declaration/draft`);
    frame = (await atemholen(), await modulOeffnen(page, inst.basis, MODUL, { site: 'zweite-site' }));
    frame = await absenden(frame, /^(Entwurf prüfen und freigeben|Review the draft and publish)$/);
    pruefe(/\/weg\/freigabe/.test(frame.url()), 'Übersicht führt zur Freigabe');
    pruefe(await frame.locator('#barrierepruefung-vorschau h1, #barrierepruefung-vorschau h2').count() === 0 && (await frame.locator('#barrierepruefung-vorschau :is(h3,h4,h5,h6)').first().evaluate((e) => e.tagName)) === 'H3', 'Vorschau des Dienstes ab h3');
    pruefe(await frame.locator('input[name=draft_etag]').inputValue() === entwurfFreigabe.draft_etag && Number(await frame.locator('input[name=expected_version]').inputValue()) === entwurfFreigabe.next_version, `Formular trägt draft_etag und expected_version ${entwurfFreigabe.next_version} des Dienstes`);
    pruefe(await frame.locator(`#barrierepruefung-freigabe button[type=submit]:has-text("${entwurfFreigabe.next_version}")`).count() === 1, `ein Knopf für Fassung ${entwurfFreigabe.next_version}`);
    pruefe(await frame.locator('input[name=conformance_status]').count() === entwurfFreigabe.conformance.choices.length + (entwurfFreigabe.conformance.choices.includes(entwurfFreigabe.conformance.suggested) ? 0 : 1), `Aussagen wie vom Dienst (${entwurfFreigabe.conformance.choices.join(', ')}; ermittelt ${entwurfFreigabe.conformance.suggested})`);
    pruefe((await frame.locator('.module-body').innerText()).includes(erwarteterName), `„Freigegeben von“ nennt „${erwarteterName}“`);
    await bild('freigabe-hell');
    await axe(frame, 'Freigabe');
    await frame.locator('#barrierepruefung-freigabe').scrollIntoViewIfNeeded();
    await bild('freigabe-formular-hell');

    // Doppelklick: Enter auf dem Knopf und im selben Augenblick dasselbe Formular ein zweites Mal (aus dem Hauptfenster,
    // damit die Navigation des Rahmens die Anfrage nicht abbricht).
    await inDenRahmen(page);
    await tabBis(page, frame, (f) => f.tag === 'button' && /veröffentlichen|Publish version/.test(f.text), protokoll, 60);
    const felder = await frame.evaluate(() => [...new FormData(document.getElementById('barrierepruefung-freigabe'))]);
    const ziel = await frame.locator('#barrierepruefung-freigabe').getAttribute('action');
    await atemholen();
    const zweites = page.evaluate(({ ziel, felder }) => fetch(ziel, { method: 'POST', body: new URLSearchParams(felder), redirect: 'manual', credentials: 'same-origin' }).then((r) => r.status), { ziel, felder });
    frame = await neuGeladen(page, () => page.keyboard.press('Enter'));
    console.log(`     zweites Absenden: Status ${await zweites}`);
    const nachherListe = await versionen();
    const neue = nachherListe.filter((v) => v.version > vorherFassung);
    pruefe(neue.length === 1 && neue[0].version === entwurfFreigabe.next_version, `genau eine neue Fassung im Dienst: ${neue.map((v) => v.version).join(', ')} (vorher ${vorherFassung})`);
    console.log(`     FASSUNG ${inst.name}: Website ${siteId}, Fassung ${neue[0].version}, ${neue[0].conformance_status}, ${neue[0].published_at}`);
    pruefe(await frame.locator(`[role=status]:has-text("${neue[0].version}")`).count() === 1, 'Rückmeldung nennt die Fassung: ' + (await frame.locator('[role=status]').allInnerTexts()).join(' | ').replace(/\s+/g, ' '));
    const nachherFrontend = await standImFrontend();
    pruefe(nachherFrontend.includes(`Version ${neue[0].version}`), `Frontend zeigt sofort die neue Fassung: „${nachherFrontend}“`);
    await bild('freigabe-erfolg-hell');
    await farbschema(page, 'dark');
    frame = (await atemholen(), await modulOeffnen(page, inst.basis, `${MODUL}_freigabe`, { site: 'zweite-site' }));
    await bild('freigabe-dunkel');
    await axe(frame, 'Freigabe dunkel');
    await farbschema(page, 'light');
    sql(`UPDATE be_users SET realName='' WHERE username='admin'`);
  }

  // --- Token ohne Recht: nur Sätze ---
  await verbinden(ohneRecht);
  for (const s of ['pruefschritte', 'verantwortliche_stelle', 'betroffenheit', 'pflichtangaben']) {
    frame = await schritt(s);
    const text = await frame.locator('.module-body').innerText();
    pruefe(await frame.locator('.module-body form').count() === 0 && /Reiter „Einbindung“|“Embedding” tab/.test(text), `ohne Recht (${s}): kein Formular, Satz mit dem Weg`);
  }
  await bild('ohne-recht-hell');
  await axe(frame, 'ohne Recht');
  frame = (await atemholen(), await modulOeffnen(page, inst.basis, `${MODUL}_freigabe`, { site: 'zweite-site' }));
  pruefe(await frame.locator('.module-body form, .module-body button').count() === 0 && /Reiter „Einbindung“|“Embedding” tab/.test(await frame.locator('.module-body').innerText()), 'ohne Recht (Freigabe): Vorschau, aber kein Formular und kein Knopf');
  await bild('freigabe-ohne-recht-hell');
  frame = (await atemholen(), await modulOeffnen(page, inst.basis, MODUL, { site: 'zweite-site' }));
  pruefe(await frame.locator('.module-body form').count() === 0, 'Übersicht ohne Recht: kein Formular');
  await farbschema(page, 'dark');
  frame = await schritt('pflichtangaben');
  await bild('ohne-recht-dunkel');
  await farbschema(page, 'auto');

  pruefe(protokoll.unsichtbar.length === 0, `Fokus an allen ${protokoll.halte.length} Halten sichtbar`);
  if (protokoll.unsichtbar.length) console.log(protokoll.unsichtbar.join('\n'));
  console.log(`ok ${inst.name}`);
} catch (e) {
  fehler++;
  console.error(`FEHLER ${inst.name}: ${e.message}`);
  if (protokoll.halte.length) console.error('  Halte: ' + protokoll.halte.slice(-8).join(' → '));
  await page.screenshot({ path: `${ERGEBNISSE}abnahme-weg-${inst.name}-fehler.png` }).catch(() => {});
} finally {
  // Immer wieder mit dem Token mit Recht verbinden.
  try { await farbschema(page, 'auto'); await verbinden(token); } catch (e) { console.error('Zurückverbinden gescheitert: ' + e.message); fehler++; }
}
await browser.close();
durchreicher?.close();
process.exit(fehler ? 1 : 0);
