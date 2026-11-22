<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Erklaerung;

use Lubomedia\Barrierepruefung\Controller\Modulrahmen;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Legt die Seite für die Erklärung an: verborgen, unter der Wurzelseite der Site, mit einem Element der
 * Erklärung. Das Anlegen geht über den DataHandler mit der angemeldeten Backend-Person – ihre Rechte
 * (Seiten anlegen, Tabellen ändern, erlaubte Inhaltstypen) prüft der DataHandler, nicht diese Klasse.
 */
final class SeiteAnlegen
{
    public function __construct(private readonly LanguageServiceFactory $sprachen) {}

    /**
     * @return int uid der neuen Seite
     *
     * @throws SeiteNichtAngelegt
     */
    public function anlegen(Site $s): int
    {
        // Zwei Läufe statt eines: Scheitert das Anlegen der Seite (kein Recht), löst der DataHandler 13.4 die Seite
        // „NEW…“ für das Element nicht auf und meldet dafür eine PHP-Warnung. So kennt der zweite Lauf die echte uid.
        $seite = $this->lauf(['pages' => ['NEW_seite' => ['pid' => $s->getRootPageId(), 'title' => $this->titel($s), 'doktype' => 1, 'hidden' => 1]]], 'NEW_seite');
        try {
            $this->lauf(['tt_content' => ['NEW_element' => ['pid' => $seite, 'CType' => Fundorte::CTYPE, 'colPos' => 0, 'sys_language_uid' => 0]]], 'NEW_element');
        } catch (SeiteNichtAngelegt $e) {
            // Keine leere Seite zurücklassen; gelingt auch das nicht, steht sie verborgen da und die Rückmeldung bleibt ein Fehler.
            $aufraeumen = GeneralUtility::makeInstance(DataHandler::class);
            $aufraeumen->start([], ['pages' => [$seite => ['delete' => 1]]]);
            $aufraeumen->process_cmdmap();

            throw $e;
        }

        return $seite;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $daten
     */
    private function lauf(array $daten, string $platzhalter): int
    {
        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start($daten, []);
        $dh->process_datamap();

        $uid = $dh->substNEWwithIDs[$platzhalter] ?? null;
        if ($dh->errorLog !== [] || !is_int($uid)) {
            throw new SeiteNichtAngelegt('Der DataHandler hat die Seite nicht angelegt: ' . implode(' | ', $dh->errorLog), 1763800301);
        }

        return $uid;
    }

    /** Der Titel in der Standardsprache der Site, nicht in der Sprache der Backend-Person: die Seite gehört der Website. */
    public function titel(Site $s): string
    {
        // Nur der Sprachcode: 13.4 findet die Übersetzung unter „de-DE“ nicht (die Datei heißt `de.locallang.xlf`), 14.3 schon.
        $titel = $this->sprachen->create($s->getDefaultLanguage()->getLocale()->getLanguageCode())->sL(Modulrahmen::SPRACHDATEI . 'seite.titel');

        return $titel !== '' ? $titel : 'Accessibility statement';
    }
}
