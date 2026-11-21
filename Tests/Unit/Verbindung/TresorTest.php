<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Unit\Verbindung;

use Lubomedia\Barrierepruefung\Verbindung\Tresor;
use Lubomedia\Barrierepruefung\Verbindung\TresorFehler;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TresorTest extends UnitTestCase
{
    #[Test]
    public function hinUndZurueck(): void
    {
        $tresor = new Tresor('schluessel-eins');
        $a = $tresor->verschluesseln('geheim');
        $b = $tresor->verschluesseln('geheim');

        self::assertSame('geheim', $tresor->entschluesseln($a));
        self::assertNotSame($a, $b);
        self::assertStringNotContainsString('geheim', $a);
    }

    #[Test]
    public function falscherSchluesselWirftTresorFehler(): void
    {
        $geheim = (new Tresor('schluessel-eins'))->verschluesseln('geheim');

        $this->expectException(TresorFehler::class);
        (new Tresor('schluessel-zwei'))->entschluesseln($geheim);
    }

    #[Test]
    public function muellWirftTresorFehler(): void
    {
        $tresor = new Tresor('schluessel-eins');
        foreach (['kein-base64', '', base64_encode('kurz'), base64_encode(str_repeat('x', 80))] as $muell) {
            try {
                $tresor->entschluesseln($muell);
                self::fail('TresorFehler erwartet für: ' . $muell);
            } catch (TresorFehler) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function leererSchluesselIstEinFehler(): void
    {
        $this->expectException(TresorFehler::class);
        (new Tresor(''))->verschluesseln('x');
    }
}
