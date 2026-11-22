<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Controller;

use Lubomedia\Barrierepruefung\Modul\Sitewahl;
use Lubomedia\Barrierepruefung\Verbindung\Adressabgleich;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownRadio;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Der gemeinsame Rahmen aller Untermodule: Docheader mit Untermodul-Menü (wie TYPO3 es für Module zweiter Ebene
 * zeichnet) und Site-Auswahl, dazu der Redirect nach jeder Aktion (PRG).
 */
final class Modulrahmen
{
    public const SPRACHDATEI = 'LLL:EXT:barrierepruefung/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private readonly ModuleTemplateFactory $vorlagen,
        private readonly Sitewahl $sitewahl,
        private readonly UriBuilder $adressen,
        private readonly LanguageServiceFactory $sprachen,
    ) {}

    public function rahmen(ServerRequestInterface $r, string $untermodul): ModuleTemplate
    {
        $benutzer = $this->benutzer();
        $sites = $this->sitewahl->erlaubteSites($benutzer);
        $site = $this->sitewahl->gewaehlt($r, $benutzer);
        $sprache = $this->sprache();

        $vorlage = $this->vorlagen->create($r);
        $modultitel = $sprache->sL('LLL:EXT:barrierepruefung/Resources/Private/Language/locallang_mod.xlf:barrierepruefung.title');
        $vorlage->setTitle($modultitel, $site !== null ? self::name($site) : '');
        $vorlage->makeDocHeaderModuleMenu($site !== null ? ['site' => $site->getIdentifier()] : []);
        $docheader = $vorlage->getDocHeaderComponent();
        // 13.4 kennt die Methode nicht (und setzt keinen solchen Knopf); PHPStan läuft gegen 14.3.
        if (method_exists($docheader, 'disableAutomaticReloadButton')) { // @phpstan-ignore function.alreadyNarrowedType
            // 14.3 setzt sonst einen zweiten, nur mit Symbol beschrifteten Neuladen-Knopf neben „Status aktualisieren“.
            $docheader->disableAutomaticReloadButton();
        }

        // Eine Auswahl nur, wenn es etwas zu wählen gibt; der Name der Site steht ohnehin in der Überschrift.
        if ($site !== null && count($sites) > 1) {
            $auswahl = GeneralUtility::makeInstance(DropDownButton::class)
                ->setLabel(sprintf($sprache->sL(self::SPRACHDATEI . 'sitewahl.knopf'), self::name($site)))
                ->setTitle($sprache->sL(self::SPRACHDATEI . 'sitewahl.titel'))
                ->setShowLabelText(true);
            foreach ($sites as $eine) {
                $auswahl->addItem(
                    GeneralUtility::makeInstance(DropDownRadio::class)
                        ->setHref((string)$this->adressen->buildUriFromRoute($untermodul, ['site' => $eine->getIdentifier()]))
                        ->setLabel(self::name($eine))
                        ->setActive($eine->getIdentifier() === $site->getIdentifier())
                );
            }
            $vorlage->getDocHeaderComponent()->getButtonBar()->addButton($auswahl, ButtonBar::BUTTON_POSITION_LEFT, 1);
        }

        $vorlage->assignMultiple([
            'site' => $site,
            'siteName' => $site !== null ? self::name($site) : '',
            'modultitel' => $modultitel,
            'istAdmin' => $benutzer->isAdmin(),
        ]);

        return $vorlage;
    }

    /**
     * 303 auf das Untermodul, mit der Site als Parameter.
     */
    public function zurueck(ServerRequestInterface $r, string $untermodul, ?Site $s): ResponseInterface
    {
        $parameter = $s !== null ? ['site' => $s->getIdentifier()] : [];

        return new RedirectResponse((string)$this->adressen->buildUriFromRoute($untermodul, $parameter), 303);
    }

    /**
     * Wie die Site heißt: Titel der Website, sonst Host, sonst Kennung; bei Titel mit Host in Klammern.
     */
    public static function name(Site $site): string
    {
        $titel = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));
        if ($titel === '') {
            $titel = trim($site->getDefaultLanguage()->getWebsiteTitle());
        }
        $basis = (string)$site->getBase();
        $host = Adressabgleich::host($basis) !== null ? (string)parse_url($basis, PHP_URL_HOST) : '';
        $port = Adressabgleich::port($basis);
        if ($host !== '' && $port !== null) {
            $host .= ':' . $port;
        }

        if ($titel === '') {
            return $host !== '' ? $host : $site->getIdentifier();
        }

        return $host !== '' ? $titel . ' (' . $host . ')' : $titel;
    }

    public function sprache(): LanguageService
    {
        return $this->sprachen->createFromUserPreferences($this->benutzer());
    }

    public function benutzer(): BackendUserAuthentication
    {
        $benutzer = $GLOBALS['BE_USER'] ?? null;
        if (!$benutzer instanceof BackendUserAuthentication) {
            throw new \RuntimeException('Das Modul läuft nur mit angemeldeter Backend-Person.', 1763800002);
        }

        return $benutzer;
    }
}
