<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Verbindung;

/**
 * Die Verbindung einer TYPO3-Site zu ihrer Website im Dienst.
 */
final readonly class Verbindung
{
    /**
     * @param array<string, mixed> $faehigkeiten JSON von `data.token` der API: `abilities` und `publish`
     */
    public function __construct(
        public string $siteIdentifier,
        public string $siteId,
        public string $dienstUrl,
        public string $tokenVerschluesselt,
        public array $faehigkeiten,
        public string $nachweisMeta,
        public string $nachweisDatei,
        public int $verbundenAm,
        public ?int $domainBestaetigtAm = null,
    ) {}

    public function darfVorbereiten(): bool
    {
        return $this->hat('declaration:prepare') && $this->freigaberecht() === 'erlaubt';
    }

    public function darfFreigeben(): bool
    {
        return $this->hat('declaration:publish') && $this->freigaberecht() === 'erlaubt';
    }

    /**
     * `data.token.publish.state` der API; `recht_fehlt`, solange sie nichts anderes gesagt hat.
     */
    public function freigaberecht(): string
    {
        $publish = $this->faehigkeiten['publish'] ?? null;
        $stand = is_array($publish) ? ($publish['state'] ?? null) : null;

        return is_string($stand) && $stand !== '' ? $stand : 'recht_fehlt';
    }

    private function hat(string $recht): bool
    {
        $rechte = $this->faehigkeiten['abilities'] ?? null;

        return is_array($rechte) && in_array($recht, $rechte, true);
    }
}
