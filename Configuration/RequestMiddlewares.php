<?php

declare(strict_types=1);

use Lubomedia\Barrierepruefung\Middleware\VerifikationsDatei;
use Lubomedia\Barrierepruefung\Middleware\VerifikationsMeta;

// Die Bezeichner gibt es in 13.4 und 14.3 gleich.
return [
    'frontend' => [
        // Nach der Site-Auflösung (die Verbindung hängt an der Site), vor der Seitenauflösung: die Datei ist keine Seite.
        'lubomedia/barrierepruefung/verifikationsdatei' => [
            'target' => VerifikationsDatei::class,
            'after' => ['typo3/cms-frontend/site'],
            'before' => ['typo3/cms-frontend/page-resolver'],
        ],
        // Innerhalb von content-length-headers: Dessen Content-Length wird erst nach unserer Änderung berechnet.
        'lubomedia/barrierepruefung/verifikationsmeta' => [
            'target' => VerifikationsMeta::class,
            'after' => ['typo3/cms-frontend/site', 'typo3/cms-frontend/content-length-headers'],
        ],
    ],
];
