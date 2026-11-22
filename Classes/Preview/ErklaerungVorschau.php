<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Preview;

use TYPO3\CMS\Backend\Preview\StandardContentPreviewRenderer;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;

/**
 * Vorschau im Seitenmodul: ein Satz mit den Einstellungen des Elements, in der Sprache der Backend-Person.
 * Die Erklärung selbst wird hier nicht abgerufen - das Seitenmodul fragt so nie den Dienst.
 */
final class ErklaerungVorschau extends StandardContentPreviewRenderer
{
    private const SPRACHDATEI = 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_db.xlf:';

    public function renderPageModulePreviewContent(GridColumnItem $item): string
    {
        $zeile = self::zeile($item);
        $teil = (string)($zeile['tx_barrierepruefung_teil'] ?? 'komplett');
        if (!in_array($teil, ['komplett', 'maengel', 'kontakt'], true)) {
            $teil = 'komplett';
        }
        $ebene = max(2, min(4, (int)($zeile['tx_barrierepruefung_ueberschrift'] ?? 2)));
        $stand = (bool)($zeile['tx_barrierepruefung_stand'] ?? true);

        $sprache = $this->getLanguageService();
        $text = sprintf(
            $sprache->sL(self::SPRACHDATEI . 'vorschau.text'),
            $sprache->sL(self::SPRACHDATEI . 'feld.teil.' . $teil),
            $ebene,
            $sprache->sL(self::SPRACHDATEI . ($stand ? 'vorschau.ja' : 'vorschau.nein')),
        );

        return '<p>' . htmlspecialchars($text, ENT_QUOTES) . '</p>';
    }

    /**
     * Die Datenbankzeile des Elements: 14.3 kennt `getRow()`, 13.4 liefert sie aus `getRecord()`.
     *
     * @return array<mixed>
     */
    private static function zeile(GridColumnItem $item): array
    {
        // @phpstan-ignore function.alreadyNarrowedType (gilt nur für 14.3, in 13.4 fehlt getRow())
        if (method_exists($item, 'getRow')) {
            $zeile = $item->getRow();
        } else {
            $zeile = $item->getRecord();
        }

        return is_array($zeile) ? $zeile : [];
    }
}
