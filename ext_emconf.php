<?php

// Metadaten für TER und klassische Installationen; Composer-Betrieb liest composer.json.
$EM_CONF[$_EXTKEY] = [
    'title' => 'Barrierepruefung.de – Barrierefreiheitserklärung',
    'description' => 'Verbindet TYPO3-Sites mit barrierepruefung.de: Domain bestätigen, Prüfung starten, Befunde ansehen, die veröffentlichte Barrierefreiheitserklärung als Inhaltselement einbetten, den Weg zur Erklärung gehen und sie freigeben.',
    'category' => 'plugin',
    'author' => 'Lukas Bock',
    'author_email' => '',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.3.99',
            'php' => '8.2.0-8.5.99',
            'fluid_styled_content' => '13.4.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'scheduler' => '',
        ],
    ],
];
