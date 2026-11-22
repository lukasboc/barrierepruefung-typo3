<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Functional\Erklaerung;

use Lubomedia\Barrierepruefung\Preview\ErklaerungVorschau;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RegistrierungTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['fluid_styled_content'];

    protected array $testExtensionsToLoad = ['lubomedia/barrierepruefung-typo3'];

    #[Test]
    public function elementStehtImAssistentenUnterSpezial(): void
    {
        $eintrag = null;
        foreach ($GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] as $item) {
            if (($item['value'] ?? null) === 'barrierepruefung_erklaerung') {
                $eintrag = $item;
            }
        }

        self::assertIsArray($eintrag);
        self::assertSame('special', $eintrag['group']);
        self::assertSame('barrierepruefung-erklaerung', $eintrag['icon']);
        self::assertNotEmpty($eintrag['description']);
        self::assertSame('barrierepruefung-erklaerung', $GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['barrierepruefung_erklaerung']);
    }

    #[Test]
    public function felderOhneKopfzeileUndMitVorschau(): void
    {
        $typ = $GLOBALS['TCA']['tt_content']['types']['barrierepruefung_erklaerung'];

        self::assertSame(ErklaerungVorschau::class, $typ['previewRenderer']);
        $felder = array_map('trim', explode(',', $typ['showitem']));
        self::assertContains('tx_barrierepruefung_teil', $felder);
        self::assertContains('tx_barrierepruefung_ueberschrift', $felder);
        self::assertContains('tx_barrierepruefung_stand', $felder);
        self::assertNotContains('--palette--;;headers', $felder);
        self::assertNotContains('--palette--;;header', $felder);
        self::assertNotContains('header', $felder);

        $spalten = $GLOBALS['TCA']['tt_content']['columns'];
        self::assertSame('komplett', $spalten['tx_barrierepruefung_teil']['config']['default']);
        self::assertSame(['komplett', 'maengel', 'kontakt'], array_column($spalten['tx_barrierepruefung_teil']['config']['items'], 'value'));
        self::assertSame([2, 3, 4], array_column($spalten['tx_barrierepruefung_ueberschrift']['config']['items'], 'value'));
        self::assertSame(2, $spalten['tx_barrierepruefung_ueberschrift']['config']['default']);
        self::assertSame(1, $spalten['tx_barrierepruefung_stand']['config']['default']);
    }

    #[Test]
    public function spaltenStandardwerteInDerDatenbank(): void
    {
        $verbindung = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $verbindung->insert('tt_content', ['pid' => 1, 'CType' => 'barrierepruefung_erklaerung']);
        $zeile = $verbindung->select(['*'], 'tt_content', ['CType' => 'barrierepruefung_erklaerung'])->fetchAssociative();

        self::assertIsArray($zeile);
        self::assertSame('komplett', $zeile['tx_barrierepruefung_teil']);
        self::assertSame(2, (int)$zeile['tx_barrierepruefung_ueberschrift']);
        self::assertSame(1, (int)$zeile['tx_barrierepruefung_stand']);
    }
}
