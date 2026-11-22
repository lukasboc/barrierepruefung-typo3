<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Controller;

use Lubomedia\Barrierepruefung\Api\Zugang;
use Lubomedia\Barrierepruefung\Modul\Anzeige;
use Lubomedia\Barrierepruefung\Modul\Sitewahl;
use Lubomedia\Barrierepruefung\Verbindung\TresorFehler;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Uid\Uuid;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;

/**
 * Untermodul „Befunde“: die offenen, verbindlichen Befunde der letzten Prüfung je Regel, dazu die Fundstellen einer Regel.
 * Dieselbe Auswahl wie das WordPress-Plugin (`binding_only=1&state=open`); die Antwort wird zusätzlich selbst gefiltert,
 * damit ein Hinweis oder ein bewerteter Befund nie in der Arbeitsliste landet. Nur lesen: kein Zustand, kein Cache,
 * jedes Blättern und Öffnen ist ein gewöhnlicher Link (GET) ohne Skript.
 */
#[AsController]
final class BefundeController
{
    public const UNTERMODUL = 'barrierepruefung_befunde';
    private const SCHRITT = 20;
    /** Höchste Seite, die das Modul abruft: schützt vor absurden Offsets, deckt 200 000 Fundstellen ab. */
    private const MAX_SEITE = 10000;
    private const ABZEICHEN = ['critical' => 'danger', 'serious' => 'warning', 'moderate' => 'info', 'minor' => 'default'];

    public function __construct(
        private readonly Modulrahmen $rahmen,
        private readonly Sitewahl $sitewahl,
        private readonly Verbindungen $verbindungen,
        private readonly \Lubomedia\Barrierepruefung\Api\Client $client,
        private readonly Anzeige $anzeige,
        private readonly UriBuilder $adressen,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $vorlage = $this->rahmen->rahmen($request, self::UNTERMODUL);
        $site = $this->sitewahl->gewaehlt($request, $this->rahmen->benutzer());
        if ($site === null) {
            return $vorlage->assign('zustand', 'keineSite')->renderResponse('Befunde/Index');
        }
        $kennung = $site->getIdentifier();
        $vorlage->assign('uebersichtUri', $this->adresse(UebersichtController::UNTERMODUL, $kennung));

        $verbindung = $this->verbindungen->fuerSite($kennung);
        if ($verbindung === null) {
            return $vorlage->assign('zustand', 'nichtVerbunden')->renderResponse('Befunde/Index');
        }
        try {
            $zugang = $this->verbindungen->zugang($verbindung);
        } catch (TresorFehler) {
            return $vorlage->assign('zustand', 'neuVerbinden')->renderResponse('Befunde/Index');
        }

        $antwort = $this->client->get($zugang, 'sites/' . rawurlencode($verbindung->siteId));
        if (!$antwort->ok()) {
            return $vorlage->assignMultiple(['zustand' => 'fehler', 'fehler' => $this->anzeige->fehler($antwort)])->renderResponse('Befunde/Index');
        }
        $daten = is_array($antwort->daten['data'] ?? null) ? $antwort->daten['data'] : [];
        $letzte = is_array($daten['latest_scan'] ?? null) ? $daten['latest_scan'] : null;
        $lauf = is_string($letzte['id'] ?? null) ? $letzte['id'] : '';
        if ($lauf === '') {
            $laufend = is_array($daten['running_scan'] ?? null) && is_string($daten['running_scan']['id'] ?? null) && $daten['running_scan']['id'] !== '';

            return $vorlage->assignMultiple([
                'zustand' => $laufend ? 'laeuft' : 'keinLauf',
                'idempotencyKey' => Uuid::v4()->toRfc4122(),
            ])->renderResponse('Befunde/Index');
        }

        $bericht = $this->anzeige->adresse($letzte['report_url'] ?? null);
        $am = $this->anzeige->zeitpunkt($letzte['finished_at'] ?? null);
        $vorlage->assignMultiple(['bericht' => $bericht, 'am' => $am !== null ? $this->anzeige->datum($am) : $this->anzeige->t('befunde.datum_unbekannt')]);

        $liste = $this->client->get($zugang, 'scans/' . rawurlencode($lauf) . '/findings?binding_only=1&state=open');
        if (!$liste->ok()) {
            return $vorlage->assignMultiple(['zustand' => 'fehler', 'fehler' => $this->anzeige->fehler($liste)])->renderResponse('Befunde/Index');
        }
        $regeln = $this->regeln($liste->daten['data'] ?? null, $kennung);

        $regel = $request->getQueryParams()['regel'] ?? null;
        if (is_string($regel) && $regel !== '') {
            return $this->regelansicht($vorlage, $request, $zugang, $kennung, $lauf, $regel, $regeln, $bericht);
        }

        return $vorlage->assignMultiple(['zustand' => $regeln === [] ? 'sauber' : 'liste', 'regeln' => $regeln])->renderResponse('Befunde/Index');
    }

    /**
     * @param array<string, array<string, mixed>> $regeln
     */
    private function regelansicht(ModuleTemplate $vorlage, ServerRequestInterface $request, Zugang $zugang, string $kennung, string $lauf, string $regel, array $regeln, ?string $bericht): ResponseInterface
    {
        $vorlage->assign('listeUri', $this->adresse(self::UNTERMODUL, $kennung));
        // Nur, was in der Liste der offenen verbindlichen Befunde steht, geht an die API.
        if (!isset($regeln[$regel])) {
            return $vorlage->assignMultiple(['zustand' => 'regelFehlt', 'regelName' => $regel])->renderResponse('Befunde/Regel');
        }

        $seite = max(1, min(self::MAX_SEITE, (int)($request->getQueryParams()['seite'] ?? 1)));
        $versatz = ($seite - 1) * self::SCHRITT;
        $antwort = $this->client->get($zugang, 'scans/' . rawurlencode($lauf) . '/findings/' . rawurlencode($regel) . '?limit=' . self::SCHRITT . '&offset=' . $versatz);
        if (!$antwort->ok()) {
            return $vorlage->assignMultiple(['zustand' => 'fehler', 'regel' => $regeln[$regel], 'fehler' => $this->anzeige->fehler($antwort)])->renderResponse('Befunde/Regel');
        }

        $meta = is_array($antwort->daten['meta'] ?? null) ? $antwort->daten['meta'] : [];
        $stellen = [];
        foreach (is_array($antwort->daten['data'] ?? null) ? $antwort->daten['data'] : [] as $eine) {
            if (is_array($eine)) {
                $stellen[] = $this->stelle($eine);
            }
        }
        $gesamt = is_int($meta['total'] ?? null) ? $meta['total'] : count($stellen);
        // Die Zählung folgt der Antwort des Dienstes (offset), nicht dem Wunsch aus der Adresse.
        $ab = is_int($meta['offset'] ?? null) ? $meta['offset'] : $versatz;
        $schritt = is_int($meta['limit'] ?? null) && $meta['limit'] > 0 ? $meta['limit'] : self::SCHRITT;
        $aktuelle = intdiv($ab, $schritt) + 1;
        $weitere = $ab + count($stellen) < $gesamt;
        $dienst = $this->anzeige->adresse($meta['report_url'] ?? null) ?? $bericht;

        return $vorlage->assignMultiple([
            'zustand' => $stellen === [] ? 'keineStellen' : 'stellen',
            'regel' => $regeln[$regel],
            'stellen' => $stellen,
            'von' => $this->anzeige->zahl($ab + 1),
            'bis' => $this->anzeige->zahl($ab + count($stellen)),
            'gesamt' => $this->anzeige->zahl($gesamt),
            'bericht' => $dienst,
            'weiterUri' => $weitere ? $this->adresse(self::UNTERMODUL, $kennung, ['regel' => $regel, 'seite' => (string)($aktuelle + 1)]) : null,
            'frueherUri' => $aktuelle > 1 ? $this->adresse(self::UNTERMODUL, $kennung, ['regel' => $regel] + ($aktuelle > 2 ? ['seite' => (string)($aktuelle - 1)] : [])) : null,
        ])->renderResponse('Befunde/Regel');
    }

    /**
     * Die offenen, verbindlichen Regeln, nach Regelschlüssel; Reihenfolge wie vom Dienst geliefert.
     *
     * @return array<string, array<string, mixed>>
     */
    private function regeln(mixed $daten, string $kennung): array
    {
        $regeln = [];
        foreach (is_array($daten) ? $daten : [] as $eine) {
            if (!is_array($eine) || !is_string($eine['rule'] ?? null) || $eine['rule'] === '') {
                continue;
            }
            // Ein Hinweis ist keine Pflicht, ein bewerteter Befund keine Arbeit mehr.
            if (($eine['advisory'] ?? false) === true || ($eine['state'] ?? 'open') !== 'open') {
                continue;
            }
            $schluessel = $eine['rule'];
            $grad = is_string($eine['severity'] ?? null) ? $eine['severity'] : '';
            $kriterien = array_values(array_filter(is_array($eine['success_criteria'] ?? null) ? $eine['success_criteria'] : [], is_string(...)));
            $regeln[$schluessel] = [
                'schluessel' => $schluessel,
                'titel' => is_string($eine['title'] ?? null) && $eine['title'] !== '' ? $eine['title'] : $schluessel,
                'hinweis' => is_string($eine['remediation'] ?? null) && trim($eine['remediation']) !== '' ? $eine['remediation'] : null,
                'grad' => $this->anzeige->wort('schweregrad.', $grad),
                'abzeichen' => self::ABZEICHEN[$grad] ?? 'default',
                'kriterien' => implode(', ', $kriterien),
                'vorkommen' => $this->anzeige->zahl(is_int($eine['occurrences'] ?? null) ? $eine['occurrences'] : 0),
                'vorkommenZahl' => is_int($eine['occurrences'] ?? null) ? $eine['occurrences'] : 0,
                'seiten' => $this->anzeige->zahl(is_int($eine['pages'] ?? null) ? $eine['pages'] : 0),
                'seitenZahl' => is_int($eine['pages'] ?? null) ? $eine['pages'] : 0,
                'detailUri' => $this->adresse(self::UNTERMODUL, $kennung, ['regel' => $schluessel]),
            ];
        }

        return $regeln;
    }

    /**
     * @param array<mixed> $s
     * @return array<string, mixed>
     */
    private function stelle(array $s): array
    {
        $farben = [];
        foreach (is_array($s['colors'] ?? null) ? $s['colors'] : [] as $name => $wert) {
            if (is_string($wert) && $wert !== '') {
                $farben[] = $name . ': ' . $wert;
            }
        }
        $messungen = array_values(array_filter(is_array($s['measurements'] ?? null) ? $s['measurements'] : [], is_string(...)));

        return [
            'seite' => is_string($s['page_url'] ?? null) ? $s['page_url'] : '',
            // Nur http(s) wird zum Link; alles andere steht als Text.
            'seiteLink' => $this->anzeige->adresse($s['page_url'] ?? null),
            'selektor' => is_string($s['selector'] ?? null) && $s['selector'] !== '' ? $s['selector'] : null,
            'zustand' => is_string($s['ui_state'] ?? null) && $s['ui_state'] !== '' ? $s['ui_state'] : null,
            'darstellung' => is_string($s['viewport'] ?? null) && $s['viewport'] !== '' ? $s['viewport'] : null,
            'messung' => implode(' · ', $messungen),
            'farben' => implode(' · ', $farben),
            'begruendung' => is_string($s['summary'] ?? null) ? $s['summary'] : '',
            'ausschnitt' => is_string($s['html_snippet'] ?? null) && $s['html_snippet'] !== '' ? $s['html_snippet'] : null,
        ];
    }

    /**
     * @param array<string, string> $weitere
     */
    private function adresse(string $route, string $site, array $weitere = []): string
    {
        return (string)$this->adressen->buildUriFromRoute($route, ['site' => $site] + $weitere);
    }
}
