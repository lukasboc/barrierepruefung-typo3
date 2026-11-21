<?php

declare(strict_types=1);

use TYPO3\CodingStandards\CsFixerConfig;

$config = CsFixerConfig::create();
$config->getFinder()
    ->in(__DIR__)
    ->exclude(['vendor', '.Build', 'Build', 'public'])
    ->ignoreVCSIgnored(true);

return $config;
