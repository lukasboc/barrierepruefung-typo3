// Gemeinsame Hilfen der Ende-zu-Ende-Läufe.
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { einordnen, beschreibung } from './bekannt.mjs';

export const ATTRAPPE = 'http://localhost:8091';
// Wie die Instanz die Attrappe erreicht: aus Docker über host.docker.internal, in der CI (php -S) direkt über localhost.
export const AUS_DEM_CONTAINER = process.env.E2E_DIENST_URL ?? 'http://host.docker.internal:8091/api/v1';
export const ZUGANG = { benutzer: 'admin', passwort: 'Lokal-Admin-1!' };

export async function anmelden(page, basis) {
  await page.goto(`${basis}/typo3/`);
  await page.fill('#t3-username', ZUGANG.benutzer);
  await page.fill('#t3-password', ZUGANG.passwort);
  await page.click('#t3-login-submit');
  await page.waitForSelector('.scaffold', { timeout: 60000 });
}

// Öffnet ein Backend-Modul über seine Kennung (z. B. "barrierepruefung_uebersicht" → /typo3/module/barrierepruefung/uebersicht)
// und liefert den Frame des Inhaltsrahmens. TYPO3 lädt jedes Modul in das iframe "list_frame" (13.4 und 14.3 gleich);
// ein direkter Aufruf der Moduladresse leitet dorthin um.
export async function modulOeffnen(page, basis, modul, parameter = {}) {
  const suche = new URLSearchParams(parameter).toString();
  await page.goto(`${basis}/typo3/module/${modul.replace(/_/g, '/')}${suche ? `?${suche}` : ''}`);
  return modulRahmen(page);
}

// Der Frame des Modulinhalts, sobald sein Inhalt steht.
export async function modulRahmen(page) {
  // Das iframe steht oft schon im DOM, bevor Playwright seinen Frame kennt.
  for (let i = 0; i < 300; i++) {
    const frame = page.frame({ name: 'list_frame' });
    if (frame) {
      await frame.waitForSelector('.module-body', { timeout: 30000 });
      return frame;
    }
    await page.waitForTimeout(100);
  }
  throw new Error('Modulrahmen list_frame erscheint nicht');
}

export async function keinQuerscrollen(page) {
  return page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
}

// Führt aktion() aus (Klick, Enter) und wartet, bis der Modulrahmen neu geladen ist. Nach POST → 303 kann die
// Adresse gleich bleiben; deshalb auf das Navigationsereignis warten, nicht auf eine andere URL.
export async function neuGeladen(page, aktion) {
  const geladen = page.waitForEvent('framenavigated', { predicate: (f) => f.name() === 'list_frame', timeout: 30000 });
  await aktion();
  const frame = await geladen;
  await frame.waitForLoadState('load');
  return modulRahmen(page);
}

// Schalter der API-Attrappe: attrappe('zuruecksetzen', {}) / attrappe('anfragen') (ohne Daten = GET).
export async function attrappe(aktion, daten) {
  const antwort = await fetch(`${ATTRAPPE}/__stub/${aktion}`, daten === undefined ? {} : { method: 'POST', body: JSON.stringify(daten) });
  return antwort.json();
}

// Der fokussierte Knoten im Modulrahmen und ob man ihn sieht (Umriss oder Schatten).
export async function fokus(frame) {
  return frame.evaluate(() => {
    const a = document.activeElement;
    if (!a || a === document.body) return null;
    const s = getComputedStyle(a);
    const outline = s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0;
    const schatten = s.boxShadow !== 'none' && s.boxShadow !== '';
    return {
      beschreibung: `${a.tagName.toLowerCase()}${a.id ? '#' + a.id : ''}${a.name ? '[name=' + a.name + ']' : ''} „${(a.innerText || a.value || a.getAttribute('aria-label') || '').trim().slice(0, 50)}“`,
      text: (a.innerText || '').trim(),
      id: a.id,
      tag: a.tagName.toLowerCase(),
      name: a.getAttribute('name') ?? '',
      href: a.getAttribute('href') ?? '',
      form: a.getAttribute('form') ?? '',
      autofocus: a.hasAttribute('autofocus'),
      sichtbar: outline || schatten,
      stil: `outline: ${s.outlineStyle} ${s.outlineWidth}; box-shadow: ${s.boxShadow}`,
    };
  });
}

// Drückt Tab, bis passt(fokus) gilt; jeder Halt im Modul muss sichtbar fokussiert sein, sonst landet er im Protokoll.
export async function tabBis(page, frame, passt, protokoll, max = 60) {
  for (let i = 0; i < max; i++) {
    await page.keyboard.press('Tab');
    const f = await fokus(frame);
    if (f === null) continue;
    protokoll.halte.push(f.beschreibung);
    if (!f.sichtbar) protokoll.unsichtbar.push(`${f.beschreibung} (${f.stil})`);
    if (passt(f)) return f;
  }
  throw new Error('Nach ' + max + ' Tab-Drücken nicht erreicht');
}

// Fokus in den Modulrahmen setzen, ohne zu klicken: das iframe selbst fokussieren, dann führt Tab hinein.
export async function inDenRahmen(page) {
  await page.locator('iframe[name="list_frame"]').focus();
}

// Enter auf Link oder Knopf, dann den neu geladenen Modulrahmen.
export const enterUndWarten = (page) => neuGeladen(page, () => page.keyboard.press('Enter'));

// Derselbe Aufruf wie der Schalter „Farbschema“ im Benutzermenü (uc.colorScheme).
export async function farbschema(page, schema) {
  const status = await page.evaluate(async (s) => {
    const antwort = await fetch(TYPO3.settings.ajaxUrls.color_scheme_update, { method: 'POST', body: new URLSearchParams({ colorScheme: s }) });
    return antwort.status;
  }, schema);
  if (status !== 200) throw new Error(`Farbschema ${schema}: Status ${status}`);
}

const AXE = readFileSync(new URL('./node_modules/axe-core/axe.min.js', import.meta.url), 'utf8');

// axe-core über den Modulinhalt (.module-body), ohne `landmark-one-main` und `region`: der Modulrahmen ist ein
// iframe-Dokument des TYPO3-Kerns. Liefert die Verstöße, die nicht in bekannte-befunde.json stehen, als Sätze;
// bekannte werden ausgegeben. instanz: 'v13' | 'v14'.
export async function axeModul(frame, instanz) {
  await frame.evaluate(AXE);
  const verstoesse = await frame.evaluate(async () => (await axe.run({ include: [['.module-body']] }, {
    runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'],
    rules: { 'landmark-one-main': { enabled: false }, region: { enabled: false } },
  })).violations);
  const { unbekannt, bekannt } = einordnen(verstoesse, 'modul', instanz);
  for (const b of bekannt) console.log(`  bekannt: ${b.regel} ${b.ziel} (${b.grund})`);
  return unbekannt.length ? [beschreibung(unbekannt)] : [];
}

// vendor/bin/typo3 der Instanz: in der CI direkt im Projektordner (E2E_TYPO3_DIR), lokal im Docker-Container.
export function typo3Befehl(inst, ...argumente) {
  if (process.env.E2E_TYPO3_DIR) {
    return execFileSync('php', ['vendor/bin/typo3', ...argumente], { cwd: process.env.E2E_TYPO3_DIR, encoding: 'utf8' }).trim();
  }
  return execFileSync('docker', ['exec', '-w', '/var/www/projekt', inst.container, 'vendor/bin/typo3', ...argumente], { encoding: 'utf8' }).trim();
}

// Stellt sicher, dass die Hauptsite der Instanz mit der Attrappe verbunden ist (über die Oberfläche, wie die
// Modulläufe) und ihre Erklärung abgerufen wurde. Ohne das hat das Inhaltselement nichts auszugeben.
export async function verbundenSein(browser, inst) {
  const kontext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await kontext.newPage();
  try {
    await anmelden(page, inst.basis);
    let frame = await modulOeffnen(page, inst.basis, 'barrierepruefung_uebersicht');
    const host = new URL(inst.basis).host;
    if (!(await frame.locator('h1').innerText()).includes(host)) {
      await frame.locator('.module-docheader .dropdown-toggle').first().click();
      frame = await neuGeladen(page, () => frame.locator(`.dropdown-menu a:has-text("${host}")`).first().click());
    }
    if (await frame.locator('#barrierepruefung-verbinden').count()) {
      await frame.fill('#barrierepruefung-dienst-url', AUS_DEM_CONTAINER);
      await frame.fill('#barrierepruefung-site-id', inst.siteId);
      await frame.fill('#barrierepruefung-token', 'beliebiges-token-der-attrappe');
      frame = await neuGeladen(page, () => frame.locator('#barrierepruefung-verbinden button[type=submit]').click());
      if (!(await frame.locator('dl').count())) throw new Error('Verbinden über die Oberfläche gelang nicht');
    }
  } finally {
    await kontext.close();
  }
  typo3Befehl(inst, 'barrierepruefung:aktualisieren');
}
