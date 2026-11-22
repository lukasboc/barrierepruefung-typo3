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
     */
    public function merken(string $art, string $text, array $felder = [], array $werte = []): void
    {
        if (!isset(self::ARTEN[$art])) {
            throw new \InvalidArgumentException('Unbekannte Art der Rückmeldung: ' . $art, 1763800001);
        }
        $this->benutzer()?->setAndSaveSessionData(self::SCHLUESSEL, ['art' => $art, 'text' => $text, 'felder' => $felder, 'werte' => $werte]);
    }

    /**
     * Die gemerkte Rückmeldung, danach ist sie weg.
     *
     * @return array{art: string, text: string, felder: array<string, string>, werte: array<string, string>, rolle: string, klasse: string, symbol: string}|null
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
            // Ein Fehler unterbricht, alles andere wird höflich angesagt.
            'rolle' => $art === 'fehler' ? 'alert' : 'status',
            'klasse' => 'callout-' . $schwere->getCssClass(),
            'symbol' => $schwere->getIconIdentifier(),
        ];
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
