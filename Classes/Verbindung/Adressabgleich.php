<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Verbindung;

/**
 * Gehört die Adresse der TYPO3-Site zu der Website, die der Dienst kennt? Verglichen wird der Host
 * (Punycode, kleingeschrieben, ohne führendes „www.“), nicht Schema, Port oder Pfad: der Nachweis gelingt
 * ohnehin nur auf dem Host, den der Dienst abfragt.
 */
final class Adressabgleich
{
    public static function passt(string $typo3Basis, string $dienstBasis): bool
    {
        $a = self::host($typo3Basis);
        $b = self::host($dienstBasis);

        return $a !== null && $a === $b;
    }

    /**
     * Normalisierter Host oder null, wenn die Adresse keinen hat (z. B. „/“).
     */
    public static function host(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (is_string($ascii) && $ascii !== '') {
            $host = $ascii;
        }
        if (str_starts_with($host, 'www.') && strlen($host) > 4) {
            $host = substr($host, 4);
        }

        return $host;
    }
}
