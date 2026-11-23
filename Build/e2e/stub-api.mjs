// API-Attrappe des Dienstes (nur node:http). Liefert aufgenommene Antworten aus antworten/.
// Start: node stub-api.mjs [port]   (Standard 8091, Basis /api/v1)
import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const verzeichnis = join(dirname(fileURLToPath(import.meta.url)), 'antworten');
const port = Number(process.argv[2] ?? process.env.STUB_PORT ?? 8091);
const laden = (name) => JSON.parse(readFileSync(join(verzeichnis, `${name}.json`), 'utf8'));
const ohneHinweis = (o) => { const { _von_hand, ...rest } = o; return rest; };

const zustand = { ausfall: false, version: null, unbestaetigt: false, unveroeffentlicht: false, wegOffen: false, eingabefehler: false, ohneRecht: false, entwurfGeaendert: false, nichtBereit: false, freigaben: {}, anfragen: [] };

const routen = [
  ['GET', /^\/sites\/[^/]+$/, 'site'],
  ['GET', /^\/sites\/[^/]+\/verification$/, 'verification'],
  ['GET', /^\/sites\/[^/]+\/declaration$/, 'declaration'],
  ['GET', /^\/sites\/[^/]+\/declaration\/path$/, 'path'],
  ['GET', /^\/sites\/[^/]+\/declaration\/draft$/, 'draft'],
  ['GET', /^\/sites\/[^/]+\/applicability$/, 'applicability'],
  ['GET', /^\/sites\/[^/]+\/declaring-party$/, 'declaring-party'],
  ['GET', /^\/scans\/[^/]+$/, 'scan'],
  ['GET', /^\/scans\/[^/]+\/findings$/, 'findings'],
  ['GET', /^\/scans\/[^/]+\/findings\/[^/]+$/, 'findings-regel'],
  ['GET', /^\/scans\/[^/]+\/manual-checks$/, 'manual-checks'],
  ['POST', /^\/sites\/[^/]+\/verify$/, 'verify', 200],
  ['POST', /^\/sites\/[^/]+\/scans$/, 'scan-start', 202],
  ['POST', /^\/sites\/[^/]+\/declaration\/publish$/, 'publish', 201],
  // Weg zur Erklärung: die PUT antworten wie das zugehörige GET (der Dienst liefert den neuen Stand zurück).
  ['PUT', /^\/scans\/[^/]+\/manual-checks$/, 'manual-checks', 200],
  ['PUT', /^\/sites\/[^/]+\/declaring-party$/, 'declaring-party', 200],
  ['PUT', /^\/sites\/[^/]+\/applicability$/, 'applicability', 200],
  ['PUT', /^\/sites\/[^/]+\/declaration\/details$/, 'draft', 200],
];

// Schalter /__stub/weg_offen: zwei offene Schritte (mit Gründen), ein Schritt, den die Extension nicht kennt, und zwei
// sperrende Prüfschritte – die aufgenommenen Antworten sind alle erledigt.
function wegOffen(name, inhalt) {
  if (name === 'path') {
    const schritte = inhalt.data.steps;
    const p = schritte.find((s) => s.key === 'pruefschritte');
    p.state = 'offen';
    p.reasons = [{ key: 'pruefschritte_offen', text: 'Zwei manuelle Prüfschritte sind noch offen: „Ist die Fokusreihenfolge sinnvoll?“, „Haben Videos Untertitel?“' }];
    const s = schritte.find((x) => x.key === 'verantwortliche_stelle');
    s.state = 'offen';
    s.reasons = [{ key: 'land', text: 'Wählen Sie, ob die Stelle zum Bund oder zu einem Land gehört.' }];
    schritte.splice(4, 0, { key: 'neu_im_dienst', title: 'PDF-Dokumente nennen', state: 'offen', reasons: [{ key: 'pdf_fehlt', text: 'Nennen Sie die PDF-Dokumente, die nicht barrierefrei sind.' }], web_url: 'https://dienst.example/pruefung/01STUBSCAN0000000000000000/pdf' });
    inhalt.data.done = 4;
    inhalt.data.total = 7;
    inhalt.data.next = 'pruefschritte';
  }
  if (name === 'manual-checks') {
    inhalt.data = [
      { id: 11, rule: 'manual.focus-order', title: 'Ist die Fokusreihenfolge sinnvoll?', description: 'Gehen Sie mit der Tabulatortaste durch die Seite.', success_criteria: ['2.4.3'], evidence: null, answer: 'unanswered', note: null, stale: false, inherited: false, answered_at: null },
      { id: 12, rule: 'manual.captions', title: 'Haben Videos Untertitel?', description: null, success_criteria: ['1.2.2'], evidence: 'Die Prüfung hat ein Video gefunden.', answer: 'passed', note: 'Untertitel per YouTube', stale: false, inherited: true, answered_at: '2026-11-01T10:00:00+00:00' },
    ];
    inhalt.meta.total = 2;
    inhalt.meta.open = 1;
  }
  if (name === 'declaring-party') {
    inhalt.data.subdivision = null;
    inhalt.data.subdivision_required = true;
  }
  return inhalt;
}

// Zweite Website für die 14.3-Instanz: der Adressabgleich vergleicht den Port mit (localhost:8089 ≠ :8090).
const SITE_14 = '01STUBSITE0000000000000014';

function antwort(name, pfad) {
  let inhalt = ohneHinweis(laden(name));
  if (pfad.includes(SITE_14)) {
    inhalt = JSON.parse(JSON.stringify(inhalt).replaceAll('http://localhost:8089', 'http://localhost:8090').replaceAll('01STUBSITE0000000000000000', SITE_14));
  }
  // Bis zum ersten POST …/verify meldet die Website „nicht bestätigt“ (Schalter /__stub/unbestaetigt).
  if (name === 'site' && zustand.unbestaetigt) {
    inhalt.data.verified = false;
    inhalt.data.verified_at = null;
  }
  if (name === 'verify') zustand.unbestaetigt = false;
  if (zustand.wegOffen) inhalt = wegOffen(name, inhalt);
  if (name === 'site' && zustand.ohneRecht) {
    inhalt.data.token.abilities = inhalt.data.token.abilities.filter((r) => r !== 'declaration:prepare' && r !== 'declaration:publish');
    inhalt.data.token.publish = { state: zustand.ohneRecht === 'erloschen' ? 'recht_erloschen' : 'recht_fehlt' };
  }
  if (zustand.version !== null) {
    if (name === 'declaration') inhalt.declaration.version = zustand.version;
    if (name === 'publish') inhalt.data.version = zustand.version;
  }
  return inhalt;
}

// Fundstellen blättern wie der Dienst: offset/limit aus der Adresse; die aufgenommenen Einträge wiederholen sich, bis `meta.total` erreicht ist.
function blaettern(body, url) {
  const limit = Math.max(1, Math.min(100, Number(url.searchParams.get('limit') ?? body.meta.limit)));
  const offset = Math.max(0, Number(url.searchParams.get('offset') ?? 0));
  const vorlage = body.data;
  const bis = Math.min(offset + limit, body.meta.total);
  body.data = [];
  for (let i = offset; i < bis; i++) body.data.push({ ...vorlage[i % vorlage.length] });
  Object.assign(body.meta, { limit, offset });
}

function senden(res, status, body, kopf = {}) {
  const text = body === undefined ? '' : JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', ...kopf });
  res.end(text);
}

// POST …/declaration/publish wie der Dienst: ohne Idempotency-Key 400; ein Schlüssel, unter dem schon eine Fassung
// liegt, bekommt deren Antwort mit `Idempotent-Replayed: true` (Fehler werden nicht gespeichert). Die neue Fassung trägt
// `expected_version` und wird die veröffentlichte (GET …/declaration).
function freigeben(req, res, text) {
  const problem = { 'Content-Type': 'application/problem+json' };
  const schluessel = String(req.headers['idempotency-key'] ?? '').trim();
  if (schluessel === '') return senden(res, 400, { message: 'Eine Veröffentlichung verlangt den Header Idempotency-Key.' }, problem);
  if (zustand.freigaben[schluessel]) return senden(res, 201, zustand.freigaben[schluessel], { 'Idempotent-Replayed': 'true' });
  if (zustand.ohneRecht) return senden(res, 403, { message: 'Diesem Token fehlt das Recht, die Erklärung zu veröffentlichen.', reason: zustand.ohneRecht === 'erloschen' ? 'recht_erloschen' : 'recht_fehlt' }, problem);
  if (zustand.nichtBereit) return senden(res, 422, { message: 'Die Erklärung kann noch nicht veröffentlicht werden.', reason: 'nicht_bereit', blockers: [{ key: 'land', text: 'Wählen Sie, ob die Stelle zum Bund oder zu einem Land gehört.' }] }, problem);
  if (zustand.entwurfGeaendert) return senden(res, 409, { message: 'Der Entwurf hat sich seit Ihrer Vorschau geändert. Bitte sehen Sie ihn sich noch einmal an.', reason: 'entwurf_geaendert', draft_etag: 'geaendert0000000000000000000000' }, problem);
  let daten = {};
  try { daten = JSON.parse(text || '{}'); } catch { /* wie leer */ }
  const body = ohneHinweis(laden('publish'));
  body.data.version = Number(daten.expected_version) || body.data.version;
  body.data.conformance_status = daten.conformance_status ?? laden('draft').data.conformance.suggested;
  zustand.freigaben[schluessel] = body;
  zustand.version = body.data.version;
  return senden(res, 201, body);
}

async function rumpf(req) {
  const teile = [];
  for await (const t of req) teile.push(t);
  return Buffer.concat(teile).toString('utf8');
}

createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host ?? 'localhost'}`);
  const text = await rumpf(req);

  if (url.pathname.startsWith('/__stub/')) {
    const aktion = url.pathname.slice('/__stub/'.length);
    let daten = {};
    try { daten = text ? JSON.parse(text) : {}; } catch { return senden(res, 400, { fehler: 'kein JSON' }); }
    if (aktion === 'ausfall' && req.method === 'POST') { zustand.ausfall = Boolean(daten.an); return senden(res, 200, { ausfall: zustand.ausfall }); }
    if (aktion === 'unbestaetigt' && req.method === 'POST') { zustand.unbestaetigt = Boolean(daten.an); return senden(res, 200, { unbestaetigt: zustand.unbestaetigt }); }
    if (aktion === 'unveroeffentlicht' && req.method === 'POST') { zustand.unveroeffentlicht = Boolean(daten.an); return senden(res, 200, { unveroeffentlicht: zustand.unveroeffentlicht }); }
    if (aktion === 'weg_offen' && req.method === 'POST') { zustand.wegOffen = Boolean(daten.an); return senden(res, 200, { wegOffen: zustand.wegOffen }); }
    if (aktion === 'eingabefehler' && req.method === 'POST') { zustand.eingabefehler = Boolean(daten.an); return senden(res, 200, { eingabefehler: zustand.eingabefehler }); }
    // {"an": true} = recht_fehlt, {"an": "erloschen"} = recht_erloschen
    if (aktion === 'ohne_recht' && req.method === 'POST') { zustand.ohneRecht = daten.an === 'erloschen' ? 'erloschen' : Boolean(daten.an); return senden(res, 200, { ohneRecht: zustand.ohneRecht }); }
    // Freigabe: {"an": true} – jedes POST …/declaration/publish antwortet 409 entwurf_geaendert bzw. 422 nicht_bereit (Blocker `land`).
    if (aktion === 'entwurf_geaendert' && req.method === 'POST') { zustand.entwurfGeaendert = Boolean(daten.an); return senden(res, 200, { entwurfGeaendert: zustand.entwurfGeaendert }); }
    if (aktion === 'nicht_bereit' && req.method === 'POST') { zustand.nichtBereit = Boolean(daten.an); return senden(res, 200, { nichtBereit: zustand.nichtBereit }); }
    if (aktion === 'fassung' && req.method === 'POST') { zustand.version = Number(daten.version); return senden(res, 200, { version: zustand.version }); }
    if (aktion === 'anfragen' && req.method === 'GET') return senden(res, 200, zustand.anfragen);
    if (aktion === 'zuruecksetzen' && req.method === 'POST') { zustand.ausfall = false; zustand.version = null; zustand.unbestaetigt = false; zustand.unveroeffentlicht = false; zustand.wegOffen = false; zustand.eingabefehler = false; zustand.ohneRecht = false; zustand.entwurfGeaendert = false; zustand.nichtBereit = false; zustand.freigaben = {}; zustand.anfragen = []; return senden(res, 200, { ok: true }); }
    return senden(res, 404, { fehler: 'unbekannt' });
  }

  zustand.anfragen.push({ methode: req.method, pfad: url.pathname + url.search, kopfzeilen: req.headers, rumpf: text });

  if (!url.pathname.startsWith('/api/v1/')) return senden(res, 404, { title: 'Nicht gefunden' });
  if (zustand.ausfall) return senden(res, 503, { title: 'Dienst nicht erreichbar', status: 503 }, { 'Content-Type': 'application/problem+json' });

  const pfad = url.pathname.slice('/api/v1'.length);
  const treffer = routen.find(([m, muster]) => m === req.method && muster.test(pfad));
  if (!treffer) return senden(res, 404, { title: 'Nicht gefunden', status: 404 }, { 'Content-Type': 'application/problem+json' });

  // Noch nichts veröffentlicht (Schalter /__stub/unveroeffentlicht): die Erklärung gibt es nicht, der Rest antwortet wie sonst.
  if (zustand.unveroeffentlicht && treffer[2] === 'declaration') return senden(res, 404, { title: 'Keine veröffentlichte Erklärung', status: 404, detail: 'Für diese Website ist keine Erklärung veröffentlicht.', reason: 'keine_erklaerung' }, { 'Content-Type': 'application/problem+json' });

  if (req.method === 'PUT' && zustand.ohneRecht) return senden(res, 403, { message: 'Diesem Token fehlt das Recht, die Erklärung vorzubereiten und zu veröffentlichen.', reason: zustand.ohneRecht === 'erloschen' ? 'recht_erloschen' : 'recht_fehlt', url: 'https://dienst.example/websites/01STUBSITE0000000000000000/einbindung' }, { 'Content-Type': 'application/problem+json' });
  // Schalter /__stub/eingabefehler: die Pflichtangaben scheitern an einem leeren Kontakt (wie der Dienst: 422 mit errors je Feld).
  if (req.method === 'PUT' && zustand.eingabefehler && treffer[2] === 'draft') return senden(res, 422, { message: 'Ohne Kontakt kann niemand eine Barriere melden — das ist Pflichtangabe.', errors: { contact: ['Ohne Kontakt kann niemand eine Barriere melden — das ist Pflichtangabe.'] } }, { 'Content-Type': 'application/problem+json' });

  if (treffer[2] === 'publish') return freigeben(req, res, text);

  const body = antwort(treffer[2], pfad);
  if (treffer[2] === 'findings-regel') blaettern(body, url);
  const etag = `"${createHash('sha1').update(JSON.stringify(body)).digest('hex')}"`;
  if (req.method === 'GET') {
    if (req.headers['if-none-match'] === etag) { res.writeHead(304, { ETag: etag }); return res.end(); }
    return senden(res, 200, body, { ETag: etag });
  }
  return senden(res, treffer[3], body);
}).listen(port, () => console.log(`API-Attrappe auf http://localhost:${port}/api/v1`));
