<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Verbindung;

use Lubomedia\Barrierepruefung\Api\Antwort;
use Lubomedia\Barrierepruefung\Api\Client;

/**
 * Bittet den Dienst, den Domain-Nachweis zu prüfen: erst über das Meta-Element, dann über die Datei.
 */
final class Verifikation
{
    private const METHODEN = ['meta_tag', 'file'];

    public function __construct(
        private readonly Verbindungen $verbindungen,
        private readonly Client $client,
    ) {}

    /**
     * @throws TresorFehler Wenn der Token sich nicht entschlüsseln lässt (encryptionKey gewechselt)
     */
    public function bestaetigen(Verbindung $v): Verifikationsergebnis
    {
        $zugang = $this->verbindungen->zugang($v);
        $gruende = [];
        foreach (self::METHODEN as $methode) {
            $antwort = $this->client->senden($zugang, 'POST', '/sites/' . rawurlencode($v->siteId) . '/verify', ['method' => $methode]);
            if (($antwort->daten['data']['verified'] ?? false) === true && $antwort->ok()) {
                $this->verbindungen->domainBestaetigt($v->siteIdentifier, time());

                return new Verifikationsergebnis(true, $methode, $gruende);
            }
            $gruende[] = $methode . ':' . $this->grund($antwort);
        }

        return new Verifikationsergebnis(false, null, $gruende);
    }

    private function grund(Antwort $antwort): string
    {
        if (!$antwort->ok()) {
            return $antwort->status === 0 ? 'nicht_erreichbar' : ($antwort->problem->reason ?? 'unbekannt');
        }
        $grund = $antwort->daten['data']['failure_reason'] ?? null;

        return is_string($grund) && $grund !== '' ? $grund : 'unbekannt';
    }
}
