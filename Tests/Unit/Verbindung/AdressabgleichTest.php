<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Unit\Verbindung;

use Lubomedia\Barrierepruefung\Verbindung\Adressabgleich;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AdressabgleichTest extends UnitTestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function paare(): array
    {
        return [
            'www wird ignoriert' => ['https://www.stadt.de/', 'https://stadt.de', true],
            'www auf der anderen Seite' => ['https://stadt.de', 'https://www.stadt.de/', true],
            'Schema egal' => ['http://stadt.de', 'https://stadt.de', true],
            'Gross/Klein' => ['https://Stadt.DE', 'https://stadt.de', true],
            'Unterdomäne ist ein anderer Host' => ['https://buergerbuero.stadt.de', 'https://stadt.de', false],
            'fremde Domain mit Präfix' => ['https://stadt.de', 'https://stadt.de.evil.example', false],
            'ohne Host' => ['/', 'https://stadt.de', false],
            'Dienst ohne Host' => ['https://stadt.de', 'stadt', false],
            'IDN gegen Punycode' => ['https://müller.de', 'https://xn--mller-kva.de', true],
            'anderer Host' => ['https://stadt.de', 'https://andere.de', false],
            'anderer Port auf demselben Host' => ['https://localhost:8089', 'https://localhost:8090', false],
            'gleicher Port' => ['http://localhost:8089/', 'http://localhost:8089', true],
            'Port nur auf einer Seite' => ['https://stadt.de:8443/', 'https://stadt.de', false],
            '443 gilt als kein Port' => ['https://stadt.de:443/', 'https://stadt.de', true],
            '80 gilt als kein Port' => ['http://stadt.de:80', 'https://www.stadt.de', true],
        ];
    }

    #[Test]
    #[DataProvider('paare')]
    public function passt(string $typo3, string $dienst, bool $erwartet): void
    {
        self::assertSame($erwartet, Adressabgleich::passt($typo3, $dienst));
    }

    #[Test]
    public function hostOhneHostIstNull(): void
    {
        self::assertNull(Adressabgleich::host('/'));
        self::assertNull(Adressabgleich::host(''));
        self::assertSame('stadt.de', Adressabgleich::host('https://www.Stadt.de:8443/pfad'));
    }
}
