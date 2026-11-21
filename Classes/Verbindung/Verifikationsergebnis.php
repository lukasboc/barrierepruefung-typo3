<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Verbindung;

/**
 * Ausgang von Verifikation::bestaetigen().
 */
final readonly class Verifikationsergebnis
{
    /**
     * @param list<string> $gruende `failure_reason` je gescheitertem Versuch, mit Methode davor, z. B. `file:token_nicht_gefunden`
     */
    public function __construct(
        public bool $bestaetigt,
        public ?string $methode = null,
        public array $gruende = [],
    ) {}
}
