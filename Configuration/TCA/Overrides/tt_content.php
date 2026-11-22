<?php

declare(strict_types=1);

use Lubomedia\Barrierepruefung\Preview\ErklaerungVorschau;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Inhaltselement „Erklärung zur Barrierefreiheit“: kein Extbase-Plugin, ein DataProcessor reicht.
// Felder wie Shortcode und Block im WordPress-Plugin (docs/08): Teil, erste Überschriftenebene, Stand-Zeile.
$ll = 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_db.xlf:';

ExtensionManagementUtility::addTCAcolumns('tt_content', [
    'tx_barrierepruefung_teil' => [
        'label' => $ll . 'feld.teil',
        'description' => $ll . 'feld.teil.beschreibung',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                ['label' => $ll . 'feld.teil.komplett', 'value' => 'komplett'],
                ['label' => $ll . 'feld.teil.maengel', 'value' => 'maengel'],
                ['label' => $ll . 'feld.teil.kontakt', 'value' => 'kontakt'],
            ],
            'default' => 'komplett',
        ],
    ],
    'tx_barrierepruefung_ueberschrift' => [
        'label' => $ll . 'feld.ueberschrift',
        'description' => $ll . 'feld.ueberschrift.beschreibung',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                ['label' => $ll . 'feld.ueberschrift.2', 'value' => 2],
                ['label' => $ll . 'feld.ueberschrift.3', 'value' => 3],
                ['label' => $ll . 'feld.ueberschrift.4', 'value' => 4],
            ],
            'default' => 2,
        ],
    ],
    'tx_barrierepruefung_stand' => [
        'label' => $ll . 'feld.stand',
        'description' => $ll . 'feld.stand.beschreibung',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'items' => [
                ['label' => $ll . 'feld.stand.an'],
            ],
            'default' => 1,
        ],
    ],
]);

// Ohne Header-Palette: die Erklärung bringt ihre Überschriften selbst mit.
// Sprache, Zugriff und „Erweitert“ ergänzt der Kern in 13.4 und 14.3 gleich (TcaPreparation).
ExtensionManagementUtility::addRecordType(
    [
        'label' => $ll . 'element.titel',
        'description' => $ll . 'element.beschreibung',
        'value' => 'barrierepruefung_erklaerung',
        'icon' => 'barrierepruefung-erklaerung',
        'group' => 'special',
    ],
    '--palette--;;general, tx_barrierepruefung_teil, tx_barrierepruefung_ueberschrift, tx_barrierepruefung_stand,'
    . ' --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access, --palette--;;hidden, --palette--;;access',
    ['previewRenderer' => ErklaerungVorschau::class],
);
