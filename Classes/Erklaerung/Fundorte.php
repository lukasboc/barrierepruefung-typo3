<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Erklaerung;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Wo das Inhaltselement der Erklärung steht: die nicht gelöschten Elemente, deren Seite zur Site gehört.
 * Verborgene Elemente und Elemente auf verborgenen Seiten stehen mit, als „verborgen“ gekennzeichnet: wer die
 * Erklärung sucht, soll auch die noch nicht freigegebene Seite sehen.
 */
final class Fundorte
{
    public const CTYPE = 'barrierepruefung_erklaerung';

    public function __construct(
        private readonly ConnectionPool $datenbank,
        private readonly SiteFinder $sites,
    ) {}

    /**
     * @param BackendUserAuthentication|null $benutzer wenn gesetzt, nur Seiten, die diese Person sehen darf
     *
     * @return list<array{uid: int, pid: int, titel: string, sprache: int, verborgen: bool}>
     */
    public function inSite(Site $s, ?BackendUserAuthentication $benutzer = null): array
    {
        $abfrage = $this->datenbank->getQueryBuilderForTable('tt_content');
        $abfrage->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $zeilen = $abfrage
            ->select('c.uid', 'c.pid', 'c.sys_language_uid', 'c.hidden AS inhalt_verborgen', 'p.title', 'p.hidden AS seite_verborgen')
            ->from('tt_content', 'c')
            ->innerJoin('c', 'pages', 'p', $abfrage->expr()->eq('p.uid', $abfrage->quoteIdentifier('c.pid')))
            ->where($abfrage->expr()->eq('c.CType', $abfrage->createNamedParameter(self::CTYPE)))
            ->orderBy('p.title')
            ->addOrderBy('c.sys_language_uid')
            ->addOrderBy('c.uid')
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var array<int, bool> $erlaubt */
        $erlaubt = [];
        $fundorte = [];
        foreach ($zeilen as $zeile) {
            $seite = (int)$zeile['pid'];
            $erlaubt[$seite] ??= $this->gehoertZu($seite, $s) && ($benutzer === null || $this->darfSehen($seite, $benutzer));
            if (!$erlaubt[$seite]) {
                continue;
            }
            $fundorte[] = [
                'uid' => (int)$zeile['uid'],
                'pid' => $seite,
                'titel' => (string)$zeile['title'],
                'sprache' => (int)$zeile['sys_language_uid'],
                'verborgen' => (int)$zeile['inhalt_verborgen'] === 1 || (int)$zeile['seite_verborgen'] === 1,
            ];
        }

        return $fundorte;
    }

    private function gehoertZu(int $seite, Site $s): bool
    {
        try {
            return $this->sites->getSiteByPageId($seite)->getIdentifier() === $s->getIdentifier();
        } catch (SiteNotFoundException) {
            return false;
        }
    }

    private function darfSehen(int $seite, BackendUserAuthentication $benutzer): bool
    {
        return $benutzer->isAdmin() || is_array(BackendUtility::readPageAccess($seite, $benutzer->getPagePermsClause(Permission::PAGE_SHOW)));
    }
}
