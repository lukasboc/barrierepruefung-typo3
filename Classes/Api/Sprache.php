<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Api;

/**
 * Die Sprache, in der der Dienst seine Texte liefern soll: die der Backend-Person, sonst Deutsch.
 */
final class Sprache
{
    /**
     * @param (\Closure(): ?string)|null $backendSprache Liefert das `lang` der Backend-Person; null = aus $GLOBALS['BE_USER']
     */
    public function __construct(
        private readonly ?\Closure $backendSprache = null,
    ) {}

    public function acceptLanguage(): string
    {
        $lang = $this->backendSprache !== null ? ($this->backendSprache)() : $this->ausBackendBenutzer();

        // Ohne Backend-Person (Frontend, CLI) gilt Deutsch; '' und 'default' sind in TYPO3 Englisch.
        if ($lang === null) {
            return 'de';
        }

        // Nicht str_starts_with('de'): „default“ beginnt auch so und ist Englisch.
        return preg_match('/^de(?:[-_]|$)/i', $lang) === 1 ? 'de' : 'en';
    }

    private function ausBackendBenutzer(): ?string
    {
        $benutzer = $GLOBALS['BE_USER'] ?? null;
        if (!is_object($benutzer) || !isset($benutzer->user) || !is_array($benutzer->user) || !isset($benutzer->user['uid'])) {
            return null;
        }
        $lang = $benutzer->user['lang'] ?? '';

        return is_string($lang) ? $lang : '';
    }
}
