<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Weg;

use Lubomedia\Barrierepruefung\Modul\Anzeige;

/**
 * Die Schritte zur Erklärung, wie `GET /sites/{id}/declaration/path` sie liefert: Titel, Zustand, Gründe und
 * `web_url` vom Dienst, hier nur als Wort und Abzeichen dargestellt. Welche Schritte es gibt, entscheidet der Dienst;
 * ein Schlüssel, den diese Fassung nicht kennt, erscheint trotzdem – mit Titel, Gründen und dem Weg in den Dienst.
 */
final class Schritte
{
    /** Schritte, für die das Modul eine eigene Ansicht hat. */
    public const BEKANNT = ['pruefung', 'domain', 'verantwortliche_stelle', 'betroffenheit', 'pruefschritte', 'pflichtangaben'];

    /** Schritte, die hier ein Formular haben (und dafür das Schreibrecht des Tokens brauchen). */
    public const MIT_FORMULAR = ['pruefschritte', 'verantwortliche_stelle', 'betroffenheit', 'pflichtangaben'];

    private const ABZEICHEN = ['erledigt' => 'success', 'offen' => 'warning', 'spaeter' => 'info'];

    public function __construct(private readonly Anzeige $anzeige) {}

    /**
     * Die Kennung des Formularfelds zu einem Schlüssel der API: `contact` → `bp-contact`, `checks.12.note` →
     * `bp-checks-12-note`. Dieselbe Regel an Feld, Fehlertext und Fehlerliste.
     */
    public static function feldId(string $schluessel): string
    {
        return 'bp-' . (string)preg_replace('/[^A-Za-z0-9_-]+/', '-', str_replace('.', '-', $schluessel));
    }

    /**
     * @return list<array{schluessel: string, titel: string, zustand: string, wort: string, abzeichen: string, offen: bool, gruende: list<string>, webUrl: string|null, bekannt: bool, formular: bool}>
     */
    public function liste(mixed $schritte): array
    {
        $liste = [];
        foreach (is_array($schritte) ? $schritte : [] as $schritt) {
            if (!is_array($schritt) || !is_string($schritt['key'] ?? null) || $schritt['key'] === '') {
                continue;
            }
            $schluessel = $schritt['key'];
            $zustand = is_string($schritt['state'] ?? null) ? $schritt['state'] : '';
            $gruende = [];
            foreach (is_array($schritt['reasons'] ?? null) ? $schritt['reasons'] : [] as $grund) {
                if (is_array($grund) && is_string($grund['text'] ?? null) && $grund['text'] !== '') {
                    $gruende[] = $grund['text'];
                }
            }
            $titel = is_string($schritt['title'] ?? null) && $schritt['title'] !== '' ? $schritt['title'] : $schluessel;
            $liste[] = [
                'schluessel' => $schluessel,
                'titel' => $titel,
                'zustand' => $zustand,
                'wort' => $this->anzeige->wort('weg.zustand.', $zustand),
                'abzeichen' => self::ABZEICHEN[$zustand] ?? 'default',
                'offen' => $zustand === 'offen',
                'gruende' => $gruende,
                'webUrl' => $this->anzeige->adresse($schritt['web_url'] ?? null),
                'bekannt' => in_array($schluessel, self::BEKANNT, true),
                'formular' => in_array($schluessel, self::MIT_FORMULAR, true),
            ];
        }

        return $liste;
    }

    /**
     * @param list<array{schluessel: string, titel: string, zustand: string, wort: string, abzeichen: string, offen: bool, gruende: list<string>, webUrl: string|null, bekannt: bool, formular: bool}> $liste
     * @return array{schluessel: string, titel: string, zustand: string, wort: string, abzeichen: string, offen: bool, gruende: list<string>, webUrl: string|null, bekannt: bool, formular: bool}|null
     */
    public static function finden(array $liste, mixed $schluessel): ?array
    {
        foreach ($liste as $schritt) {
            if ($schritt['schluessel'] === $schluessel) {
                return $schritt;
            }
        }

        return null;
    }
}
