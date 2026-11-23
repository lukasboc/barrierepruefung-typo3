<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Weg;

use Lubomedia\Barrierepruefung\Modul\Anzeige;

/**
 * Was die Formulare eines Schritts zeigen: Fragen, Optionen und Hinweise so, wie der Dienst sie liefert, dazu je Feld
 * der Wert (nach einem Fehler die letzte Eingabe), der Fehler, `aria-describedby` und `autofocus` am ersten
 * fehlerhaften Feld. Eigene Wörter hat die Extension nur für die Bedienung und für die Beschriftung der
 * Pflichtangaben (die API nennt sie nicht); ein unbekannter Schlüssel steht dort, wie er heißt.
 */
final class Formulare
{
    /** `deferred`: „kann ich nicht beurteilen“, bei sperrenden Fragen nie erlaubt. */
    private const NICHT_BEURTEILBAR = 'deferred';

    public function __construct(private readonly Anzeige $anzeige) {}

    /**
     * @param array<mixed>          $antwort `GET /scans/{id}/manual-checks` (ohne `umfang`: nur die sperrenden Fragen)
     * @param array<string, string> $felder  Fehler je Feld
     * @param array<string, string> $werte   letzte Eingaben
     * @return array<string, mixed>
     */
    public function pruefschritte(array $antwort, array $felder, array $werte): array
    {
        $meta = is_array($antwort['meta'] ?? null) ? $antwort['meta'] : [];
        $antworten = [];
        foreach (is_array($meta['answers'] ?? null) ? $meta['answers'] : [] as $wert => $wort) {
            // Hier stehen nur sperrende Fragen, und für die lehnt der Dienst „kann ich nicht beurteilen“ ab. Liefert er
            // die Option trotzdem (etwa mit `umfang=alle`), bietet das Formular sie nicht an.
            if (is_string($wert) && is_string($wort) && $wert !== self::NICHT_BEURTEILBAR) {
                $antworten[] = ['wert' => $wert, 'wort' => $wort];
            }
        }
        $fokus = false;
        $fragen = [];
        $gerendert = [];
        foreach (is_array($antwort['data'] ?? null) ? $antwort['data'] : [] as $frage) {
            if (!is_array($frage) || !is_int($frage['id'] ?? null)) {
                continue;
            }
            $id = $frage['id'];
            $gespeichert = is_string($frage['answer'] ?? null) ? $frage['answer'] : 'unanswered';
            $notizGespeichert = is_string($frage['note'] ?? null) ? $frage['note'] : '';
            $antwortWert = $werte['checks.' . $id . '.answer'] ?? $gespeichert;
            $fehlerAntwort = $felder['checks.' . $id . '.answer'] ?? '';
            $fehlerNotiz = $felder['checks.' . $id . '.note'] ?? '';
            $idAntwort = Schritte::feldId('checks.' . $id . '.answer');
            $idNotiz = Schritte::feldId('checks.' . $id . '.note');
            $optionen = [];
            foreach ($antworten as $nummer => $option) {
                $optionen[] = $option + ['id' => $idAntwort . '-' . $nummer, 'gewaehlt' => $option['wert'] === $antwortWert];
            }
            $fokusAntwort = !$fokus && $fehlerAntwort !== '';
            $fokus = $fokus || $fokusAntwort;
            $fokusNotiz = !$fokus && $fehlerNotiz !== '';
            $fokus = $fokus || $fokusNotiz;
            $gerendert[] = 'checks.' . $id . '.answer';
            $gerendert[] = 'checks.' . $id . '.note';
            $kriterien = array_values(array_filter(is_array($frage['success_criteria'] ?? null) ? $frage['success_criteria'] : [], 'is_string'));
            $fragen[] = [
                'id' => $id,
                'titel' => self::text($frage['title'] ?? null),
                'titelId' => 'bp-frage-' . $id,
                'beschreibung' => self::text($frage['description'] ?? null),
                'kriterien' => implode(', ', $kriterien),
                'beleg' => self::text($frage['evidence'] ?? null),
                'veraltet' => ($frage['stale'] ?? false) === true,
                'uebernommen' => ($frage['inherited'] ?? false) === true,
                'vorherAntwort' => $gespeichert,
                'vorherNotiz' => $notizGespeichert,
                'optionen' => self::mitFokus($optionen, $fokusAntwort),
                'antwortId' => $idAntwort,
                'antwortFehler' => $fehlerAntwort,
                'notiz' => [
                    'id' => $idNotiz,
                    'name' => 'checks[' . $id . '][note]',
                    'wert' => $werte['checks.' . $id . '.note'] ?? $notizGespeichert,
                    'fehler' => $fehlerNotiz,
                    'fokus' => $fokusNotiz,
                    'beschrieben' => $fehlerNotiz !== '' ? $idNotiz . '-fehler' : '',
                ],
            ];
        }

        return [
            'fragen' => $fragen,
            'notizHinweis' => self::text($meta['note_hint'] ?? null),
            'webUrl' => $this->anzeige->adresse($meta['web_url'] ?? null),
            'gerendert' => $gerendert,
        ];
    }

    /**
     * @param array<mixed>          $daten `data` von `GET /sites/{id}/declaring-party`
     * @param array<string, string> $felder
     * @param array<string, string> $werte
     * @return array<string, mixed>
     */
    public function stelle(array $daten, array $felder, array $werte): array
    {
        $geerbt = self::text($daten['inherited_name'] ?? null);
        $name = $this->feld('name', 'name', $this->anzeige->t('weg.stelle.name'), $geerbt !== '' ? $this->anzeige->t('weg.stelle.name.geerbt', $geerbt) : $this->anzeige->t('weg.stelle.name.hilfe'), $werte['name'] ?? self::text($daten['name'] ?? null), $felder, false, 255, 'text', 'organization');

        $art = $werte['applies_to'] ?? self::text($daten['applies_to'] ?? null);
        $optionen = [];
        foreach (is_array($daten['options'] ?? null) ? $daten['options'] : [] as $option) {
            if (!is_array($option) || self::text($option['key'] ?? null) === '') {
                continue;
            }
            $schluessel = self::text($option['key']);
            $id = Schritte::feldId('applies_to.' . $schluessel);
            $optionen[] = [
                'wert' => $schluessel,
                'id' => $id,
                'beschriftung' => self::text($option['label'] ?? null) ?: $schluessel,
                'erlaeuterung' => self::text($option['description'] ?? null),
                'gewaehlt' => $schluessel === $art,
            ];
        }

        $laender = [];
        $land = $werte['subdivision'] ?? self::text($daten['subdivision'] ?? null);
        foreach (is_array($daten['subdivisions'] ?? null) ? $daten['subdivisions'] : [] as $eintrag) {
            if (is_array($eintrag) && self::text($eintrag['key'] ?? null) !== '') {
                $laender[] = ['wert' => self::text($eintrag['key']), 'beschriftung' => self::text($eintrag['label'] ?? null) ?: self::text($eintrag['key']), 'gewaehlt' => self::text($eintrag['key']) === $land];
            }
        }
        $artFehler = $felder['applies_to'] ?? '';
        $landFehler = $felder['subdivision'] ?? '';
        foreach ($optionen as $nummer => $option) {
            $optionen[$nummer]['beschrieben'] = trim($option['id'] . '-erlaeuterung' . ($artFehler !== '' ? ' ' . Schritte::feldId('applies_to') . '-fehler' : ''));
        }
        $fokusArt = $name['fehler'] === '' && $artFehler !== '';
        $landId = Schritte::feldId('subdivision');

        return [
            'name' => $name,
            'art' => [
                'id' => Schritte::feldId('applies_to'),
                'hinweis' => self::text($daten['hint'] ?? null),
                'optionen' => self::mitFokus($optionen, $fokusArt),
                'fehler' => $artFehler,
            ],
            // Leere Liste: der Rechtsraum kennt die Frage nicht, dann gibt es auch kein Feld (und nichts wird gesendet).
            'land' => $laender === [] ? null : [
                'id' => $landId,
                'laender' => $laender,
                'keinesGewaehlt' => !in_array($land, array_column($laender, 'wert'), true),
                'pflicht' => ($daten['subdivision_required'] ?? false) === true,
                'fehler' => $landFehler,
                'fokus' => $name['fehler'] === '' && $artFehler === '' && $landFehler !== '',
                'beschrieben' => trim($landId . '-hilfe' . ($landFehler !== '' ? ' ' . $landId . '-fehler' : '')),
            ],
            'gerendert' => ['name', 'applies_to', 'subdivision'],
        ];
    }

    /**
     * @param array<mixed>          $daten `data` von `GET /sites/{id}/applicability`
     * @param array<string, string> $felder
     * @param array<string, string> $werte
     * @return array<string, mixed>
     */
    public function betroffenheit(array $daten, array $felder, array $werte): array
    {
        $dienste = is_array($daten['services'] ?? null) ? $daten['services'] : [];
        $klein = is_array($daten['micro_enterprise'] ?? null) ? $daten['micro_enterprise'] : [];
        $nachFehler = isset($werte['services']);
        $optionen = [];
        foreach (is_array($dienste['options'] ?? null) ? $dienste['options'] : [] as $option) {
            if (!is_array($option) || self::text($option['key'] ?? null) === '') {
                continue;
            }
            $schluessel = self::text($option['key']);
            $optionen[] = [
                'wert' => $schluessel,
                'id' => Schritte::feldId('services.' . $schluessel),
                'beschriftung' => self::text($option['label'] ?? null) ?: $schluessel,
                'fundstelle' => self::text($option['reference'] ?? null),
                'gewaehlt' => $nachFehler ? isset($werte['services.' . $schluessel]) : ($option['selected'] ?? false) === true,
            ];
        }
        $dienstFehler = $felder['services'] ?? '';
        $kleinFehler = $felder['micro_enterprise'] ?? '';
        foreach ($optionen as $nummer => $option) {
            $optionen[$nummer]['beschrieben'] = trim(($option['fundstelle'] !== '' ? $option['id'] . '-fundstelle' : '') . ($dienstFehler !== '' ? ' ' . Schritte::feldId('services') . '-fehler' : ''));
        }
        $kleinId = Schritte::feldId('micro_enterprise');
        $kleinHinweis = self::text($klein['hint'] ?? null);

        return [
            'gefragt' => ($daten['asked'] ?? false) === true,
            'titel' => self::text($daten['title'] ?? null),
            'beschreibung' => self::text($daten['description'] ?? null),
            'dienste' => [
                'id' => Schritte::feldId('services'),
                'frage' => self::text($dienste['question'] ?? null),
                'hinweis' => self::text($dienste['hint'] ?? null),
                'optionen' => self::mitFokus($optionen, $dienstFehler !== ''),
                'fehler' => $dienstFehler,
            ],
            'klein' => [
                'id' => $kleinId,
                'frage' => self::text($klein['question'] ?? null),
                'hinweis' => $kleinHinweis,
                'beschrieben' => trim(($kleinHinweis !== '' ? $kleinId . '-hilfe' : '') . ($kleinFehler !== '' ? ' ' . $kleinId . '-fehler' : '')),
                'nurMitDiensten' => ($klein['only_with_services'] ?? false) === true,
                'gewaehlt' => $nachFehler ? ($werte['micro_enterprise'] ?? '') === '1' : ($klein['value'] ?? false) === true,
                'fehler' => $kleinFehler,
                'fokus' => $dienstFehler === '' && $kleinFehler !== '',
            ],
            'folge' => self::text($daten['consequence'] ?? null),
            'beantwortetAm' => ($am = $this->anzeige->zeitpunkt($daten['answered_at'] ?? null)) !== null ? $this->anzeige->datum($am) : null,
            'hinweis' => self::text($daten['disclaimer'] ?? null),
            'gerendert' => ['services', 'micro_enterprise'],
        ];
    }

    /**
     * Die Pflichtangaben aus `draft.fields`: nur, was für die Dokumentart gilt (`applies`); `required`, `max` und
     * `type` stehen am Feld. Mehr als 255 Zeichen erlaubt: mehrzeilig.
     *
     * @param array<mixed>          $entwurf `data` von `GET /sites/{id}/declaration/draft`
     * @param array<string, string> $felder
     * @param array<string, string> $werte
     * @return array<string, mixed>
     */
    public function angaben(array $entwurf, array $felder, array $werte): array
    {
        $uebrige = [];
        $stelle = [];
        $gerendert = [];
        foreach (is_array($entwurf['fields'] ?? null) ? $entwurf['fields'] : [] as $feld) {
            $schluessel = is_array($feld) ? self::text($feld['key'] ?? null) : '';
            if ($schluessel === '' || ($feld['applies'] ?? false) !== true) {
                continue;
            }
            $beschriftung = $this->anzeige->t('weg.angabe.' . $schluessel);
            $max = is_int($feld['max'] ?? null) && $feld['max'] > 0 ? $feld['max'] : null;
            $eintrag = $this->feld(
                $schluessel,
                'angaben[' . $schluessel . ']',
                $beschriftung !== '' ? $beschriftung : $schluessel,
                $this->anzeige->t('weg.angabe.' . $schluessel . '.hilfe'),
                $werte[$schluessel] ?? self::text($feld['value'] ?? null),
                $felder,
                ($feld['required'] ?? false) === true,
                $max,
                ($feld['type'] ?? '') === 'url' ? 'url' : ($max !== null && $max > 255 ? 'textarea' : 'text'),
                ($feld['type'] ?? '') === 'url' ? 'url' : 'off',
            );
            $gerendert[] = $schluessel;
            if (str_starts_with($schluessel, 'enforcement_body_')) {
                $stelle[] = $eintrag;
            } else {
                $uebrige[] = $eintrag;
            }
        }
        // autofocus am ersten fehlerhaften Feld in der Reihenfolge der Anzeige.
        $fokus = false;
        foreach ([&$uebrige, &$stelle] as &$gruppe) {
            foreach ($gruppe as &$eintrag) {
                $eintrag['fokus'] = !$fokus && $eintrag['fehler'] !== '';
                $fokus = $fokus || $eintrag['fokus'];
            }
            unset($eintrag);
        }
        unset($gruppe);

        return ['uebrige' => $uebrige, 'stelle' => $stelle, 'gerendert' => $gerendert];
    }

    /**
     * Die Aussage zur Konformität aus `draft.conformance`: die ermittelte (`suggested`) ist gewählt, angeboten wird nur,
     * was der Dienst wählbar nennt (`choices`; „vollständig konform“ fehlt, solange etwas offen ist), mit seinen Wörtern
     * (`labels`). Wer abweicht, begründet es (`override_reason`). Dazu Kennung und Nummer der Fassung, die freigegeben
     * würde – genau das, was die Vorschau zeigt.
     *
     * @param array<mixed>          $entwurf `data` von `GET /sites/{id}/declaration/draft`
     * @param array<string, string> $felder
     * @param array<string, string> $werte
     * @return array<string, mixed>
     */
    public function freigabe(array $entwurf, array $felder, array $werte): array
    {
        $aussage = is_array($entwurf['conformance'] ?? null) ? $entwurf['conformance'] : [];
        $woerter = is_array($aussage['labels'] ?? null) ? $aussage['labels'] : [];
        $ermittelt = self::text($aussage['suggested'] ?? null);
        $wahl = $werte['conformance_status'] ?? $ermittelt;
        $werteListe = array_values(array_filter(is_array($aussage['choices'] ?? null) ? $aussage['choices'] : [], static fn(mixed $w): bool => is_string($w) && $w !== ''));
        if ($ermittelt !== '' && !in_array($ermittelt, $werteListe, true)) {
            array_unshift($werteListe, $ermittelt);
        }
        $id = Schritte::feldId('conformance_status');
        $fehler = $felder['conformance_status'] ?? '';
        $optionen = [];
        foreach ($werteListe as $wert) {
            /** @var string $wert */
            $optionen[] = [
                'wert' => $wert,
                'id' => Schritte::feldId('conformance_status.' . $wert),
                'wort' => self::text($woerter[$wert] ?? null) ?: $wert,
                'ermittelt' => $wert === $ermittelt,
                'gewaehlt' => $wert === $wahl,
            ];
        }
        $begruendung = $this->feld(
            'override_reason',
            'override_reason',
            $this->anzeige->t('weg.freigabe.begruendung'),
            $this->anzeige->t('weg.freigabe.begruendung.hilfe'),
            $werte['override_reason'] ?? '',
            $felder,
            false,
            2000,
            'textarea',
            'off',
        );
        $begruendung['fokus'] = $fehler === '' && $begruendung['fehler'] !== '';

        return [
            'ermittelt' => $ermittelt,
            'ermitteltWort' => self::text($woerter[$ermittelt] ?? null) ?: $ermittelt,
            'grund' => self::text($aussage['reason'] ?? null),
            'id' => $id,
            'optionen' => self::mitFokus($optionen, $fehler !== ''),
            'fehler' => $fehler,
            'begruendung' => $begruendung,
            'etag' => self::text($entwurf['draft_etag'] ?? null),
            'fassung' => is_int($entwurf['next_version'] ?? null) && $entwurf['next_version'] > 0 ? $entwurf['next_version'] : null,
            'gerendert' => ['conformance_status', 'override_reason'],
        ];
    }

    /**
     * Die Fehlerliste über dem Formular: jeder Text mit Sprungmarke zum Feld, wenn es das Feld hier gibt.
     *
     * @param array<string, string> $felder
     * @param list<string>          $gerendert Schlüssel der Felder im Formular
     * @return list<array{text: string, ziel: string|null}>
     */
    public static function fehlerliste(array $felder, array $gerendert): array
    {
        $liste = [];
        foreach ($felder as $schluessel => $text) {
            $liste[] = ['text' => $text, 'ziel' => in_array($schluessel, $gerendert, true) ? Schritte::feldId($schluessel) : null];
        }

        return $liste;
    }

    /**
     * @param array<string, string> $felder
     * @return array<string, mixed>
     */
    private function feld(string $schluessel, string $name, string $beschriftung, string $hinweis, string $wert, array $felder, bool $pflicht, ?int $max, string $art, string $autocomplete): array
    {
        $id = Schritte::feldId($schluessel);
        $fehler = $felder[$schluessel] ?? '';
        $beschrieben = trim(($fehler !== '' ? $id . '-fehler ' : '') . ($hinweis !== '' ? $id . '-hilfe' : ''));

        return [
            'schluessel' => $schluessel,
            'name' => $name,
            'id' => $id,
            'beschriftung' => $beschriftung,
            'hinweis' => $hinweis,
            'wert' => $wert,
            'fehler' => $fehler,
            'pflicht' => $pflicht,
            'max' => $max,
            'art' => $art === 'textarea' ? 'textarea' : 'input',
            'typ' => $art === 'url' ? 'url' : 'text',
            'autocomplete' => $autocomplete,
            'fokus' => $fehler !== '',
            'beschrieben' => $beschrieben,
        ];
    }

    /**
     * `fokus` an einer Option der Gruppe: der gewählten, sonst der ersten – nur, wenn die Gruppe den Fehler trägt.
     *
     * @param list<array<string, mixed>> $optionen
     * @return list<array<string, mixed>>
     */
    private static function mitFokus(array $optionen, bool $fokus): array
    {
        $ziel = 0;
        foreach ($optionen as $nummer => $option) {
            if (($option['gewaehlt'] ?? false) === true) {
                $ziel = $nummer;
                break;
            }
        }
        foreach ($optionen as $nummer => $option) {
            $optionen[$nummer]['fokus'] = $fokus && $nummer === $ziel;
        }

        return $optionen;
    }

    private static function text(mixed $wert): string
    {
        return is_string($wert) ? $wert : '';
    }
}
