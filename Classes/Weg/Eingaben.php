<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Weg;

/**
 * Aus einem abgeschickten Formular wird der Rumpf für die API – und die Werte, die das Formular nach einem Fehler
 * wieder zeigt (flach, `checks.12.note` → Text, für `Rueckmeldung::werte`). Hier wird nichts geprüft: was gilt,
 * entscheidet der Dienst und sagt es in `errors`.
 */
final class Eingaben
{
    /**
     * Nur die Prüfschritte, deren Antwort oder Notiz sich gegenüber der Anzeige geändert hat. Eine unverändert
     * zurückgeschickte Antwort wäre beim Dienst eine eben gegebene: eine übernommene verlöre ihren Vermerk, und die
     * handelnde Person stünde an Antworten, die sie nicht gegeben hat.
     *
     * @param array<mixed> $rumpf
     * @return array{checks: list<array{id: int, answer: string, note: string}>, werte: array<string, string>}
     */
    public static function pruefschritte(array $rumpf): array
    {
        $checks = [];
        $werte = [];
        foreach (is_array($rumpf['checks'] ?? null) ? $rumpf['checks'] : [] as $id => $eingabe) {
            if (!is_array($eingabe) || preg_match('/^[1-9]\d{0,17}$/', (string)$id) !== 1) {
                continue;
            }
            $antwort = self::text($eingabe['answer'] ?? null);
            $notiz = self::text($eingabe['note'] ?? null);
            $werte['checks.' . $id . '.answer'] = $antwort;
            $werte['checks.' . $id . '.note'] = $notiz;
            if ($antwort === '' || ($antwort === self::text($eingabe['vorher_antwort'] ?? null) && $notiz === self::text($eingabe['vorher_notiz'] ?? null))) {
                continue;
            }
            $checks[] = ['id' => (int)$id, 'answer' => $antwort, 'note' => $notiz];
        }

        return ['checks' => $checks, 'werte' => $werte];
    }

    /**
     * `subdivision` geht nur mit, wenn das Feld gezeigt wurde: fehlend heißt beim Dienst „unverändert“, leer „leeren“.
     *
     * @param array<mixed> $rumpf
     * @return array{rumpf: array<string, string>, werte: array<string, string>}
     */
    public static function stelle(array $rumpf): array
    {
        $daten = [
            'name' => trim(self::text($rumpf['name'] ?? null)),
            'applies_to' => self::text($rumpf['applies_to'] ?? null),
        ];
        if (self::text($rumpf['subdivision_gezeigt'] ?? null) === '1') {
            $daten['subdivision'] = self::text($rumpf['subdivision'] ?? null);
        }

        return ['rumpf' => $daten, 'werte' => $daten];
    }

    /**
     * Nichts angekreuzt ist eine Antwort („keine dieser Dienstleistungen“), keine Lücke.
     *
     * @param array<mixed> $rumpf
     * @return array{rumpf: array{services: list<string>, micro_enterprise: bool}, werte: array<string, string>}
     */
    public static function betroffenheit(array $rumpf): array
    {
        $dienste = [];
        foreach (is_array($rumpf['services'] ?? null) ? $rumpf['services'] : [] as $schluessel) {
            if (is_string($schluessel) && $schluessel !== '' && !in_array($schluessel, $dienste, true)) {
                $dienste[] = $schluessel;
            }
        }
        $klein = self::text($rumpf['micro_enterprise'] ?? null) === '1';
        $werte = ['services' => '1', 'micro_enterprise' => $klein ? '1' : ''];
        foreach ($dienste as $schluessel) {
            $werte['services.' . $schluessel] = '1';
        }

        return ['rumpf' => ['services' => $dienste, 'micro_enterprise' => $klein], 'werte' => $werte];
    }

    /**
     * Die Pflichtangaben. Der Dienst ersetzt alle Angaben, ein fehlender Schlüssel zählt als leer. Deshalb geht jedes
     * Feld des Entwurfs mit: die gezeigten mit der Eingabe, die für diese Dokumentart nicht geltenden mit ihrem
     * gespeicherten Wert – sonst löschte das Speichern sie still.
     *
     * @param array<mixed>       $rumpf
     * @param list<array<mixed>> $felder `draft.fields` der API
     * @return array{rumpf: array<string, string>, werte: array<string, string>}
     */
    public static function angaben(array $rumpf, array $felder): array
    {
        $eingabe = is_array($rumpf['angaben'] ?? null) ? $rumpf['angaben'] : [];
        $daten = [];
        $werte = [];
        foreach ($felder as $feld) {
            $schluessel = self::text($feld['key'] ?? null);
            if ($schluessel === '') {
                continue;
            }
            if (($feld['applies'] ?? false) === true) {
                $wert = self::text($eingabe[$schluessel] ?? null);
                $daten[$schluessel] = ($feld['type'] ?? '') === 'url' ? trim($wert) : $wert;
                $werte[$schluessel] = $daten[$schluessel];
            } else {
                $daten[$schluessel] = self::text($feld['value'] ?? null);
            }
        }

        return ['rumpf' => $daten, 'werte' => $werte];
    }

    /**
     * Fehler je Feld aus `errors` der API, ein Text je Schlüssel. Der Dienst zählt die Einträge eines Stapels
     * (`checks.0.note`); hier stehen sie unter der Kennung der Frage (`checks.12.note`), wie das Formular sie führt.
     * `services.0` gehört zur Gruppe `services`.
     *
     * @param array<string, mixed>              $errors
     * @param list<array{id: int, answer: string, note: string}> $stapel die gesendeten Prüfschritte
     * @return array<string, string>
     */
    public static function fehler(array $errors, array $stapel = []): array
    {
        $felder = [];
        foreach ($errors as $schluessel => $meldungen) {
            $texte = array_values(array_filter(is_array($meldungen) ? $meldungen : [$meldungen], 'is_string'));
            if ($texte === []) {
                continue;
            }
            $schluessel = (string)$schluessel;
            if (preg_match('/^checks\.(\d+)\.(\w+)$/', $schluessel, $treffer) === 1 && isset($stapel[(int)$treffer[1]])) {
                $schluessel = 'checks.' . $stapel[(int)$treffer[1]]['id'] . '.' . $treffer[2];
            } elseif (preg_match('/^services\.\d+$/', $schluessel) === 1) {
                $schluessel = 'services';
            }
            $felder[$schluessel] = trim(($felder[$schluessel] ?? '') . ' ' . implode(' ', $texte));
        }

        return $felder;
    }

    private static function text(mixed $wert): string
    {
        return is_string($wert) ? $wert : '';
    }
}
