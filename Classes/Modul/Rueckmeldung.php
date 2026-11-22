<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Modul;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Die Antwort auf eine Aktion, über den Redirect hinweg (PRG): in den Sitzungsdaten der Backend-Person, genau
 * einmal angezeigt. Bewusst keine FlashMessage: deren Toast verschwindet nach Sekunden (WCAG 2.2.1); die
 * Rückmeldung steht als `callout` oben im Inhalt, bis die Seite verlassen wird.
 */
final class Rueckmeldung
{
    private const SCHLUESSEL = 'barrierepruefung_rueckmeldung';

    private const ARTEN = [
        'erfolg' => ContextualFeedbackSeverity::OK,
        'fehler' => ContextualFeedbackSeverity::ERROR,
        'info' => ContextualFeedbackSeverity::INFO,
    ];

    /**
     * @param string                $art    erfolg|fehler|info
     * @param array<string, string> $felder Fehler je Formularfeld
     * @param array<string, string> $werte  Eingaben, die das Formular wieder zeigt (nie ein Token)
     * @param array{uri: string, text: string}|null $link ein Weg weiter, als Link unter dem Text (Adresse aus dem UriBuilder)
     */
    public function merken(string $art, string $text, array $felder = [], array $werte = [], ?array $link = null): void
    {
        if (!isset(self::ARTEN[$art])) {
            throw new \InvalidArgumentException('Unbekannte Art der Rückmeldung: ' . $art, 1763800001);
        }
        $this->benutzer()?->setAndSaveSessionData(self::SCHLUESSEL, ['art' => $art, 'text' => $text, 'felder' => $felder, 'werte' => $werte, 'link' => $link]);
    }

    /**
     * Die gemerkte Rückmeldung, danach ist sie weg.
     *
     * @return array{art: string, text: string, felder: array<string, string>, werte: array<string, string>, link: array{uri: string, text: string}|null, rolle: string, klasse: string, symbol: string}|null
     */
    public function nehmen(): ?array
    {
        $benutzer = $this->benutzer();
        $daten = $benutzer?->getSessionData(self::SCHLUESSEL);
        if ($benutzer === null || !is_array($daten)) {
            return null;
        }
        $benutzer->setAndSaveSessionData(self::SCHLUESSEL, null);

        $art = is_string($daten['art'] ?? null) && isset(self::ARTEN[$daten['art']]) ? $daten['art'] : 'info';
        $schwere = self::ARTEN[$art];

        return [
            'art' => $art,
            'text' => is_string($daten['text'] ?? null) ? $daten['text'] : '',
            'felder' => self::texte($daten['felder'] ?? null),
            'werte' => self::texte($daten['werte'] ?? null),
            'link' => self::link($daten['link'] ?? null),
            // Ein Fehler unterbricht, alles andere wird höflich angesagt.
            'rolle' => $art === 'fehler' ? 'alert' : 'status',
            'klasse' => 'callout-' . $schwere->getCssClass(),
            'symbol' => $schwere->getIconIdentifier(),
        ];
    }

    /**
     * @return array{uri: string, text: string}|null
     */
    private static function link(mixed $link): ?array
    {
        return is_array($link) && is_string($link['uri'] ?? null) && $link['uri'] !== '' && is_string($link['text'] ?? null)
            ? ['uri' => $link['uri'], 'text' => $link['text']]
            : null;
    }

    /**
     * @return array<string, string>
     */
    private static function texte(mixed $liste): array
    {
        $ergebnis = [];
        foreach (is_array($liste) ? $liste : [] as $schluessel => $wert) {
            if (is_string($schluessel) && is_string($wert)) {
                $ergebnis[$schluessel] = $wert;
            }
        }

        return $ergebnis;
    }

    private function benutzer(): ?BackendUserAuthentication
    {
        $benutzer = $GLOBALS['BE_USER'] ?? null;

        return $benutzer instanceof BackendUserAuthentication ? $benutzer : null;
    }
}
