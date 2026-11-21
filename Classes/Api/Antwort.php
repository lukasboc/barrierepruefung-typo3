<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Api;

/**
 * Antwort des Dienstes. `status` 0 heißt: keine Antwort bekommen (Netzfehler).
 */
final readonly class Antwort
{
    /**
     * @param array<mixed>                $daten Dekodierter JSON-Rumpf, leer bei leerem oder ungültigem Rumpf
     * @param array<string, list<string>> $kopf  Kopfzeilen, Namen in Kleinschreibung
     */
    public function __construct(
        public int $status,
        public array $daten = [],
        public array $kopf = [],
        public ?Problem $problem = null,
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300 && $this->problem === null;
    }

    public function etag(): ?string
    {
        return $this->kopf['etag'][0] ?? null;
    }

    public function maxAge(): ?int
    {
        foreach ($this->kopf['cache-control'] ?? [] as $zeile) {
            if (preg_match('/(?:^|[\s,])max-age=(\d+)/i', $zeile, $treffer) === 1) {
                return (int)$treffer[1];
            }
        }

        return null;
    }
}
