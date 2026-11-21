// Rauchtest: Anmeldung im Backend und Frontend-Startseite beider lokaler Instanzen.
import { chromium } from 'playwright';
import { anmelden } from './hilfen.mjs';

const instanzen = [
  { name: 'v13', basis: 'http://localhost:8089' },
  { name: 'v14', basis: 'http://localhost:8090' },
];

const browser = await chromium.launch();
let fehler = 0;
for (const { name, basis } of instanzen) {
  const kontext = await browser.newContext();
  const page = await kontext.newPage();
  try {
    await anmelden(page, basis);
    const frontend = await kontext.request.get(`${basis}/`);
    if (frontend.status() !== 200) throw new Error(`Frontend ${basis}/ antwortet ${frontend.status()}`);
    console.log(`ok ${name}`);
  } catch (e) {
    fehler++;
    console.error(`FEHLER ${name}: ${e.message}`);
  }
  await kontext.close();
}
await browser.close();
process.exit(fehler ? 1 : 0);
