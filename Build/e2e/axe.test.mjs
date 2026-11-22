// axe-Golden-Test des Inhaltselements: alle 18 Kombinationen (Teil × Überschriftenebene × Stand-Zeile) in beiden
// lokalen Instanzen. Seiten aus Build/lokal/seed/erklaerungsseiten.sql (`lokal.sh seiten <13|14>`), Attrappe auf :8091.
//
//   node stub-api.mjs &  &&  npm run test:axe
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';
import { attrappe } from './hilfen.mjs';
import { einordnen, beschreibung } from './bekannt.mjs';

const INSTANZEN = [
  { name: 'v13', basis: 'http://localhost:8089' },
  { name: 'v14', basis: 'http://localhost:8090' },
].filter((i) => !process.env.INSTANZ || i.name === process.env.INSTANZ);

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

let browser;
before(async () => {
  await attrappe('zuruecksetzen', {});
  browser = await chromium.launch();
});
after(async () => { await browser?.close(); });

for (const inst of INSTANZEN) {
  for (const teil of ['komplett', 'maengel', 'kontakt']) {
    for (const ebene of [2, 3, 4]) {
      for (const stand of ['mit', 'ohne']) {
        const adresse = `/erklaerung-${teil}-h${ebene}-${stand}-stand`;
        test(`${inst.name} ${adresse}`, async () => {
          const kontext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
          const page = await kontext.newPage();
          try {
            const antwort = await page.goto(inst.basis + adresse);
            assert.equal(antwort.status(), 200, `${adresse}: Status`);
            assert.equal(await page.locator('.a11y-erklaerung').count(), 1, 'genau ein Wrapper .a11y-erklaerung');
            assert.equal(await page.locator('.a11y-erklaerung-stand').count(), stand === 'mit' ? 1 : 0, 'Stand-Zeile je nach Feld');

            const ergebnis = await new AxeBuilder({ page }).include('.a11y-erklaerung').withTags(TAGS).analyze();
            const { unbekannt, bekannt } = einordnen(ergebnis.violations, 'inhaltselement', inst.name);
            for (const b of bekannt) console.log(`  bekannt: ${b.regel} ${b.ziel} (${b.grund})`);
            assert.equal(unbekannt.length, 0, `axe-Verstöße:\n${beschreibung(unbekannt)}`);

            // Überschriftenfolge im Element: keine h1, erste Ebene wie eingestellt, nirgends ein Sprung über mehr als eine Ebene.
            const ebenen = await page.locator('.a11y-erklaerung').locator('h1, h2, h3, h4, h5, h6').evaluateAll((k) => k.map((h) => Number(h.tagName[1])));
            assert.ok(ebenen.length > 0, 'das Element hat Überschriften');
            assert.ok(!ebenen.includes(1), 'keine h1 im Element');
            assert.equal(ebenen[0], ebene, `erste Überschrift ist h${ebene}`);
            ebenen.forEach((e, i) => { if (i) assert.ok(e - ebenen[i - 1] <= 1, `Sprung h${ebenen[i - 1]} → h${e}: ${ebenen.join(',')}`); });
          } finally {
            await page.screenshot({ path: new URL(`./ergebnisse/axe-${inst.name}${adresse.replace(/\//g, '-')}.png`, import.meta.url).pathname }).catch(() => {});
            await kontext.close();
          }
        });
      }
    }
  }
}
