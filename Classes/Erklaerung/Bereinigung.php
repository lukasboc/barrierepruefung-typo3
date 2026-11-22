<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Erklaerung;

use TYPO3\HtmlSanitizer\Behavior;
use TYPO3\HtmlSanitizer\Builder\CommonBuilder;

/**
 * Bereinigt das HTML aus der API, bevor es auf die Seite kommt.
 *
 * Grundlage ist der `CommonBuilder` von typo3/html-sanitizer, auf dem auch der `DefaultSanitizerBuilder`
 * des Kerns aufbaut. Dieser selbst passt nicht: er erlaubt `style` an jedem Element (das Element hat keine
 * eigene Gestaltung), ist `@internal`, und in 14.3 wirft er ohne `$GLOBALS['TYPO3_REQUEST']`. Er ergänzt
 * sonst nur Links auf den eigenen Host, `t3://` und SVG - alles, was eine Erklärung nicht enthält.
 *
 * Drei Abweichungen vom CommonBuilder: `time` behält `datetime` (die Stand-Zeile); ein unbekanntes
 * Element (`script`, `style`, `iframe` …) fällt samt Inhalt weg, statt als Quelltext sichtbar zu werden;
 * Bilder, Audio und Video fallen weg - eine Erklärung enthält keine, und jedes ließe die Browser der
 * Besucher fremde Inhalte laden.
 */
final class Bereinigung extends CommonBuilder
{
    protected function createBehavior(): Behavior
    {
        return parent::createBehavior()
            ->withName('barrierepruefung')
            ->withFlags(Behavior::REMOVE_UNEXPECTED_CHILDREN | Behavior::ENCODE_INVALID_PROCESSING_INSTRUCTION);
    }

    /**
     * @return array<string, Behavior\Tag>
     */
    protected function createBasicTags(): array
    {
        $tags = parent::createBasicTags();
        $tags['time']->addAttrs(new Behavior\Attr('datetime'));

        return $tags;
    }

    /**
     * @return array<string, Behavior\Tag>
     */
    protected function createMediaTags(): array
    {
        return [];
    }

    public function bereinigen(string $html): string
    {
        return $this->build()->sanitize($html);
    }
}
