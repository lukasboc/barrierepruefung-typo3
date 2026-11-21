<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Verbindung;

use Lubomedia\Barrierepruefung\Api\Antwort;
use Lubomedia\Barrierepruefung\Api\Client;
use Lubomedia\Barrierepruefung\Api\Zugang;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Der Anwendungsfall „Verbinden“: Zugang prüfen, Adresse der Site mit der des Dienstes abgleichen,
 * Nachweis-Werte holen, Verbindung mit verschlüsseltem Token speichern. Gespeichert wird erst, wenn alles stimmt.
 */
final class Verbinden
{
    public function __construct(
        private readonly Client $client,
        private readonly Verbindungen $verbindungen,
        private readonly Tresor $tresor,
    ) {}

    public function verbinden(Site $site, string $dienstUrl, string $siteId, string $token): Verbindungsergebnis
    {
        $dienstUrl = trim($dienstUrl);
        $siteId = trim($siteId);
        $token = trim($token);

        $teile = parse_url($dienstUrl);
        $schema = is_array($teile) ? strtolower((string)($teile['scheme'] ?? '')) : '';
        if (!is_array($teile) || !in_array($schema, ['http', 'https'], true) || ($teile['host'] ?? '') === ''
            || preg_match('/\s/', $dienstUrl) === 1 || $siteId === '' || preg_match('/[^A-Za-z0-9_-]/', $siteId) === 1) {
            return Verbindungsergebnis::abgelehnt('dienst_adresse_ungueltig');
        }
        if ($schema === 'http' && !Environment::getContext()->isDevelopment()) {
            return Verbindungsergebnis::abgelehnt('dienst_unsicher');
        }

        // Die Adresse der Standardsprache; Sprachen auf eigenen Domains zählen nicht.
        $typo3Adresse = (string)$site->getBase();
        if (Adressabgleich::host($typo3Adresse) === null) {
            return Verbindungsergebnis::abgelehnt('adresse_ohne_host', $typo3Adresse);
        }

        $zugang = new Zugang($dienstUrl, $siteId, $token);
        $website = $this->client->get($zugang, 'sites/' . rawurlencode($siteId));
        if (!$website->ok()) {
            return $this->ausFehler($website);
        }
        $dienstAdresse = (string)($website->daten['data']['base_url'] ?? '');
        if (!Adressabgleich::passt($typo3Adresse, $dienstAdresse)) {
            return Verbindungsergebnis::abgelehnt('adresse_abweichend', $typo3Adresse, $dienstAdresse);
        }

        $nachweis = $this->client->get($zugang, 'sites/' . rawurlencode($siteId) . '/verification');
        if (!$nachweis->ok()) {
            return $this->ausFehler($nachweis);
        }

        $faehigkeiten = $website->daten['data']['token'] ?? [];
        $verifiziertAm = $website->daten['data']['verified_at'] ?? null;
        $bestaetigt = null;
        if (($website->daten['data']['verified'] ?? false) === true) {
            $zeit = is_string($verifiziertAm) ? strtotime($verifiziertAm) : false;
            $bestaetigt = $zeit !== false ? $zeit : time();
        }

        $this->verbindungen->speichern(new Verbindung(
            $site->getIdentifier(),
            $siteId,
            $dienstUrl,
            $this->tresor->verschluesseln($token),
            is_array($faehigkeiten) ? $faehigkeiten : [],
            (string)($nachweis->daten['data']['meta_tag']['content'] ?? ''),
            (string)($nachweis->daten['data']['file']['content'] ?? ''),
            time(),
            $bestaetigt,
        ));

        return Verbindungsergebnis::verbunden();
    }

    private function ausFehler(Antwort $antwort): Verbindungsergebnis
    {
        $reason = $antwort->problem?->reason;
        if ($antwort->status === 0 || $reason === 'nicht_erreichbar') {
            return Verbindungsergebnis::abgelehnt('nicht_erreichbar', detail: $antwort->problem?->detail);
        }

        return Verbindungsergebnis::abgelehnt('api:' . ($reason ?? 'unbekannt'), detail: $antwort->problem?->detail);
    }
}
