// Gemeinsame Hilfen der Ende-zu-Ende-Läufe.
export const ZUGANG = { benutzer: 'admin', passwort: 'Lokal-Admin-1!' };

export async function anmelden(page, basis) {
  await page.goto(`${basis}/typo3/`);
  await page.fill('#t3-username', ZUGANG.benutzer);
  await page.fill('#t3-password', ZUGANG.passwort);
  await page.click('#t3-login-submit');
  await page.waitForSelector('.scaffold', { timeout: 60000 });
}

// Öffnet ein Backend-Modul über seine Kennung (z. B. "web_barrierepruefung") und liefert den Inhaltsrahmen.
// Noch ungeprüft: das Modul entsteht erst in einer späteren Aufgabe; Adresse und Rahmenwähler dort anpassen.
export async function modulOeffnen(page, basis, untermodul) {
  await page.goto(`${basis}/typo3/module/${untermodul.replace(/_/g, '/')}`);
  return page.frameLocator('iframe[name="list_frame"], iframe.t3js-module-iframe, typo3-iframe-module iframe').first();
}

export async function keinQuerscrollen(page) {
  return page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
}
