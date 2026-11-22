// Bekannte Befunde, die nicht aus dem Markup der Erweiterung stammen (Website-Paket, TYPO3-Kern). Jeder Eintrag
// nennt Regel, Instanz, Selektor und Grund in bekannte-befunde.json; alles andere lässt den Test scheitern.
import { readFileSync } from 'node:fs';

const LISTE = JSON.parse(readFileSync(new URL('./bekannte-befunde.json', import.meta.url), 'utf8'));

for (const e of LISTE) {
  for (const feld of ['bereich', 'regel', 'instanz', 'selektor', 'grund']) {
    if (!e[feld]) throw new Error(`bekannte-befunde.json: Eintrag ohne „${feld}“: ${JSON.stringify(e)}`);
  }
}

// Trennt axe-Verstöße in unbekannte (Test scheitert) und bekannte (werden ausgegeben).
// bereich: 'inhaltselement' | 'modul'; instanz: 'v13' | 'v14'.
export function einordnen(verstoesse, bereich, instanz) {
  const unbekannt = [];
  const bekannt = [];
  for (const v of verstoesse) {
    for (const n of v.nodes) {
      const ziel = n.target.join(' ');
      const eintrag = LISTE.find((e) => e.bereich === bereich && e.instanz === instanz && e.regel === v.id && ziel.includes(e.selektor));
      (eintrag ? bekannt : unbekannt).push({ regel: v.id, wirkung: v.impact, ziel, grund: eintrag?.grund, html: n.html.slice(0, 160) });
    }
  }
  return { unbekannt, bekannt };
}

export const beschreibung = (liste) => liste.map((u) => `${u.regel} (${u.wirkung}) ${u.ziel}  ${u.html}`).join('\n');
