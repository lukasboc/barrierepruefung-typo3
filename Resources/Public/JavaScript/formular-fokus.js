// Setzt den Fokus nach einem Formularfehler auf das erste fehlerhafte Feld (das mit autofocus).
// Das Attribut allein genügt im Backend nicht: Browser werten autofocus in einem iframe nicht aus, sobald im
// umgebenden Backend-Dokument schon etwas fokussiert wurde – also nach jedem Klick auf „Verbinden“.
document.querySelector('form [autofocus]')?.focus();
