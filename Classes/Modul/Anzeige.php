<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Modul;

use Lubomedia\Barrierepruefung\Api\Antwort;
use Lubomedia\Barrierepruefung\Controller\Modulrahmen;

/**
 * Was alle Untermodule zum Anzeigen brauchen: Texte in der Sprache der Backend-Person, Datum und Zahl, die
 * Zeitangaben des Dienstes, sichere Links und der Satz zu einem Fehler des Dienstes. Eine Stelle, damit
 * Übersicht und Befunde einen Fehler gleich sagen.
 */
final class Anzeige
{
    public function __construct(private readonly Modulrahmen $rahmen) {}

    public function t(string $schluessel, string ...$werte): string
    {
        $text = $this->rahmen->sprache()->sL(Modulrahmen::SPRACHDATEI . $schluessel);

        return $werte === [] || $text === '' ? $text : vsprintf($text, $werte);
    }

    /**
     * Das Wort zu einem Wert des Dienstes; ein unbekannter Wert steht, wie er ist, statt zu verschwinden.
     */
    public function wort(string $praefix, string $wert): string
    {
        $wort = $wert !== '' ? $this->t($praefix . $wert) : '';

        return $wort !== '' ? $wort : ($wert !== '' ? $wert : $this->t($praefix . 'unbekannt'));
    }

    public function datum(int $zeit): string
    {
        return date($this->t('format.datum'), $zeit);
    }

    public function zahl(int $zahl): string
    {
        return number_format($zahl, 0, '', $this->t('format.tausender'));
    }

    public function zeitpunkt(mixed $wert): ?int
    {
        if (!is_string($wert) || $wert === '') {
            return null;
        }
        $zeit = strtotime($wert);

        return $zeit === false ? null : $zeit;
    }

    /**
     * Nur http(s): ein Link aus der Antwort des Dienstes wird nie zu `javascript:`.
     */
    public function adresse(mixed $wert): ?string
    {
        return is_string($wert) && preg_match('#^https?://#i', $wert) === 1 ? $wert : null;
    }

    /**
     * Detail des Dienstes (in der Sprache der Backend-Person) und ein Hinweis, was zu tun ist.
     *
     * @return array{detail: string, hinweis: string}
     */
    public function fehler(Antwort $antwort): array
    {
        $reason = $antwort->problem?->reason;
        $hinweis = $reason !== null ? $this->t('fehler.' . $reason) : '';
        if ($hinweis === '') {
            $hinweis = match (true) {
                $antwort->status === 401 => $this->t('fehler.status_401'),
                $antwort->status === 404 => $this->t('fehler.status_404'),
                $antwort->status === 429 => $this->t('fehler.status_429'),
                $antwort->status >= 500 => $this->t('fehler.status_5xx'),
                default => '',
            };
        }
        // Ein Netzfehler hat kein Detail vom Dienst; der Satz der Erweiterung sagt dann alles.
        $detail = $antwort->status === 0 ? '' : (string)$antwort->problem?->detail;

        return ['detail' => $detail, 'hinweis' => $hinweis];
    }
}
