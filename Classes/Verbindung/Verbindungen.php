<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Verbindung;

use Lubomedia\Barrierepruefung\Api\Zugang;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Speicher der Verbindungen, eine Zeile je TYPO3-Site.
 */
final class Verbindungen
{
    public const TABELLE = 'tx_barrierepruefung_verbindung';

    public function __construct(
        private readonly ConnectionPool $verbindungen,
        private readonly Tresor $tresor,
    ) {}

    public function fuerSite(string $siteIdentifier): ?Verbindung
    {
        $zeile = $this->verbindung()->select(['*'], self::TABELLE, ['site_identifier' => $siteIdentifier])->fetchAssociative();

        return is_array($zeile) ? $this->ausZeile($zeile) : null;
    }

    /**
     * @return list<Verbindung>
     */
    public function alle(): array
    {
        $ergebnis = [];
        $zeilen = $this->verbindung()->select(['*'], self::TABELLE, [], [], ['site_identifier' => 'ASC'])->fetchAllAssociative();
        foreach ($zeilen as $zeile) {
            $ergebnis[] = $this->ausZeile($zeile);
        }

        return $ergebnis;
    }

    public function speichern(Verbindung $v): void
    {
        $werte = [
            'tstamp' => time(),
            'site_id' => $v->siteId,
            'dienst_url' => $v->dienstUrl,
            'token_verschluesselt' => $v->tokenVerschluesselt,
            'faehigkeiten' => json_encode($v->faehigkeiten, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'nachweis_meta' => $v->nachweisMeta,
            'nachweis_datei' => $v->nachweisDatei,
            'verbunden_am' => $v->verbundenAm,
            'domain_bestaetigt_am' => $v->domainBestaetigtAm,
        ];
        $typen = ['domain_bestaetigt_am' => $v->domainBestaetigtAm === null ? Connection::PARAM_NULL : Connection::PARAM_INT];

        if ($this->fuerSite($v->siteIdentifier) !== null) {
            $this->verbindung()->update(self::TABELLE, $werte, ['site_identifier' => $v->siteIdentifier], $typen);

            return;
        }
        $this->verbindung()->insert(self::TABELLE, ['site_identifier' => $v->siteIdentifier] + $werte, $typen);
    }

    /**
     * @param array<string, mixed> $token `data.token` der API
     */
    public function faehigkeitenAktualisieren(string $siteIdentifier, array $token): void
    {
        $this->verbindung()->update(
            self::TABELLE,
            ['tstamp' => time(), 'faehigkeiten' => json_encode($token, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
            ['site_identifier' => $siteIdentifier],
        );
    }

    public function domainBestaetigt(string $siteIdentifier, int $am): void
    {
        $this->verbindung()->update(self::TABELLE, ['tstamp' => time(), 'domain_bestaetigt_am' => $am], ['site_identifier' => $siteIdentifier]);
    }

    public function trennen(string $siteIdentifier): void
    {
        $this->verbindung()->delete(self::TABELLE, ['site_identifier' => $siteIdentifier]);
    }

    /**
     * @throws TresorFehler Wenn der Token sich nicht entschlüsseln lässt (encryptionKey gewechselt)
     */
    public function zugang(Verbindung $v): Zugang
    {
        return new Zugang($v->dienstUrl, $v->siteId, $this->tresor->entschluesseln($v->tokenVerschluesselt));
    }

    private function verbindung(): Connection
    {
        return $this->verbindungen->getConnectionForTable(self::TABELLE);
    }

    /**
     * @param array<string, mixed> $zeile
     */
    private function ausZeile(array $zeile): Verbindung
    {
        $faehigkeiten = json_decode((string)($zeile['faehigkeiten'] ?? ''), true);
        $bestaetigt = $zeile['domain_bestaetigt_am'] ?? null;

        return new Verbindung(
            (string)$zeile['site_identifier'],
            (string)$zeile['site_id'],
            (string)$zeile['dienst_url'],
            (string)($zeile['token_verschluesselt'] ?? ''),
            is_array($faehigkeiten) ? $faehigkeiten : [],
            (string)$zeile['nachweis_meta'],
            (string)$zeile['nachweis_datei'],
            (int)$zeile['verbunden_am'],
            $bestaetigt === null ? null : (int)$bestaetigt,
        );
    }
}
