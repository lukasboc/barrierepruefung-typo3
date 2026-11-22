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
        $titel = $this->titel($s);
        $daten = [
            'pages' => ['NEW_seite' => ['pid' => $s->getRootPageId(), 'title' => $titel, 'doktype' => 1, 'hidden' => 1]],
            'tt_content' => ['NEW_element' => ['pid' => 'NEW_seite', 'CType' => Fundorte::CTYPE, 'colPos' => 0, 'sys_language_uid' => 0]],
        ];

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start($daten, []);
        $dh->process_datamap();

        $seite = $dh->substNEWwithIDs['NEW_seite'] ?? null;
        $element = $dh->substNEWwithIDs['NEW_element'] ?? null;
        if ($dh->errorLog !== [] || !is_int($seite) || !is_int($element)) {
            file_put_contents('/private/tmp/claude-501/-Users-lukas-PhpstormProjects-web-accessibility-checker-web-accessibility-checker/da43beff-f97b-4301-b6a9-52fedab03c53/scratchpad/dh.log', json_encode([$dh->errorLog, $dh->substNEWwithIDs]));
            throw new SeiteNichtAngelegt('Der DataHandler hat die Seite nicht angelegt: ' . implode(' | ', $dh->errorLog), 1763800301);
        }

        return $seite;
    }

    /** Der Titel in der Standardsprache der Site, nicht in der Sprache der Backend-Person: die Seite gehört der Website. */
    public function titel(Site $s): string
    {
        $titel = $this->sprachen->create($s->getDefaultLanguage()->getLocale())->sL(Modulrahmen::SPRACHDATEI . 'seite.titel');

        return $titel !== '' ? $titel : 'Accessibility statement';
    }
}
