<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Erklaerung;

use Lubomedia\Barrierepruefung\Api\Antwort;
use Lubomedia\Barrierepruefung\Api\Client;
use Lubomedia\Barrierepruefung\Verbindung\TresorFehler;
use Lubomedia\Barrierepruefung\Verbindung\Verbindung;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Registry;

/**
 * Holt die veröffentlichte Erklärung einer Website und hält sie bereit.
 *
 * Drei Stufen: der eigene Cache (Lebensdauer aus `max-age` der API), dann der Dienst mit `If-None-Match`,
 * und wenn der nicht antwortet, die letzte gute Fassung aus `sys_registry`. Die Erklärung verschwindet
 * nie, weil der Dienst ausfällt; und während eines Ausfalls fragt nicht jeder Seitenaufruf nach.
 */
final class Abruf
{
    public const ZEITLIMIT_SEKUNDEN = 3.0;
    public const RUECKFALL_SEKUNDEN = 300;
    public const STANDARD_SEKUNDEN = 3600;

    public const CACHE = 'barrierepruefung_erklaerung';
    public const REGISTRY = 'barrierepruefung';
    private const TAG = 'barrierepruefung_site_';

    public function __construct(
        private readonly Client $client,
        private readonly Verbindungen $verbindungen,
        private readonly Registry $registry,
        private readonly CacheManager $caches,
    ) {}

    /** Cache-Tag jeder Seite, die die Erklärung dieser Website zeigt. */
    public static function tag(string $siteId): string
    {
        return self::TAG . $siteId;
    }

    public function fassung(Verbindung $v): Fassung
    {
        $eintrag = $this->cache()->get($this->schluessel($v->siteId));
        if (is_array($eintrag)) {
            return Fassung::ausArray($eintrag);
        }

        return $this->abrufen($v);
    }

    /** Am eigenen Cache vorbei: fragt den Dienst sofort (mit ETag, also meist nur ein 304). */
    public function auffrischen(Verbindung $v): Fassung
    {
        $this->cache()->remove($this->schluessel($v->siteId));

        return $this->fassung($v);
    }

    /** Was `sys_registry` weiß, ohne den Dienst zu fragen - für das Modul. */
    public function stand(string $siteId): Fassung
    {
        $gespeichert = $this->gespeichert($siteId);
        $erklaerung = $this->erklaerungAus($gespeichert);
        $abgerufen = self::zahl($gespeichert['abgerufen_am'] ?? null);
        $fehlerAm = self::zahl($gespeichert['letzter_fehler_am'] ?? null);

        if ($erklaerung === null) {
            $zustand = Fassung::KEINE;
        } elseif ($fehlerAm !== null && ($abgerufen === null || $fehlerAm > $abgerufen)) {
            $zustand = Fassung::RUECKFALL;
        } else {
            $zustand = Fassung::AKTUELL;
        }

        return new Fassung($erklaerung, $zustand, $abgerufen, $fehlerAm, self::text($gespeichert['letzter_fehler'] ?? null));
    }

    private function abrufen(Verbindung $v): Fassung
    {
        $gespeichert = $this->gespeichert($v->siteId);
        $vorher = $this->erklaerungAus($gespeichert);
        $vorherEtag = $vorher === null ? null : self::text($gespeichert['etag'] ?? null);

        try {
            $zugang = $this->verbindungen->zugang($v);
        } catch (TresorFehler $e) {
            // encryptionKey gewechselt: keine Anfrage, aber die Erklärung bleibt stehen.
            return $this->rueckfall($v->siteId, $gespeichert, $e->getMessage());
        }

        $kopf = $vorherEtag === null ? [] : ['If-None-Match' => $vorherEtag];
        $antwort = $this->client->get($zugang, '/sites/' . rawurlencode($v->siteId) . '/declaration', $kopf, self::ZEITLIMIT_SEKUNDEN);
        $jetzt = time();

        if ($antwort->status === 304 && $vorher !== null) {
            $gespeichert['abgerufen_am'] = $jetzt;
            $this->registry->set(self::REGISTRY, $this->registrySchluessel($v->siteId), $gespeichert);

            return $this->merken($v->siteId, new Fassung($vorher, Fassung::AKTUELL, $jetzt, self::zahl($gespeichert['letzter_fehler_am'] ?? null), self::text($gespeichert['letzter_fehler'] ?? null)), $this->lebensdauer($antwort));
        }

        $neu = $antwort->ok() ? ($antwort->daten['declaration'] ?? null) : null;
        if (!is_array($neu) || !is_string($neu['html'] ?? null)) {
            return $this->rueckfall($v->siteId, $gespeichert, $this->fehlertext($antwort));
        }

        /** @var array<string, mixed> $neu */
        $etag = $antwort->etag();
        $geaendert = $etag !== $vorherEtag || $neu !== $vorher;
        if ($geaendert) {
            // Genau die Seiten, die diese Erklärung zeigen, verlieren ihren Seitencache.
            $this->caches->flushCachesInGroupByTag('pages', self::tag($v->siteId));
        }
        $this->registry->set(self::REGISTRY, $this->registrySchluessel($v->siteId), [
            'erklaerung' => $neu,
            'etag' => $etag,
            'abgerufen_am' => $jetzt,
            'letzter_fehler' => self::text($gespeichert['letzter_fehler'] ?? null),
            'letzter_fehler_am' => self::zahl($gespeichert['letzter_fehler_am'] ?? null),
        ]);

        $fassung = new Fassung($neu, Fassung::AKTUELL, $jetzt, self::zahl($gespeichert['letzter_fehler_am'] ?? null), self::text($gespeichert['letzter_fehler'] ?? null), null, $geaendert);

        return $this->merken($v->siteId, $fassung, $this->lebensdauer($antwort));
    }

    /**
     * @param array<mixed> $gespeichert
     */
    private function rueckfall(string $siteId, array $gespeichert, string $fehler): Fassung
    {
        $jetzt = time();
        $gespeichert['letzter_fehler'] = $fehler;
        $gespeichert['letzter_fehler_am'] = $jetzt;
        $this->registry->set(self::REGISTRY, $this->registrySchluessel($siteId), $gespeichert);

        $erklaerung = $this->erklaerungAus($gespeichert);
        $fassung = new Fassung(
            $erklaerung,
            $erklaerung === null ? Fassung::KEINE : Fassung::RUECKFALL,
            self::zahl($gespeichert['abgerufen_am'] ?? null),
            $jetzt,
            $fehler,
        );

        // Kurz merken: während eines Ausfalls fragt nicht jeder Seitenaufruf den Dienst.
        return $this->merken($siteId, $fassung, self::RUECKFALL_SEKUNDEN);
    }

    private function merken(string $siteId, Fassung $fassung, int $sekunden): Fassung
    {
        $sekunden = max(1, $sekunden);
        $fassung = $fassung->mitAblauf(time() + $sekunden);
        $this->cache()->set($this->schluessel($siteId), $fassung->alsArray(), [], $sekunden);

        return $fassung;
    }

    private function lebensdauer(Antwort $antwort): int
    {
        return $antwort->maxAge() ?? self::STANDARD_SEKUNDEN;
    }

    private function fehlertext(Antwort $antwort): string
    {
        if ($antwort->problem !== null) {
            return $antwort->problem->detail;
        }

        return 'Der Dienst hat mit Status ' . $antwort->status . ' ohne lesbare Erklärung geantwortet.';
    }

    /**
     * @return array<mixed>
     */
    private function gespeichert(string $siteId): array
    {
        $wert = $this->registry->get(self::REGISTRY, $this->registrySchluessel($siteId));

        return is_array($wert) ? $wert : [];
    }

    /**
     * @param array<mixed> $gespeichert
     *
     * @return array<string, mixed>|null
     */
    private function erklaerungAus(array $gespeichert): ?array
    {
        $erklaerung = $gespeichert['erklaerung'] ?? null;
        if (!is_array($erklaerung) || !is_string($erklaerung['html'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $erklaerung */
        return $erklaerung;
    }

    private function registrySchluessel(string $siteId): string
    {
        return 'fassung_' . $siteId;
    }

    /** Cache-Kennungen erlauben nur wenige Zeichen; die Website-Kennung geht deshalb gehasht hinein. */
    private function schluessel(string $siteId): string
    {
        return 'fassung_' . sha1($siteId);
    }

    private function cache(): FrontendInterface
    {
        return $this->caches->getCache(self::CACHE);
    }

    private static function zahl(mixed $wert): ?int
    {
        return is_int($wert) ? $wert : null;
    }

    private static function text(mixed $wert): ?string
    {
        return is_string($wert) && $wert !== '' ? $wert : null;
    }
}
