<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Erklaerung;

/**
 * Überschriften- und Abschnittslogik des eingebetteten Erklärungstextes.
 *
 * Portiert aus `DeclarationFragment`/`DeclarationMarkdown` des Dienstes, nicht geteilt:
 * es sind zwei Laufzeiten. Ein Gleichlauf-Test im Dienst vergleicht die Ausgabe Zeichen
 * für Zeichen, deshalb ändert sich `abschnitt()` und `ebenenVerschieben()` nur zusammen
 * mit dem Dienst.
 *
 * Reines PHP: keine CMS-Klasse, denn der Gleichlauf-Test lädt die Datei ohne das CMS.
 */
final class Fragment
{
    /** Überschriften, unter denen die Mängel stehen - je nach Dokumenttyp. */
    public const MAENGEL = ['Nicht barrierefreie Inhalte', 'Bekannte Einschränkungen'];

    /** Überschriften des Meldewegs - je nach Dokumenttyp. */
    public const KONTAKT = ['Feedback und Kontaktangaben', 'Kontakt bei Barrieren'];

    /** Schneidet einen Abschnitt (`maengel`, `kontakt`) anhand seiner h2-Überschrift heraus. */
    public static function abschnitt(string $html, string $teil): string
    {
        $titel = match ($teil) {
            'maengel' => self::MAENGEL,
            'kontakt' => self::KONTAKT,
            default => [],
        };

        foreach ($titel as $suche) {
            $muster = '#<h2[^>]*>\s*' . preg_quote($suche, '#') . '.*?(?=<h2|$)#is';

            if (preg_match($muster, $html, $treffer)) {
                return $treffer[0];
            }
        }

        // Fehlt der gewünschte Abschnitt, steht lieber der ganze Text da als
        // gar keiner: eine fehlende Erklärung ist das größere Problem.
        return $html;
    }

    /**
     * Verschiebt die Überschriften so, dass die oberste auf `$start` steht (2 bis 4).
     * Gemessen wird an der obersten Überschrift des Ausschnitts, nicht an einer h1.
     */
    public static function ebenenVerschieben(string $html, int $start): string
    {
        preg_match_all('#<h([1-6])[^>]*>#i', $html, $treffer);

        if ($treffer[1] === []) {
            return $html;
        }

        $verschiebung = max(2, min(4, $start)) - min(array_map('intval', $treffer[1]));

        if ($verschiebung === 0) {
            return $html;
        }

        return preg_replace_callback(
            '#<(/?)h([1-6])([^>]*)>#i',
            static function (array $treffer) use ($verschiebung): string {
                // Über h6 hinaus gibt es nichts; gedeckelt statt ungültiges HTML.
                $ebene = min(6, (int)$treffer[2] + $verschiebung);

                return '<' . $treffer[1] . 'h' . $ebene . $treffer[3] . '>';
            },
            $html,
        ) ?? $html;
    }

    /**
     * Version und Stand als Schlusszeile, in der Sprache der Erklärung.
     * Das Datum steht in Europe/Berlin; `de` als TT.MM.JJJJ, jede andere Sprache englisch (MM/DD/YYYY).
     */
    public static function standzeile(int $version, ?\DateTimeImmutable $am, string $locale): string
    {
        $deutsch = str_starts_with(strtolower($locale), 'de');
        $text = 'Version ' . $version;
        $zeit = '';

        if ($am !== null) {
            $berlin = $am->setTimezone(new \DateTimeZone('Europe/Berlin'));
            $anzeige = $berlin->format($deutsch ? 'd.m.Y' : 'm/d/Y');
            $text .= $deutsch ? ', Stand ' : ', as of ';
            $zeit = '<time datetime="' . $berlin->format('Y-m-d') . '">'
                . htmlspecialchars($anzeige, ENT_QUOTES) . '</time>';
        }

        return '<p class="a11y-erklaerung-stand">' . htmlspecialchars($text, ENT_QUOTES) . $zeit . '</p>';
    }

    /**
     * Umschlag ohne Inline-Styles und ohne id (die Seite erbt die Schrift; eine id könnte
     * mit der Fremdseite kollidieren). `lang` nur, wenn gewünscht.
     */
    public static function umschlag(string $html, string $locale, bool $mitLang): string
    {
        $lang = $mitLang ? ' lang="' . htmlspecialchars($locale, ENT_QUOTES) . '"' : '';

        return '<div class="a11y-erklaerung"' . $lang . '>' . $html . '</div>';
    }
}
