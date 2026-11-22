<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Modul;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Welche TYPO3-Sites eine Backend-Person im Modul sieht: die, deren Wurzelseite in ihrem Web-Mount liegt und
 * die sie anzeigen darf. Das Token einer Verbindung handelt für die ganze Organisation im Dienst – eine Site
 * außerhalb des Mounts darf deshalb weder erscheinen noch über einen gebastelten Parameter erreichbar sein.
 */
final class Sitewahl
{
    /** Eigener Schlüssel der Moduldaten: unter `barrierepruefung` merkt sich TYPO3 das zuletzt benutzte Untermodul. */
    public const MODULDATEN = 'barrierepruefung/sitewahl';

    public function __construct(private readonly SiteFinder $sites) {}

    /**
     * @return list<Site>
     */
    public function erlaubteSites(BackendUserAuthentication $u): array
    {
        $erlaubt = [];
        foreach ($this->sites->getAllSites() as $site) {
            if ($u->isAdmin() || $u->isInWebMount($site->getRootPageId(), $u->getPagePermsClause(Permission::PAGE_SHOW)) !== null) {
                $erlaubt[] = $site;
            }
        }

        return $erlaubt;
    }

    /**
     * Genau diese Site, wenn sie erlaubt ist – für Aktionen, die nie auf eine andere ausweichen dürfen.
     */
    public function erlaubt(mixed $kennung, BackendUserAuthentication $u): ?Site
    {
        if (!is_string($kennung) || $kennung === '') {
            return null;
        }
        foreach ($this->erlaubteSites($u) as $site) {
            if ($site->getIdentifier() === $kennung) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Parameter `site`, sonst die zuletzt gewählte, sonst die erste erlaubte; nie eine nicht erlaubte.
     */
    public function gewaehlt(ServerRequestInterface $r, BackendUserAuthentication $u): ?Site
    {
        $erlaubte = $this->erlaubteSites($u);
        $rumpf = $r->getParsedBody();
        $wunsch = $r->getQueryParams()['site'] ?? (is_array($rumpf) ? ($rumpf['site'] ?? null) : null);
        $moduldaten = $u->getModuleData(self::MODULDATEN);
        $gemerkt = is_array($moduldaten) ? ($moduldaten['site'] ?? null) : null;

        foreach ([$wunsch, $gemerkt] as $kennung) {
            foreach ($erlaubte as $site) {
                if ($site->getIdentifier() === $kennung) {
                    if ($kennung !== $gemerkt) {
                        $u->pushModuleData(self::MODULDATEN, ['site' => $kennung]);
                    }

                    return $site;
                }
            }
        }

        return $erlaubte[0] ?? null;
    }
}
