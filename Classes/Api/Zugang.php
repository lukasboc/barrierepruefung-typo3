<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Api;

/**
 * Was ein Aufruf des Dienstes braucht: Adresse der API, Kennung der Website, Token.
 */
final readonly class Zugang
{
    public function __construct(
        public string $dienstUrl,
        public string $siteId,
        public string $token,
    ) {}
}
