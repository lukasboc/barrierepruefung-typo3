// Bereinigt eine aufgenommene Antwort des Dienstes (stdin -> stdout). Aufruf:
//   node bereinigen.mjs <Website-Kennung> <Lauf-Kennung> < roh.json > name.json
// Bricht mit Fehler ab, wenn danach ein anderer Host als erlaubt oder eine fremde Adresse übrig ist.
import { readFileSync } from 'node:fs';

const [siteId, scanId] = process.argv.slice(2);
if (!siteId || !scanId) { console.error('Aufruf: node bereinigen.mjs <Website-Kennung> <Lauf-Kennung>'); process.exit(2); }

// Hosts, die nach dem Bereinigen vorkommen dürfen. schlichtungsstelle-bgg.de: öffentliche Stelle im Gesetzestext der Vorlage.
const ERLAUBTE_HOSTS = ['localhost', '127.0.0.1', 'beispiel.test', 'dienst.example', 'www.schlichtungsstelle-bgg.de'];
const ERLAUBTE_MAIL = /@beispiel\.test$/;

function text(s) {
  // Verifikationswerte (ULID-förmig, geheim genug) -> Platzhalter
  if (/^01[0-9A-Z]{24}$/.test(s) && s !== siteId && s !== scanId) return 'STUB-PLATZHALTER';
  return s
    .replaceAll(siteId, '01STUBSITE0000000000000000')
    .replaceAll(scanId, '01STUBSCAN0000000000000000')
    // jeder fremde Host (die geprüfte Website und ihre Seiten) wird die lokale Instanz
    .replace(/https?:\/\/([A-Za-z0-9.-]+)/g, (m, host) => (ERLAUBTE_HOSTS.includes(host) ? m : 'http://localhost:8089'))
    .replace(/\bbrowsertest\.example\b/g, 'beispiel.test')
    .replace(/http:\/\/127\.0\.0\.1:8001/g, 'https://dienst.example')
    .replace(/Browsertest Plugin GmbH/g, 'Beispiel GmbH')
    .replace(/browsertestexample-[a-z0-9]+/g, 'beispiel-test')
    .replace(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g, (m) => (ERLAUBTE_MAIL.test(m) ? m : 'kontakt@beispiel.test'));
}

// Fremder Text im HTML-Ausschnitt eines Befunds: Inhalt und Beschriftungen durch Platzhalter ersetzen, Struktur bleibt.
function ausschnitt(html) {
  return html
    .replace(/>([^<]+)</g, (m, t) => (t.trim() ? '>Beispieltext<' : m))
    .replace(/\b(alt|title|aria-label|placeholder|value)="[^"]*"/g, '$1="Beispiel"')
    .replace(/\b(href|src|data-src|srcset)="[^"]*"/g, (m, a) => `${a}="/beispiel"`);
}

function gehe(o, schluessel = '') {
  // Name der Admin, die das Freigaberecht erteilt hat (data.token.publish.granted_by)
  if (schluessel === 'granted_by' && typeof o === 'string') return 'Beispiel Person';
  if (typeof o === 'string') return schluessel === 'html_snippet' ? text(ausschnitt(o)) : text(o);
  if (Array.isArray(o)) return o.map((v) => gehe(v, schluessel));
  if (o && typeof o === 'object') return Object.fromEntries(Object.entries(o).map(([k, v]) => [k, gehe(v, k)]));
  return o;
}

const daten = gehe(JSON.parse(readFileSync(0, 'utf8')));
if (daten?.data?.base_url) daten.data.base_url = daten.data.base_url.replace(/\/$/, '');
const ausgabe = JSON.stringify(daten, null, 2);

const probleme = [];
for (const m of ausgabe.matchAll(/https?:(?:\\?\/){2}([A-Za-z0-9.-]+)/g)) {
  if (!ERLAUBTE_HOSTS.includes(m[1])) probleme.push(`Host ${m[1]}`);
}
for (const m of ausgabe.matchAll(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g)) {
  if (!ERLAUBTE_MAIL.test(m[0])) probleme.push(`Adresse ${m[0]}`);
}
if (/browsertest\.example|Bearer\s/.test(ausgabe)) probleme.push('Rest einer Originalangabe');
if (probleme.length) { console.error('Bereinigen fehlgeschlagen: ' + [...new Set(probleme)].join(', ')); process.exit(1); }
console.log(ausgabe);
