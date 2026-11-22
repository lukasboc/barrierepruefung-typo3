<?php

declare(strict_types=1);

use Lubomedia\Barrierepruefung\Erklaerung\Abruf;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Eigener Cache der Erklärung. Gruppe „pages“: „Frontend-Caches leeren“ holt sie neu.
// Nur vorbelegen, damit eine Installation Backend oder Lebensdauer selbst setzen kann.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][Abruf::CACHE] ??= [];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][Abruf::CACHE] += [
    'frontend' => VariableFrontend::class,
    'backend' => Typo3DatabaseBackend::class,
    'groups' => ['pages'],
    'options' => ['defaultLifetime' => Abruf::STANDARD_SEKUNDEN],
];

// Global statt als Site-Set: das Element funktioniert so auch in Sites mit sys_template.
ExtensionManagementUtility::addTypoScriptSetup(
    "@import 'EXT:barrierepruefung/Configuration/TypoScript/setup.typoscript'"
);
