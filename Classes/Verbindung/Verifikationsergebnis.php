<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Verbindung;

use Lubomedia\Barrierepruefung\Api\Antwort;

/**
 * Ausgang von Verifikation::bestaetigen().
 */
final readonly class Verifikationsergebnis
{
    /**
     * @param list<string> $gruende `failure_reason` je gescheitertem Versuch, mit Methode davor, z. B. `file:token_nicht_gefunden`
     * @param Antwort|null $fehler  Die Antwort des Dienstes, wenn er die Anfrage selbst abgelehnt hat (kein 2xx): dann ist nichts geprüft worden
     */
    public function __construct(
        public bool $bestaetigt,
        public ?string $methode = null,
        public array $gruende = [],
        public ?Antwort $fehler = null,
    ) {}
}
