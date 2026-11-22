<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\DataProcessing;

use Lubomedia\Barrierepruefung\Erklaerung\Abruf;
use Lubomedia\Barrierepruefung\Erklaerung\Bereinigung;
use Lubomedia\Barrierepruefung\Erklaerung\Fassung;
use Lubomedia\Barrierepruefung\Erklaerung\Fragment;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

/**
 * Rendert die veröffentlichte Erklärung für das Inhaltselement: Teil, Überschriftenebenen, Stand-Zeile,
 * Hinweis der API, Umschlag mit `lang` - und alles durch die Bereinigung.
 *
 * Setzt `erklaerung = {html, zustand}`. Die Seite trägt das Cache-Tag der Website, damit eine neue
 * Fassung genau sie aus dem Seitencache wirft. Ohne gute Fassung lebt die Seite nur kurz im Cache:
 * sonst stünde nach einem Ausfall beim allerersten Abruf 24 h lang „nicht verfügbar“ da.
 */
final class ErklaerungProcessor implements DataProcessorInterface
{
    private const SPRACHDATEI = 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private readonly Verbindungen $verbindungen,
        private readonly Abruf $abruf,
        private readonly LanguageServiceFactory $sprachen,
    ) {}

    /**
     * @param array<mixed> $contentObjectConfiguration
     * @param array<mixed> $processorConfiguration
     * @param array<mixed> $processedData
     *
     * @return array<mixed>
     */
    public function process(ContentObjectRenderer $cObj, array $contentObjectConfiguration, array $processorConfiguration, array $processedData): array
    {
        $request = $cObj->getRequest();
        $site = $request->getAttribute('site');
        $sprache = $request->getAttribute('language');
        $sammler = $request->getAttribute('frontend.cache.collector');
        $sammler = $sammler instanceof CacheDataCollector ? $sammler : null;
        $zeile = is_array($processedData['data'] ?? null) ? $processedData['data'] : [];

        $verbindung = $site instanceof Site ? $this->verbindungen->fuerSite($site->getIdentifier()) : null;
        if ($verbindung === null) {
            // Wird die Site gleich danach verbunden, soll die Seite nicht noch einen Tag „nicht verfügbar“ zeigen.
            $sammler?->restrictMaximumLifetime(Abruf::RUECKFALL_SEKUNDEN);

            return $this->ergebnis($processedData, $this->nichtVerfuegbar($sprache), Fassung::KEINE);
        }

        $sammler?->addCacheTags(new CacheTag(Abruf::tag($verbindung->siteId)));
        $fassung = $this->abruf->fassung($verbindung);
        $erklaerung = $fassung->erklaerung;
        if ($erklaerung === null || !is_string($erklaerung['html'] ?? null)) {
            $sammler?->restrictMaximumLifetime(Abruf::RUECKFALL_SEKUNDEN);

            return $this->ergebnis($processedData, $this->nichtVerfuegbar($sprache), Fassung::KEINE);
        }

        $locale = is_string($erklaerung['locale'] ?? null) ? $erklaerung['locale'] : '';
        $html = Fragment::abschnitt($erklaerung['html'], (string)($zeile['tx_barrierepruefung_teil'] ?? 'komplett'));
        $html = Fragment::ebenenVerschieben($html, (int)($zeile['tx_barrierepruefung_ueberschrift'] ?? 2));

        $version = $erklaerung['version'] ?? null;
        if ((bool)($zeile['tx_barrierepruefung_stand'] ?? true) && is_int($version)) {
            $html .= Fragment::standzeile($version, self::datum($erklaerung['published_at'] ?? null), $locale);
        }
        if (is_string($erklaerung['attribution'] ?? null)) {
            $html .= $erklaerung['attribution'];
        }
        $html = Fragment::umschlag($html, $locale, $locale !== '' && !self::gleicheSprache($locale, $sprache));

        // Die Fassung wird nie übersetzt; ein Rückfall bekommt keinen eigenen Hinweis: ihr Stand-Datum sagt es.
        return $this->ergebnis($processedData, (new Bereinigung())->bereinigen($html), $fassung->zustand);
    }

    /**
     * @param array<mixed> $processedData
     *
     * @return array<mixed>
     */
    private function ergebnis(array $processedData, string $html, string $zustand): array
    {
        $processedData['erklaerung'] = ['html' => $html, 'zustand' => $zustand];

        return $processedData;
    }

    /** In der Sprache der Seite, nicht der einer Backend-Person. */
    private function nichtVerfuegbar(mixed $sprache): string
    {
        $text = $sprache instanceof SiteLanguage
            ? $this->sprachen->createFromSiteLanguage($sprache)->sL(self::SPRACHDATEI . 'inhalt.nicht_verfuegbar')
            : '';
        if ($text === '') {
            $text = 'Die Erklärung zur Barrierefreiheit kann gerade nicht angezeigt werden. Bitte versuchen Sie es später noch einmal.';
        }

        return '<p class="a11y-erklaerung-nicht-verfuegbar">' . htmlspecialchars($text, ENT_QUOTES) . '</p>';
    }

    /** Verglichen wird die Sprache, nicht die Region: `de` auf einer `de_DE`-Seite braucht kein `lang`. */
    private static function gleicheSprache(string $locale, mixed $sprache): bool
    {
        if (!$sprache instanceof SiteLanguage) {
            return false;
        }
        $erklaerung = strtolower((string)preg_replace('/[-_].*$/', '', $locale));

        return $erklaerung === strtolower($sprache->getLocale()->getLanguageCode());
    }

    private static function datum(mixed $wert): ?\DateTimeImmutable
    {
        if (!is_string($wert) || $wert === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($wert);
        } catch (\Exception) {
            return null;
        }
    }
}
