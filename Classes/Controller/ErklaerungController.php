<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Controller;

use Lubomedia\Barrierepruefung\Erklaerung\Abruf;
use Lubomedia\Barrierepruefung\Erklaerung\Fassung;
use Lubomedia\Barrierepruefung\Erklaerung\Fundorte;
use Lubomedia\Barrierepruefung\Erklaerung\SeiteAnlegen;
use Lubomedia\Barrierepruefung\Erklaerung\SeiteNichtAngelegt;
use Lubomedia\Barrierepruefung\Modul\Anzeige;
use Lubomedia\Barrierepruefung\Modul\Rueckmeldung;
use Lubomedia\Barrierepruefung\Modul\Sitewahl;
use Lubomedia\Barrierepruefung\Verbindung\TresorFehler;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Untermodul „Erklärung“: der Stand der veröffentlichten Erklärung, die Seiten, die sie zeigen, und „Seite mit
 * Erklärung anlegen“. Der Stand kommt aus der Registry (kein Abruf beim Anzeigen); „Jetzt abgleichen“ fragt den
 * Dienst. Jede Aktion antwortet mit 303 und einer Rückmeldung oben im Inhalt (PRG).
 */
#[AsController]
final class ErklaerungController
{
    public const UNTERMODUL = 'barrierepruefung_erklaerung';

    public function __construct(
        private readonly Modulrahmen $rahmen,
        private readonly Sitewahl $sitewahl,
        private readonly Rueckmeldung $rueckmeldung,
        private readonly Verbindungen $verbindungen,
        private readonly Abruf $abruf,
        private readonly Fundorte $fundorte,
        private readonly SeiteAnlegen $seiteAnlegen,
        private readonly Anzeige $anzeige,
        private readonly UriBuilder $adressen,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $benutzer = $this->rahmen->benutzer();
        $vorlage = $this->rahmen->rahmen($request, self::UNTERMODUL);
        $site = $this->sitewahl->gewaehlt($request, $benutzer);
        $vorlage->assign('rueckmeldung', $this->rueckmeldung->nehmen());
        if ($site === null) {
            return $vorlage->assign('keineSite', true)->renderResponse('Erklaerung/Index');
        }

        $verbindung = $this->verbindungen->fuerSite($site->getIdentifier());
        $zustand = 'nichtVerbunden';
        $erklaerung = null;
        if ($verbindung !== null) {
            $zustand = 'verbunden';
            try {
                $this->verbindungen->zugang($verbindung);
            } catch (TresorFehler) {
                $zustand = 'neuVerbinden';
            }
            $erklaerung = $this->anzeige->erklaerung($this->abruf->stand($verbindung->siteId));
        }

        $seiten = [];
        foreach ($this->fundorte->inSite($site, $benutzer) as $ort) {
            $seiten[] = [
                'uid' => $ort['uid'],
                'titel' => $ort['titel'],
                'sprache' => $this->sprachtitel($site, $ort['sprache']),
                // Eine Übersetzung zeigt die Erklärung trotzdem in der Sprache, in der sie freigegeben wurde.
                'uebersetzung' => $ort['sprache'] > 0,
                'verborgen' => $ort['verborgen'],
                'uri' => $this->seitenmodul($ort['pid']),
            ];
        }

        return $vorlage->assignMultiple([
            'zustand' => $zustand,
            'erklaerung' => $erklaerung,
            'freigegebeneSprache' => $erklaerung['sprache'] ?? null,
            'seiten' => $seiten,
            'uebersichtUri' => (string)$this->adressen->buildUriFromRoute(UebersichtController::UNTERMODUL, ['site' => $site->getIdentifier()]),
        ])->renderResponse('Erklaerung/Index');
    }

    public function seiteAnlegen(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->site($request);
        if ($site === null) {
            return $this->verboten();
        }

        try {
            $seite = $this->seiteAnlegen->anlegen($site);
        } catch (SeiteNichtAngelegt) {
            $this->rueckmeldung->merken('fehler', $this->anzeige->t('seite.fehler'));

            return $this->rahmen->zurueck($request, self::UNTERMODUL, $site);
        }
        $this->rueckmeldung->merken(
            'erfolg',
            $this->anzeige->t('seite.angelegt', $this->seiteAnlegen->titel($site)),
            [],
            [],
            ['uri' => $this->seitenmodul($seite), 'text' => $this->anzeige->t('seite.oeffnen')],
        );

        return $this->rahmen->zurueck($request, self::UNTERMODUL, $site);
    }

    public function abgleichen(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->site($request);
        if ($site === null) {
            return $this->verboten();
        }

        $verbindung = $this->verbindungen->fuerSite($site->getIdentifier());
        if ($verbindung === null) {
            $this->rueckmeldung->merken('fehler', $this->anzeige->t('befunde.nicht_verbunden'));

            return $this->rahmen->zurueck($request, self::UNTERMODUL, $site);
        }
        try {
            $this->verbindungen->zugang($verbindung);
        } catch (TresorFehler) {
            $this->rueckmeldung->merken('fehler', $this->anzeige->t('fehler.neu_verbinden'));

            return $this->rahmen->zurueck($request, self::UNTERMODUL, $site);
        }

        $fassung = $this->abruf->auffrischen($verbindung);
        if ($fassung->zustand === Fassung::AKTUELL) {
            $anzeige = $this->anzeige->erklaerung($fassung);
            $this->rueckmeldung->merken('erfolg', $this->anzeige->t('abgleichen.erfolg', (string)($anzeige['fassung'] ?? ''), (string)($anzeige['stand'] ?? '')));
        } else {
            $fehler = $this->anzeige->erklaerungsfehler($fassung);
            // „Im Dienst ist nichts veröffentlicht“ ist kein Fehler der Verbindung, nur ein Befund.
            $this->rueckmeldung->merken($fehler['art'] === 'nicht_veroeffentlicht' ? 'info' : 'fehler', implode(' ', $fehler['saetze']));
        }

        return $this->rahmen->zurueck($request, self::UNTERMODUL, $site);
    }

    private function site(ServerRequestInterface $request): ?Site
    {
        $rumpf = $request->getParsedBody();

        return $this->sitewahl->erlaubt(is_array($rumpf) ? ($rumpf['site'] ?? null) : null, $this->rahmen->benutzer());
    }

    private function seitenmodul(int $seite): string
    {
        return (string)$this->adressen->buildUriFromRoute('web_layout', ['id' => $seite]);
    }

    private function sprachtitel(Site $site, int $sprache): string
    {
        try {
            return $site->getLanguageById($sprache)->getTitle();
        } catch (\InvalidArgumentException) {
            return '#' . $sprache;
        }
    }

    private function verboten(): ResponseInterface
    {
        return new HtmlResponse(htmlspecialchars($this->anzeige->t('verboten')), 403);
    }
}
