<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Der einzige Weg zum Dienst. Wirft nie: ein Netzfehler wird zur Antwort mit Status 0.
 */
final class Client
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly Sprache $sprache,
    ) {}

    /**
     * @param array<string, string> $kopf Zusätzliche Kopfzeilen, z. B. If-None-Match
     */
    public function get(Zugang $z, string $pfad, array $kopf = [], float $zeitlimit = 10.0): Antwort
    {
        return $this->anfragen($z, 'GET', $pfad, $kopf, null, $zeitlimit);
    }

    /**
     * @param array<mixed>          $daten JSON-Rumpf
     * @param array<string, string> $kopf  Zusätzliche Kopfzeilen, z. B. Idempotency-Key
     */
    public function senden(Zugang $z, string $methode, string $pfad, array $daten, array $kopf = []): Antwort
    {
        try {
            $rumpf = json_encode($daten, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            return $this->netzfehler('Die Daten konnten nicht als JSON kodiert werden.');
        }

        return $this->anfragen($z, strtoupper($methode), $pfad, $kopf + ['Content-Type' => 'application/json'], $rumpf, 30.0);
    }

    /**
     * @param array<string, string> $kopf
     */
    private function anfragen(Zugang $z, string $methode, string $pfad, array $kopf, ?string $rumpf, float $zeitlimit): Antwort
    {
        $optionen = [
            // Ein Token geht nie an einen anderen Host.
            'allow_redirects' => false,
            'http_errors' => false,
            'timeout' => $zeitlimit,
            'connect_timeout' => min($zeitlimit, 10.0),
            'headers' => $kopf + [
                'Authorization' => 'Bearer ' . $z->token,
                'Accept' => 'application/json',
                'Accept-Language' => $this->sprache->acceptLanguage(),
                'User-Agent' => $this->userAgent(),
            ],
        ];
        if ($rumpf !== null) {
            $optionen['body'] = $rumpf;
        }

        try {
            $antwort = $this->http->request($methode, rtrim($z->dienstUrl, '/') . '/' . ltrim($pfad, '/'), $optionen);
        } catch (GuzzleException $e) {
            return $this->netzfehler('Der Dienst ist nicht erreichbar.');
        }

        return $this->auswerten($antwort);
    }

    private function auswerten(ResponseInterface $antwort): Antwort
    {
        $status = $antwort->getStatusCode();
        $json = json_decode((string)$antwort->getBody(), true);
        $daten = is_array($json) ? $json : [];

        $kopf = [];
        foreach ($antwort->getHeaders() as $name => $werte) {
            $kopf[strtolower((string)$name)] = array_values($werte);
        }

        return new Antwort($status, $daten, $kopf, Problem::ausAntwort($status, $daten));
    }

    private function netzfehler(string $meldung): Antwort
    {
        return new Antwort(0, [], [], Problem::nichtErreichbar($meldung));
    }

    private function userAgent(): string
    {
        // Ohne Paketinformationen (Unit-Tests) liefert TYPO3 einen leeren Text; der User-Agent bleibt trotzdem gültig.
        $version = ExtensionManagementUtility::getExtensionVersion('barrierepruefung');
        if ($version === '') {
            $version = 'dev';
        }

        return 'Barrierepruefung-TYPO3/' . $version . ' (TYPO3 ' . (new Typo3Version())->getVersion() . ')';
    }
}
