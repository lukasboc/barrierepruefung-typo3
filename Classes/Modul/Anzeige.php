<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Modul;

use Lubomedia\Barrierepruefung\Api\Antwort;
use Lubomedia\Barrierepruefung\Controller\Modulrahmen;
use Lubomedia\Barrierepruefung\Erklaerung\Fassung;

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

    /**
     * Die Erklärung, wie das Modul sie zeigt – aus dem, was die Registry weiß (nie vom Dienst).
     *
     * `zustand`: `veroeffentlicht`, `keine` (der Dienst hat keine oder war nicht zu fragen) oder `nochNie` (noch nie
     * abgerufen: ob der Dienst eine hat, weiß das Modul dann nicht). `fehler` gibt es, solange der letzte Abruf
     * scheiterte; je nach Status des Fehlers (`letzterStatus`) ein eigener Satz: 404 heißt „im Dienst nichts
     * veröffentlicht“, 401/403 „Verbindung nicht mehr gültig“, alles andere „Dienst nicht erreichbar“. In jedem Fall
     * bleibt die letzte gute Fassung stehen, und der Satz sagt das.
     *
     * @return array{zustand: string, veraltet: bool, stand: string|null, fassung: int|null, am: string|null, adresse: string|null, sprache: string|null, fehler: array{art: string, klasse: string, symbol: string, saetze: list<string>}|null}
     */
    public function erklaerung(Fassung $f): array
    {
        $e = $f->erklaerung;
        $zustand = $e !== null ? 'veroeffentlicht' : ($f->letzterFehlerAm === null ? 'nochNie' : 'keine');
        $veroeffentlicht = $this->zeitpunkt($e['published_at'] ?? null);
        $stand = $this->zeitpunkt($e['assessed_at'] ?? null) ?? $veroeffentlicht;

        return [
            'zustand' => $zustand,
            'veraltet' => ($e['outdated'] ?? false) === true,
            'stand' => $stand !== null ? $this->datum($stand) : null,
            'fassung' => is_int($e['version'] ?? null) ? $e['version'] : null,
            'am' => $veroeffentlicht !== null ? $this->datum($veroeffentlicht) : null,
            'adresse' => $this->adresse($e['public_url'] ?? null),
            'sprache' => is_string($e['locale'] ?? null) && $e['locale'] !== '' ? $this->sprachname($e['locale']) : null,
            'fehler' => $f->zustand === Fassung::AKTUELL || $zustand === 'nochNie' ? null : $this->erklaerungsfehler($f),
        ];
    }

    /**
     * @return array{art: string, klasse: string, symbol: string, saetze: list<string>}
     */
    public function erklaerungsfehler(Fassung $f): array
    {
        $art = match (true) {
            $f->letzterStatus === 404 => 'nicht_veroeffentlicht',
            $f->letzterStatus === 401, $f->letzterStatus === 403 => 'verbindung',
            default => 'ausfall',
        };
        $saetze = [];
        if ($art === 'ausfall') {
            $saetze[] = $f->abgerufenAm !== null
                ? $this->t('erklaerung.zuletzt_erreichbar', $this->datum($f->abgerufenAm))
                : $this->t('erklaerung.nie_erreichbar');
            if ($f->letzterFehler !== null) {
                $saetze[] = $this->t('erklaerung.grund', $f->letzterFehler);
            }
        } else {
            $saetze[] = $this->t('erklaerung.fehler.' . $art);
        }
        $veroeffentlicht = $this->zeitpunkt($f->erklaerung['published_at'] ?? null);
        if ($veroeffentlicht !== null) {
            $saetze[] = $this->t('erklaerung.website_zeigt', $this->datum($veroeffentlicht));
        }

        return [
            'art' => $art,
            'klasse' => $art === 'nicht_veroeffentlicht' ? 'callout-info' : 'callout-warning',
            'symbol' => $art === 'nicht_veroeffentlicht' ? 'actions-info' : 'actions-exclamation',
            'saetze' => $saetze,
        ];
    }

    /** „Deutsch“ statt `de`, in der Sprache der Backend-Person; ohne intl der Code selbst. */
    public function sprachname(string $code): string
    {
        if (!class_exists(\Locale::class)) {
            return $code;
        }
        $name = \Locale::getDisplayName(str_replace('_', '-', $code), $this->rahmen->sprache()->getLocale()?->getLanguageCode() ?? 'en');

        return is_string($name) && $name !== $code ? $name : $code;
    }
}
