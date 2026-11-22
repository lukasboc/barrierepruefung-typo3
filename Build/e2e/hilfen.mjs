// Gemeinsame Hilfen der Ende-zu-Ende-Läufe.
export const ATTRAPPE = 'http://localhost:8091';
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
