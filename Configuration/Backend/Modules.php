<?php

declare(strict_types=1);

use Lubomedia\Barrierepruefung\Controller\BefundeController;
use Lubomedia\Barrierepruefung\Controller\ErklaerungController;
use Lubomedia\Barrierepruefung\Controller\UebersichtController;
use Lubomedia\Barrierepruefung\Controller\WegController;

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
    'barrierepruefung_befunde' => [
        'parent' => 'barrierepruefung',
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/barrierepruefung/befunde',
        'iconIdentifier' => 'barrierepruefung-modul',
        'labels' => [
            'title' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:befunde.title',
            'description' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:befunde.description',
            'shortDescription' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:befunde.shortDescription',
        ],
        'routes' => [
            '_default' => [
                'target' => BefundeController::class . '::index',
            ],
        ],
    ],
    'barrierepruefung_erklaerung' => [
        'parent' => 'barrierepruefung',
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/barrierepruefung/erklaerung',
        'iconIdentifier' => 'barrierepruefung-modul',
        'labels' => [
            'title' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:erklaerung.title',
            'description' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:erklaerung.description',
            'shortDescription' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:erklaerung.shortDescription',
        ],
        'routes' => [
            '_default' => [
                'target' => ErklaerungController::class . '::index',
            ],
            'seiteAnlegen' => [
                'target' => ErklaerungController::class . '::seiteAnlegen',
                'methods' => ['POST'],
            ],
            'abgleichen' => [
                'target' => ErklaerungController::class . '::abgleichen',
                'methods' => ['POST'],
            ],
        ],
    ],
    'barrierepruefung_weg' => [
        'parent' => 'barrierepruefung',
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/barrierepruefung/weg',
        'iconIdentifier' => 'barrierepruefung-modul',
        'labels' => [
            'title' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:weg.title',
            'description' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:weg.description',
            'shortDescription' => 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:weg.shortDescription',
        ],
        'routes' => [
            '_default' => [
                'target' => WegController::class . '::index',
            ],
            'pruefschritte' => [
                'target' => WegController::class . '::pruefschritte',
                'methods' => ['POST'],
            ],
            'stelle' => [
                'target' => WegController::class . '::stelle',
                'methods' => ['POST'],
            ],
            'betroffenheit' => [
                'target' => WegController::class . '::betroffenheit',
                'methods' => ['POST'],
            ],
            'angaben' => [
                'target' => WegController::class . '::angaben',
                'methods' => ['POST'],
            ],
            'freigabe' => [
                'target' => WegController::class . '::freigabe',
            ],
            'freigeben' => [
                'target' => WegController::class . '::freigeben',
                'methods' => ['POST'],
            ],
        ],
    ],
];
