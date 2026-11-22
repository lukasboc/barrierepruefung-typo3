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
 * Vier Abweichungen vom CommonBuilder: `time` behält `datetime` (die Stand-Zeile); ein unbekanntes
 * Element (`script`, `style`, `iframe` …) fällt samt Inhalt weg, statt als Quelltext sichtbar zu werden;
 * Bilder, Audio und Video fallen weg - eine Erklärung enthält keine, und jedes ließe die Browser der
 * Besucher fremde Inhalte laden; und alles, was eine Erklärung nie braucht, aber eine Seite verändern oder
 * ausspähen könnte, fällt ebenfalls weg: `a[ping]`, `tabindex` (es würde die Tab-Reihenfolge der Seite stören),
 * `font`, `center`, `button`, `dialog` und `meta`.
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
        foreach (['font', 'center', 'button', 'dialog', 'meta'] as $name) {
            unset($tags[$name]);
        }
        // `ping` meldet jeden Klick an eine fremde Adresse; der Link wird ohne es neu aufgebaut.
        $tags['a'] = (new Behavior\Tag('a', Behavior\Tag::ALLOW_CHILDREN))
            ->addAttrs(...$this->globalAttrs)
            ->addAttrs($this->hrefAttr, ...$this->createAttrs('download', 'hreflang', 'rel', 'referrerpolicy', 'target', 'type'));
        $tags['time']->addAttrs(new Behavior\Attr('datetime'));

        return $tags;
    }

    /**
     * @return Behavior\Attr[]
     */
    protected function createGlobalAttrs(): array
    {
        return array_values(array_filter(
            parent::createGlobalAttrs(),
            static fn(Behavior\Attr $attr): bool => $attr->getName() !== 'tabindex',
        ));
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
