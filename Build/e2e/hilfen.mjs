// Gemeinsame Hilfen der Ende-zu-Ende-Läufe.
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
  await page.waitForSelector('iframe[name="list_frame"]');
  const frame = page.frame({ name: 'list_frame' });
  await frame.waitForSelector('.module-body', { timeout: 30000 });
  return frame;
}

export async function keinQuerscrollen(page) {
  return page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
}
