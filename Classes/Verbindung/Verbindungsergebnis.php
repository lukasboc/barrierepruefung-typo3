<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Verbindung;

/**
 * Ausgang von Verbinden. `grund`: adresse_ohne_host | adresse_abweichend | dienst_unsicher
 * | dienst_adresse_ungueltig | api:<reason> | nicht_erreichbar.
 */
final readonly class Verbindungsergebnis
{
    public function __construct(
        public bool $ok,
        public ?string $grund = null,
        public ?string $typo3Adresse = null,
        public ?string $dienstAdresse = null,
        public ?string $detail = null,
    ) {}

    public static function verbunden(): self
    {
        return new self(true);
    }

    public static function abgelehnt(string $grund, ?string $typo3Adresse = null, ?string $dienstAdresse = null, ?string $detail = null): self
    {
        return new self(false, $grund, $typo3Adresse, $dienstAdresse, $detail);
    }
}
