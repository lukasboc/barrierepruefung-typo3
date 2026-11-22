# Herkunft der Fixtures

Diese Dateien sind unverändert kopiert aus dem Dienst-Repository
(`web-accessibility-checker`), Pfad `tests/Fixtures/fragment/`, Stand Commit `24cf672`.

Regel: Eine Änderung an den Fixtures geschieht zuerst im Dienst und wird dann hierher
kopiert, nie umgekehrt und nie nur hier. Der Gleichlauf-Test im Dienst liest diese Dateien
aus dem Tag-Archiv der Extension und vergleicht die Ausgabe von `Fragment` Zeichen für
Zeichen mit `DeclarationFragment`/`DeclarationMarkdown`. Beim Kopieren den Commit oben
nachziehen.
