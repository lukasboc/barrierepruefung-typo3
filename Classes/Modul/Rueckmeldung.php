<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Modul;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Die Antwort auf eine Aktion, über den Redirect hinweg (PRG): in den Sitzungsdaten der Backend-Person, genau
 * einmal angezeigt. Bewusst keine FlashMessage: deren Toast verschwindet nach Sekunden (WCAG 2.2.1); die
 * Rückmeldung steht als `callout` oben im Inhalt, bis die Seite verlassen wird.
 *
 * Je Site eine: Die Sitzung gilt für alle Sites, eine Rückmeldung aber nur für die, auf der gehandelt wurde. Wer nach
 * einer Aktion auf Site A die Seite von Site B öffnet, sieht dort nichts von A; die Rückmeldung von A bleibt liegen und
 * erscheint, wenn A wieder gezeigt wird (verworfen würde sie gerade dann, wenn sie etwas Offenes meldet).
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
     * @param string                $site   Kennung der TYPO3-Site, auf der gehandelt wurde
     * @param string                $art    erfolg|fehler|info
     * @param array<string, string> $felder Fehler je Formularfeld
     * @param array<string, string> $werte  Eingaben, die das Formular wieder zeigt (nie ein Token)
     * @param array{uri: string, text: string}|null $link ein Weg weiter, als Link unter dem Text (Adresse aus dem UriBuilder)
     * @param list<array{text: string, uri: string|null}> $liste Einträge unter dem Text, je mit Weg dorthin, wo er sich beheben lässt
     */
    public function merken(string $site, string $art, string $text, array $felder = [], array $werte = [], ?array $link = null, array $liste = []): void
    {
        if (!isset(self::ARTEN[$art])) {
            throw new \InvalidArgumentException('Unbekannte Art der Rückmeldung: ' . $art, 1763800001);
        }
        $benutzer = $this->benutzer();
        if ($benutzer === null) {
            return;
        }
        $alle = self::alle($benutzer);
        $alle[$site] = ['art' => $art, 'text' => $text, 'felder' => $felder, 'werte' => $werte, 'link' => $link, 'liste' => $liste];
        $benutzer->setAndSaveSessionData(self::SCHLUESSEL, $alle);
    }

    /**
     * Die gemerkte Rückmeldung dieser Site, danach ist sie weg; die anderer Sites bleibt liegen. Ohne Site: keine.
     *
     * @return array{art: string, text: string, felder: array<string, string>, werte: array<string, string>, link: array{uri: string, text: string}|null, liste: list<array{text: string, uri: string|null}>, rolle: string, klasse: string, symbol: string}|null
     */
    public function nehmen(?string $site): ?array
    {
        $benutzer = $this->benutzer();
        if ($benutzer === null || $site === null) {
            return null;
        }
        $alle = self::alle($benutzer);
        $daten = $alle[$site] ?? null;
        if (!is_array($daten)) {
            return null;
        }
        unset($alle[$site]);
        $benutzer->setAndSaveSessionData(self::SCHLUESSEL, $alle === [] ? null : $alle);

        $art = is_string($daten['art'] ?? null) && isset(self::ARTEN[$daten['art']]) ? $daten['art'] : 'info';
        $schwere = self::ARTEN[$art];

        return [
            'art' => $art,
            'text' => is_string($daten['text'] ?? null) ? $daten['text'] : '',
            'felder' => self::texte($daten['felder'] ?? null),
            'werte' => self::texte($daten['werte'] ?? null),
            'link' => self::link($daten['link'] ?? null),
            'liste' => self::liste($daten['liste'] ?? null),
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
     * Die gemerkten Rückmeldungen je Site. Eine aus der Zeit vor der Trennung nach Site (ein einzelner Eintrag mit
     * `art`) gehört keiner Site und wird verworfen.
     *
     * @return array<string, array<mixed>>
     */
    private static function alle(BackendUserAuthentication $benutzer): array
    {
        $daten = $benutzer->getSessionData(self::SCHLUESSEL);
        if (!is_array($daten) || array_key_exists('art', $daten)) {
            return [];
        }
        $alle = [];
        foreach ($daten as $site => $eintrag) {
            if (is_string($site) && is_array($eintrag)) {
                $alle[$site] = $eintrag;
            }
        }

        return $alle;
    }

    /**
     * @return list<array{text: string, uri: string|null}>
     */
    private static function liste(mixed $liste): array
    {
        $ergebnis = [];
        foreach (is_array($liste) ? $liste : [] as $eintrag) {
            if (is_array($eintrag) && is_string($eintrag['text'] ?? null) && $eintrag['text'] !== '') {
                $ergebnis[] = ['text' => $eintrag['text'], 'uri' => is_string($eintrag['uri'] ?? null) && $eintrag['uri'] !== '' ? $eintrag['uri'] : null];
            }
        }

        return $ergebnis;
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
