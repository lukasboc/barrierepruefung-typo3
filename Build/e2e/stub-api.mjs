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

const zustand = { ausfall: false, version: null, anfragen: [] };

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
  ['POST', /^\/sites\/[^/]+\/scans$/, 'scan-start', 202],
  ['POST', /^\/sites\/[^/]+\/declaration\/publish$/, 'publish', 201],
];

function antwort(name) {
  const inhalt = ohneHinweis(laden(name));
  if (zustand.version !== null) {
    if (name === 'declaration') inhalt.declaration.version = zustand.version;
    if (name === 'publish') inhalt.data.version = zustand.version;
  }
  return inhalt;
}

function senden(res, status, body, kopf = {}) {
  const text = body === undefined ? '' : JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', ...kopf });
  res.end(text);
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
    if (aktion === 'fassung' && req.method === 'POST') { zustand.version = Number(daten.version); return senden(res, 200, { version: zustand.version }); }
    if (aktion === 'anfragen' && req.method === 'GET') return senden(res, 200, zustand.anfragen);
    if (aktion === 'zuruecksetzen' && req.method === 'POST') { zustand.ausfall = false; zustand.version = null; zustand.anfragen = []; return senden(res, 200, { ok: true }); }
    return senden(res, 404, { fehler: 'unbekannt' });
  }

  zustand.anfragen.push({ methode: req.method, pfad: url.pathname + url.search, kopfzeilen: req.headers, rumpf: text });

  if (!url.pathname.startsWith('/api/v1/')) return senden(res, 404, { title: 'Nicht gefunden' });
  if (zustand.ausfall) return senden(res, 503, { title: 'Dienst nicht erreichbar', status: 503 }, { 'Content-Type': 'application/problem+json' });

  const pfad = url.pathname.slice('/api/v1'.length);
  const treffer = routen.find(([m, muster]) => m === req.method && muster.test(pfad));
  if (!treffer) return senden(res, 404, { title: 'Nicht gefunden', status: 404 }, { 'Content-Type': 'application/problem+json' });

  const body = antwort(treffer[2]);
  const etag = `"${createHash('sha1').update(JSON.stringify(body)).digest('hex')}"`;
  if (req.method === 'GET') {
    if (req.headers['if-none-match'] === etag) { res.writeHead(304, { ETag: etag }); return res.end(); }
    return senden(res, 200, body, { ETag: etag });
  }
  return senden(res, treffer[3], body);
}).listen(port, () => console.log(`API-Attrappe auf http://localhost:${port}/api/v1`));
