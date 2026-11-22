// Tastaturdurchgang durch die drei Untermodule (Übersicht, Befunde, Erklärung) in beiden lokalen Instanzen.
//
// Teil 1: die drei Modulläufe (modul-*.mjs) bedienen alles nur mit Tab, Umschalt+Tab, Pfeiltasten und Enter, prüfen an
// jedem Halt den sichtbaren Fokus (hilfen.mjs → tabBis) und führen axe-core über `.module-body` aus; sie scheitern
// mit Exit-Code 1. Teil 2 (unten): in jedem Untermodul wird jedes sichtbare Bedienelement per Tab erreicht, der Fokus
// verlässt den Modulrahmen wieder (keine Falle) und Umschalt+Tab führt zurück.
//
//   node stub-api.mjs &  &&  npm run test:tastatur        (INSTANZ=v14 beschränkt auf eine Instanz)
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { chromium } from 'playwright';
import { anmelden, attrappe, modulOeffnen, fokus } from './hilfen.mjs';

const INSTANZEN = [
  { name: 'v13', basis: 'http://localhost:8089' },
  { name: 'v14', basis: 'http://localhost:8090' },
].filter((i) => !process.env.INSTANZ || i.name === process.env.INSTANZ);

const LAEUFE = ['modul-uebersicht', 'modul-befunde', 'modul-erklaerung'];
const MODULE = ['barrierepruefung_uebersicht', 'barrierepruefung_befunde', 'barrierepruefung_erklaerung'];

for (const inst of INSTANZEN) {
  for (const lauf of LAEUFE) {
    test(`${inst.name} ${lauf}: Tastatur, sichtbarer Fokus, axe`, () => {
      const r = spawnSync('node', [`${lauf}.mjs`, inst.name], { cwd: new URL('./', import.meta.url).pathname, encoding: 'utf8', timeout: 240000 });
      process.stdout.write(r.stdout.split('\n').filter((z) => /bekannt:/.test(z)).join('\n') + '\n');
      assert.equal(r.status, 0, `${lauf} ${inst.name}:\n${r.stdout}\n${r.stderr}`);
      assert.match(r.stdout, /Fokus an allen \d+ Halten sichtbar/, 'der Lauf hat den sichtbaren Fokus geprüft');
      assert.match(r.stdout, /axe-core/, 'der Lauf hat axe-core ausgeführt');
      assert.doesNotMatch(r.stdout, /FEHLER/);
    });
  }
}

let browser;
before(async () => {
  await attrappe('zuruecksetzen', {});
  browser = await chromium.launch();
});
after(async () => { await browser?.close(); });

const schluessel = (f) => `${f.tag}|${f.id}|${f.name}|${f.href}|${f.text}`;

// Alle sichtbaren, tabbaren Bedienelemente des Modulrahmens (Docheader und Inhalt) als Schlüssel.
const bedienelemente = (frame) => frame.evaluate(() => [...document.querySelectorAll('a[href], button, input, select, textarea, summary, [tabindex]')]
  .filter((e) => !e.disabled && e.type !== 'hidden' && e.tabIndex >= 0 && e.checkVisibility({ visibilityProperty: true }) && !e.closest('[inert]'))
  .map((a) => `${a.tagName.toLowerCase()}|${a.id}|${a.getAttribute('name') ?? ''}|${a.getAttribute('href') ?? ''}|${(a.innerText || '').trim()}`));

for (const inst of INSTANZEN) {
  for (const modul of MODULE) {
    test(`${inst.name} ${modul}: jedes Bedienelement per Tab erreichbar, keine Falle`, async () => {
      const kontext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
      const page = await kontext.newPage();
      try {
        await anmelden(page, inst.basis);
        const frame = await modulOeffnen(page, inst.basis, modul);
        const erwartet = new Set(await bedienelemente(frame));
        assert.ok(erwartet.size > 0, 'das Modul hat Bedienelemente');

        await page.locator('iframe[name="list_frame"]').focus();
        const erreicht = new Set();
        const unsichtbar = [];
        let verlassen = false;
        for (let i = 0; i < 200 && !verlassen; i++) {
          await page.keyboard.press('Tab');
          const f = await fokus(frame);
          if (f === null) {
            // Fokus liegt nicht mehr im Modulrahmen (oder auf dessen body): raus aus dem Rahmen = keine Falle.
            verlassen = await page.evaluate(() => document.activeElement?.name !== 'list_frame');
            continue;
          }
          erreicht.add(schluessel(f));
          if (!f.sichtbar) unsichtbar.push(f.beschreibung);
        }
        assert.ok(verlassen, 'der Fokus verlässt den Modulrahmen nach höchstens 200 Tab-Drücken (keine Tastaturfalle)');
        assert.deepEqual(unsichtbar, [], 'Fokus an jedem Halt sichtbar');
        console.log(`  ${inst.name} ${modul}: ${erwartet.size} Bedienelemente, ${erreicht.size} Halte`);
        const nichtErreicht = [...erwartet].filter((k) => !erreicht.has(k));
        assert.deepEqual(nichtErreicht, [], 'jedes Bedienelement wird per Tab erreicht');

        // Umschalt+Tab führt zurück in den Rahmen.
        await page.keyboard.press('Shift+Tab');
        assert.notEqual(await fokus(frame), null, 'Umschalt+Tab führt zurück in den Modulrahmen');
      } finally {
        await kontext.close();
      }
    });
  }
}
