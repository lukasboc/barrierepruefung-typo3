<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Verbindung;

/**
 * Verschlüsselt den Token für die Datenbank. Der Schlüssel wird aus dem encryptionKey der Installation
 * abgeleitet; wechselt der, ist der Token unlesbar und die Verbindung muss neu hergestellt werden.
 */
final class Tresor
{
    public function __construct(private readonly string $encryptionKey) {}

    public static function ausInstallation(): self
    {
        $schluessel = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';

        return new self(is_string($schluessel) ? $schluessel : '');
    }

    public function verschluesseln(string $klar): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($klar, $nonce, $this->schluessel()));
    }

    /**
     * @throws TresorFehler
     */
    public function entschluesseln(string $geheim): string
    {
        $roh = base64_decode($geheim, true);
        if ($roh === false || strlen($roh) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new TresorFehler('Der gespeicherte Token hat kein lesbares Format.');
        }

        $klar = sodium_crypto_secretbox_open(
            substr($roh, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($roh, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->schluessel(),
        );
        if ($klar === false) {
            throw new TresorFehler('Der gespeicherte Token lässt sich mit dem aktuellen encryptionKey nicht entschlüsseln.');
        }

        return $klar;
    }

    private function schluessel(): string
    {
        if ($this->encryptionKey === '') {
            throw new TresorFehler('Die Installation hat keinen encryptionKey.');
        }

        return hash_hkdf('sha256', $this->encryptionKey, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'barrierepruefung-token');
    }
}
