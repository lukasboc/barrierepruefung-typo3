<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Unit\Erklaerung;

use Lubomedia\Barrierepruefung\Erklaerung\Fragment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Die Fälle des WordPress-Tests im Dienst (WordPressShortcodeTest) als PHPUnit-Tests.
 * Ein Gleichlauf-Test im Dienst vergleicht die Ausgabe außerdem Zeichen für Zeichen.
 */
final class FragmentTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/fragment';

    #[Test]
    public function verschiebtDieUeberschriftenebenen(): void
    {
        $verschoben = Fragment::ebenenVerschieben('<h1>Erklärung</h1><h2>Stand</h2><p>Text</p><h3>Details</h3>', 3);

        self::assertSame('<h3>Erklärung</h3><h4>Stand</h4><p>Text</p><h5>Details</h5>', $verschoben);
    }

    #[Test]
    public function machtBeiDerVoreinstellungAusDerH1EineH2(): void
    {
        self::assertSame(
            '<h2>Erklärung</h2><h3>Stand</h3>',
            Fragment::ebenenVerschieben('<h1>Erklärung</h1><h2>Stand</h2>', 2),
        );
    }

    #[Test]
    public function misstAnDerOberstenUeberschriftDesAusschnitts(): void
    {
        $html = '<h2>Nicht barrierefreie Inhalte</h2><h3>Nichtvereinbarkeit</h3>';

        self::assertSame($html, Fragment::ebenenVerschieben($html, 2));
        self::assertSame(
            '<h3>Nicht barrierefreie Inhalte</h3><h4>Nichtvereinbarkeit</h4>',
            Fragment::ebenenVerschieben($html, 3),
        );
    }

    #[Test]
    public function gehtNichtUeberH6Hinaus(): void
    {
        self::assertSame(
            '<h4>Oben</h4><h6>Tief</h6><h6>Tiefer</h6>',
            Fragment::ebenenVerschieben('<h1>Oben</h1><h5>Tief</h5><h6>Tiefer</h6>', 4),
        );
    }

    #[Test]
    public function klammertDieStartebeneAufZweiBisVier(): void
    {
        self::assertSame('<h2>A</h2>', Fragment::ebenenVerschieben('<h1>A</h1>', 0));
        self::assertSame('<h4>A</h4>', Fragment::ebenenVerschieben('<h1>A</h1>', 9));
    }

    #[Test]
    public function laesstEinenTextOhneUeberschriftenUnveraendert(): void
    {
        self::assertSame('<p>Nur Text</p>', Fragment::ebenenVerschieben('<p>Nur Text</p>', 3));
    }

    #[Test]
    public function schneidetDenAbschnittMitDenMaengelnHeraus(): void
    {
        $html = '<h2>Stand der Vereinbarkeit</h2><p>teilweise</p>'
            . '<h2>Nicht barrierefreie Inhalte</h2><ul><li>Bilder ohne Alternativtext</li></ul>'
            . '<h2>Erstellung dieser Erklärung</h2><p>Selbstbewertung</p>';

        $abschnitt = Fragment::abschnitt($html, 'maengel');

        self::assertStringContainsString('Bilder ohne Alternativtext', $abschnitt);
        self::assertStringNotContainsString('Selbstbewertung', $abschnitt);
        self::assertStringNotContainsString('Stand der Vereinbarkeit', $abschnitt);
    }

    #[Test]
    public function erkenntAuchDieUeberschriftDerUnternehmensfassung(): void
    {
        $html = '<h2>Stand der Umsetzung</h2><p>x</p><h2>Bekannte Einschränkungen</h2><p>Untertitel fehlen</p>';

        self::assertStringContainsString('Untertitel fehlen', Fragment::abschnitt($html, 'maengel'));
    }

    #[Test]
    public function schneidetDenKontaktabschnittHeraus(): void
    {
        $html = '<h2>Stand</h2><p>a</p><h2>Feedback und Kontaktangaben</h2><p>mail</p><h2>Ende</h2><p>z</p>';

        self::assertSame('<h2>Feedback und Kontaktangaben</h2><p>mail</p>', Fragment::abschnitt($html, 'kontakt'));
    }

    #[Test]
    public function gibtDenGanzenTextZurueckWennDerAbschnittFehlt(): void
    {
        $html = '<h2>Stand</h2><p>alles gut</p>';

        self::assertSame($html, Fragment::abschnitt($html, 'maengel'));
        self::assertSame($html, Fragment::abschnitt($html, 'komplett'));
    }

    #[Test]
    public function standzeileMitTimeElement(): void
    {
        $am = new \DateTimeImmutable('2026-11-14T10:00:00Z');

        self::assertSame(
            '<p class="a11y-erklaerung-stand">Version 7, Stand <time datetime="2026-11-14">14.11.2026</time></p>',
            Fragment::standzeile(7, $am, 'de'),
        );
        self::assertSame(
            '<p class="a11y-erklaerung-stand">Version 7, as of <time datetime="2026-11-14">11/14/2026</time></p>',
            Fragment::standzeile(7, $am, 'en'),
        );
        self::assertSame(
            '<p class="a11y-erklaerung-stand">Version 7, as of <time datetime="2026-11-14">11/14/2026</time></p>',
            Fragment::standzeile(7, $am, 'fr'),
        );
    }

    #[Test]
    public function standzeileOhneDatumNurVersion(): void
    {
        self::assertSame('<p class="a11y-erklaerung-stand">Version 7</p>', Fragment::standzeile(7, null, 'de'));
    }

    #[Test]
    public function standzeileUmMitternachtBerlin(): void
    {
        $am = new \DateTimeImmutable('2026-11-13T23:30:00Z');

        self::assertStringContainsString('datetime="2026-11-14"', Fragment::standzeile(7, $am, 'de'));
        self::assertStringContainsString('>14.11.2026<', Fragment::standzeile(7, $am, 'de'));
    }

    #[Test]
    public function umschlagMitUndOhneLang(): void
    {
        self::assertSame('<div class="a11y-erklaerung" lang="de"><p>x</p></div>', Fragment::umschlag('<p>x</p>', 'de', true));
        self::assertSame('<div class="a11y-erklaerung"><p>x</p></div>', Fragment::umschlag('<p>x</p>', 'de', false));
        self::assertSame('<div class="a11y-erklaerung" lang="a&quot;b"></div>', Fragment::umschlag('', 'a"b', true));
    }

    #[Test]
    public function enthaeltKeineTypo3Klasse(): void
    {
        $quelltext = (string)file_get_contents(__DIR__ . '/../../../Classes/Erklaerung/Fragment.php');

        self::assertDoesNotMatchRegularExpression('#TYPO3#', $quelltext);
    }

    #[Test]
    public function fixturesVollstaendig(): void
    {
        $dateien = glob(self::FIXTURES . '/*.html');
        self::assertIsArray($dateien);
        self::assertNotEmpty($dateien);

        foreach ($dateien as $datei) {
            $html = (string)file_get_contents($datei);
            $eingabeSprung = $this->groessterSprung($html);

            foreach (['komplett', 'maengel', 'kontakt'] as $teil) {
                foreach ([2, 3, 4] as $ebene) {
                    $name = basename($datei) . " {$teil} h{$ebene}";
                    $ausgabe = Fragment::ebenenVerschieben(Fragment::abschnitt($html, $teil), $ebene);

                    self::assertDoesNotMatchRegularExpression('#<h1[\s>]#i', $ausgabe, $name);
                    self::assertLessThanOrEqual(max(1, $eingabeSprung), $this->groessterSprung($ausgabe), $name);
                }
            }
        }
    }

    /** Größter Schritt nach unten zwischen aufeinanderfolgenden Überschriften. */
    private function groessterSprung(string $html): int
    {
        preg_match_all('#<h([1-6])[\s>]#i', $html, $treffer);
        $sprung = 0;
        $vorher = null;

        foreach ($treffer[1] as $ebene) {
            $ebene = (int)$ebene;
            if ($vorher !== null) {
                $sprung = max($sprung, $ebene - $vorher);
            }
            $vorher = $ebene;
        }

        return $sprung;
    }
}
