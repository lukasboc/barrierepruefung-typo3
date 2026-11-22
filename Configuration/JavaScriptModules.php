<?php

declare(strict_types=1);

// Nur für das Backend-Modul (das Frontend bleibt ohne Skripte).
return [
    'dependencies' => ['backend'],
    'imports' => [
        '@lubomedia/barrierepruefung/' => 'EXT:barrierepruefung/Resources/Public/JavaScript/',
    ],
];
