<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Unit\Erklaerung;

use Lubomedia\Barrierepruefung\Erklaerung\Bereinigung;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BereinigungTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        foreach (glob(__DIR__ . '/../../Fixtures/fragment/*.html') ?: [] as $datei) {
            yield basename($datei) => [$datei];
        }
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function dieFixturesGehenUnveraendertDurch(string $datei): void
    {
        $html = trim((string)file_get_contents($datei));

        self::assertSame($html, trim((new Bereinigung())->bereinigen($html)));
    }

    #[Test]
    public function eineErklaerungOhneFixturesHatNeunDavon(): void
    {
        self::assertCount(9, glob(__DIR__ . '/../../Fixtures/fragment/*.html') ?: []);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nichtsDavonGehoertInEineErklaerung(): iterable
    {
        yield 'ping' => ['<p><a href="https://example.org/" ping="https://spur.example/">Link</a></p>', 'ping='];
        yield 'tabindex' => ['<p tabindex="0">Text</p>', 'tabindex'];
        yield 'tabindex am Link' => ['<p><a href="https://example.org/" tabindex="5">Link</a></p>', 'tabindex'];
        yield 'font' => ['<p><font color="red" face="x" size="7">Text</font></p>', '<font'];
        yield 'center' => ['<center>Text</center>', '<center'];
        yield 'button' => ['<p><button>Klick</button></p>', '<button'];
        yield 'dialog' => ['<dialog open>Fenster</dialog>', '<dialog'];
        yield 'meta' => ['<p>Text</p><meta content="0">', '<meta'];
    }

    #[Test]
    #[DataProvider('nichtsDavonGehoertInEineErklaerung')]
    public function entferntWasEineErklaerungNieEnthaelt(string $html, string $verboten): void
    {
        self::assertStringNotContainsString($verboten, (new Bereinigung())->bereinigen($html));
    }

    #[Test]
    public function linksBehaltenHrefUndRel(): void
    {
        $ausgabe = (new Bereinigung())->bereinigen('<p><a href="https://example.org/" rel="noopener" target="_blank" hreflang="de">Link</a></p>');

        self::assertStringContainsString('href="https://example.org/"', $ausgabe);
        self::assertStringContainsString('rel="noopener"', $ausgabe);
    }
}
