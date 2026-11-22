<?php

declare(strict_types=1);

use Lubomedia\Barrierepruefung\Controller\UebersichtController;

/*
 * Hauptgruppe `site` („Site Management“): in 13.4 und 14.3 derselbe Schlüssel. Das Modul selbst hat keine Route;
 * TYPO3 leitet auf das zuletzt benutzte bzw. erste erlaubte Untermodul weiter und zeigt die Untermodule im Docheader.
 */
return [
    'barrierepruefung' => [
        'parent' => 'site',
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/barrierepruefung',
        'iconIdentifier' => 'barrierepruefung-modul',
        'labels' => [
            'title' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:barrierepruefung.title',
            'description' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:barrierepruefung.description',
            'shortDescription' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:barrierepruefung.shortDescription',
        ],
    ],
    'barrierepruefung_uebersicht' => [
        'parent' => 'barrierepruefung',
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/barrierepruefung/uebersicht',
        'iconIdentifier' => 'barrierepruefung-modul',
        'labels' => [
            'title' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:uebersicht.title',
            'description' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:uebersicht.description',
            'shortDescription' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:uebersicht.shortDescription',
        ],
        'routes' => [
            '_default' => [
                'target' => UebersichtController::class . '::index',
            ],
            'verbinden' => [
                'target' => UebersichtController::class . '::verbinden',
                'methods' => ['POST'],
            ],
            'trennen' => [
                'target' => UebersichtController::class . '::trennen',
                'methods' => ['POST'],
            ],
            'bestaetigen' => [
                'target' => UebersichtController::class . '::bestaetigen',
                'methods' => ['POST'],
            ],
            'pruefen' => [
                'target' => UebersichtController::class . '::pruefen',
                'methods' => ['POST'],
            ],
            'aktualisieren' => [
                'target' => UebersichtController::class . '::aktualisieren',
                'methods' => ['POST'],
            ],
        ],
    ],
];
