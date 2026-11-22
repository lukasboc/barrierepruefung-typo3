<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Controller;

use Lubomedia\Barrierepruefung\Api\Antwort;
use Lubomedia\Barrierepruefung\Api\Client;
use Lubomedia\Barrierepruefung\Api\Zugang;
use Lubomedia\Barrierepruefung\Modul\Anzeige;
use Lubomedia\Barrierepruefung\Modul\Rueckmeldung;
use Lubomedia\Barrierepruefung\Modul\Sitewahl;
use Lubomedia\Barrierepruefung\Verbindung\TresorFehler;
use Lubomedia\Barrierepruefung\Verbindung\Verbindung;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use Lubomedia\Barrierepruefung\Weg\Eingaben;
use Lubomedia\Barrierepruefung\Weg\Formulare;
use Lubomedia\Barrierepruefung\Weg\Schritte;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Untermodul „Weg zur Erklärung“: die Schritte aus `GET /sites/{id}/declaration/path`, je Schritt das Formular nach
 * den Angaben der API. Die Extension kennt kein Recht – Schritte, Fragen, Optionen und Gründe kommen vom Dienst,
 * hier werden sie gezeigt und zurückgeschickt. Ohne das Schreibrecht des Tokens steht statt jedes Formulars ein Satz
 * mit dem Weg zum Recht, nie ein ausgegrautes Formular. Jede Aktion antwortet mit 303 (PRG); Fehler je Feld und die
 * letzten Eingaben stehen genau einmal in der Rückmeldung.
 */
#[AsController]
final class WegController
{
    public const UNTERMODUL = 'barrierepruefung_weg';

    /** `reason` einer 403, die den Stand des Rechts ändert. */
    private const RECHT_GRUENDE = ['recht_fehlt', 'recht_erloschen', 'konto_geschlossen'];

    public function __construct(
        private readonly Modulrahmen $rahmen,
        private readonly Sitewahl $sitewahl,
        private readonly Rueckmeldung $rueckmeldung,
        private readonly Verbindungen $verbindungen,
        private readonly Client $client,
        private readonly Anzeige $anzeige,
        private readonly Schritte $schritte,
        private readonly Formulare $formulare,
        private readonly UriBuilder $adressen,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $vorlage = $this->rahmen->rahmen($request, self::UNTERMODUL);
        $site = $this->sitewahl->gewaehlt($request, $this->rahmen->benutzer());
        $rueckmeldung = $this->rueckmeldung->nehmen();
        $vorlage->assign('rueckmeldung', $rueckmeldung);
        if ($site === null) {
            return $vorlage->assign('zustand', 'keineSite')->renderResponse('Weg/Index');
        }
        $kennung = $site->getIdentifier();
        $vorlage->assignMultiple([
            'uebersichtUri' => (string)$this->adressen->buildUriFromRoute(UebersichtController::UNTERMODUL, ['site' => $kennung]),
            'wegUri' => (string)$this->adressen->buildUriFromRoute(self::UNTERMODUL, ['site' => $kennung]),
        ]);

        $verbindung = $this->verbindungen->fuerSite($kennung);
        if ($verbindung === null) {
            return $vorlage->assign('zustand', 'nichtVerbunden')->renderResponse('Weg/Index');
        }
        try {
            $zugang = $this->verbindungen->zugang($verbindung);
        } catch (TresorFehler) {
            return $vorlage->assign('zustand', 'neuVerbinden')->renderResponse('Weg/Index');
        }

        // Das Recht frisch vom Dienst: ein inzwischen erteiltes zeigt die Formulare, ein entzogenes nimmt sie weg.
        $verbindung = $this->rechtAuffrischen($zugang, $verbindung);

        $weg = $this->client->get($zugang, 'sites/' . rawurlencode($verbindung->siteId) . '/declaration/path');
        if (!$weg->ok()) {
            $fehler = $this->anzeige->fehler($weg);
            // 404 ohne eigenen Grund: diesen Endpunkt kennt der Dienst (noch) nicht, etwa eine ältere eigene Instanz.
            if ($weg->status === 404 && $weg->problem?->reason === null) {
                $fehler = ['detail' => '', 'hinweis' => $this->anzeige->t('weg.nicht_unterstuetzt')];
            }

            return $vorlage->assignMultiple(['zustand' => 'fehler', 'fehler' => $fehler])->renderResponse('Weg/Index');
        }
        $daten = is_array($weg->daten['data'] ?? null) ? $weg->daten['data'] : [];
        $schritte = $this->schritte->liste($daten['steps'] ?? null);
        $lauf = is_array($daten['scan'] ?? null) && is_string($daten['scan']['id'] ?? null) && $daten['scan']['id'] !== '' ? $daten['scan'] : null;
        $lauf = $lauf === null ? null : [
            'id' => (string)$lauf['id'],
            'bericht' => $this->anzeige->adresse($lauf['report_url'] ?? null),
            'befunde' => $this->anzeige->adresse($lauf['findings_url'] ?? null),
        ];
        $vorlage->assignMultiple(['recht' => $this->recht($verbindung), 'lauf' => $lauf]);

        $schritt = $lauf !== null ? Schritte::finden($schritte, $request->getQueryParams()['schritt'] ?? null) : null;
        if ($schritt !== null) {
            return $this->schritt($vorlage, $zugang, $verbindung, $schritt, $lauf['id'], $rueckmeldung);
        }

        $naechster = Schritte::finden($schritte, $daten['next'] ?? null);
        $veroeffentlicht = is_array($daten['published'] ?? null) ? $daten['published'] : null;
        $am = $this->anzeige->zeitpunkt($veroeffentlicht['published_at'] ?? null);
        $dokument = is_array($daten['document'] ?? null) ? $daten['document'] : [];

        return $vorlage->assignMultiple([
            'zustand' => $lauf === null ? 'keinLauf' : 'uebersicht',
            'schritte' => $schritte,
            'naechster' => $naechster,
            'erledigt' => $this->anzeige->zahl(is_int($daten['done'] ?? null) ? $daten['done'] : 0),
            'gesamt' => $this->anzeige->zahl(is_int($daten['total'] ?? null) ? $daten['total'] : count($schritte)),
            'dokument' => [
                'titel' => is_string($dokument['title'] ?? null) ? $dokument['title'] : '',
                'erklaerung' => is_string($dokument['explanation'] ?? null) ? $dokument['explanation'] : '',
            ],
            'veroeffentlicht' => $veroeffentlicht === null ? null : [
                'fassung' => is_int($veroeffentlicht['version'] ?? null) ? $veroeffentlicht['version'] : null,
                'am' => $am !== null ? $this->anzeige->datum($am) : null,
                'veraltet' => ($veroeffentlicht['outdated'] ?? false) === true,
            ],
        ])->renderResponse('Weg/Index');
    }

    public function pruefschritte(ServerRequestInterface $request): ResponseInterface
    {
        return $this->speichern($request, 'pruefschritte', function (Zugang $zugang, array $rumpf): array {
            $eingabe = Eingaben::pruefschritte($rumpf);
            $lauf = is_string($rumpf['scan'] ?? null) && preg_match('/^[0-9A-Za-z]{10,40}$/', $rumpf['scan']) === 1 ? $rumpf['scan'] : '';
            if ($lauf === '') {
                return ['meldung' => 'weg.pruefschritte.ohne_lauf', 'werte' => $eingabe['werte']];
            }
            if ($eingabe['checks'] === []) {
                return ['meldung' => 'weg.pruefschritte.unveraendert', 'werte' => []];
            }

            return [
                'antwort' => $this->client->senden($zugang, 'PUT', 'scans/' . rawurlencode($lauf) . '/manual-checks', [
                    'actor_name' => $this->handelnde(),
                    'checks' => $eingabe['checks'],
                ]),
                'werte' => $eingabe['werte'],
                'stapel' => $eingabe['checks'],
            ];
        });
    }

    public function stelle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->speichern($request, 'verantwortliche_stelle', function (Zugang $zugang, array $rumpf): array {
            $eingabe = Eingaben::stelle($rumpf);

            return [
                'antwort' => $this->client->senden($zugang, 'PUT', 'sites/' . rawurlencode($zugang->siteId) . '/declaring-party', $eingabe['rumpf'] + ['actor_name' => $this->handelnde()]),
                'werte' => $eingabe['werte'],
            ];
        });
    }

    public function betroffenheit(ServerRequestInterface $request): ResponseInterface
    {
        return $this->speichern($request, 'betroffenheit', function (Zugang $zugang, array $rumpf): array {
            $eingabe = Eingaben::betroffenheit($rumpf);

            return [
                'antwort' => $this->client->senden($zugang, 'PUT', 'sites/' . rawurlencode($zugang->siteId) . '/applicability', $eingabe['rumpf'] + ['actor_name' => $this->handelnde()]),
                'werte' => $eingabe['werte'],
            ];
        });
    }

    public function angaben(ServerRequestInterface $request): ResponseInterface
    {
        return $this->speichern($request, 'pflichtangaben', function (Zugang $zugang, array $rumpf): array {
            // Welche Felder es gibt und was in den nicht gezeigten steht, weiß nur der Entwurf.
            $entwurf = $this->client->get($zugang, 'sites/' . rawurlencode($zugang->siteId) . '/declaration/draft');
            if (!$entwurf->ok()) {
                return ['antwort' => $entwurf, 'werte' => Eingaben::angaben($rumpf, [])['werte']];
            }
            $felder = [];
            foreach (is_array($entwurf->daten['data']['fields'] ?? null) ? $entwurf->daten['data']['fields'] : [] as $feld) {
                if (is_array($feld)) {
                    $felder[] = $feld;
                }
            }
            $eingabe = Eingaben::angaben($rumpf, $felder);

            return [
                'antwort' => $this->client->senden($zugang, 'PUT', 'sites/' . rawurlencode($zugang->siteId) . '/declaration/details', $eingabe['rumpf'] + ['actor_name' => $this->handelnde()]),
                'werte' => $eingabe['werte'],
            ];
        });
    }

    /**
     * Ein Schritt: Titel, Zustand, Gründe, darunter das Formular (oder der Satz ohne Recht) und, solange er offen
     * ist, der Weg in den Dienst – der kann einem bekannten Schritt einen Grund geben, für den es hier kein Feld gibt.
     *
     * @param array{schluessel: string, titel: string, zustand: string, wort: string, abzeichen: string, offen: bool, gruende: list<string>, webUrl: string|null, bekannt: bool, formular: bool} $schritt
     * @param array<string, mixed>|null $rueckmeldung
     */
    private function schritt(ModuleTemplate $vorlage, Zugang $zugang, Verbindung $verbindung, array $schritt, string $lauf, ?array $rueckmeldung): ResponseInterface
    {
        $felder = is_array($rueckmeldung['felder'] ?? null) ? $rueckmeldung['felder'] : [];
        $werte = is_array($rueckmeldung['werte'] ?? null) ? $rueckmeldung['werte'] : [];
        $ansicht = $schritt['bekannt'] ? $schritt['schluessel'] : 'unbekannt';
        $formular = null;
        $abruffehler = null;
        $fehlerliste = [];

        if ($schritt['formular'] && $verbindung->darfVorbereiten()) {
            $site = rawurlencode($verbindung->siteId);
            $antwort = match ($ansicht) {
                'pruefschritte' => $this->client->get($zugang, 'scans/' . rawurlencode($lauf) . '/manual-checks'),
                'verantwortliche_stelle' => $this->client->get($zugang, 'sites/' . $site . '/declaring-party'),
                'betroffenheit' => $this->client->get($zugang, 'sites/' . $site . '/applicability'),
                default => $this->client->get($zugang, 'sites/' . $site . '/declaration/draft'),
            };
            if (!$antwort->ok()) {
                $abruffehler = $this->anzeige->fehler($antwort);
            } else {
                $daten = is_array($antwort->daten['data'] ?? null) ? $antwort->daten['data'] : [];
                $formular = match ($ansicht) {
                    'pruefschritte' => $this->formulare->pruefschritte($antwort->daten, $felder, $werte),
                    'verantwortliche_stelle' => $this->formulare->stelle($daten, $felder, $werte),
                    'betroffenheit' => $this->formulare->betroffenheit($daten, $felder, $werte),
                    default => $this->formulare->angaben($daten, $felder, $werte),
                };
                $fehlerliste = Formulare::fehlerliste($felder, $formular['gerendert']);
            }
        }

        return $vorlage->assignMultiple([
            'zustand' => 'schritt',
            'schritt' => $schritt,
            'ansicht' => $ansicht,
            'formular' => $formular,
            'abruffehler' => $abruffehler,
            'fehlerliste' => $fehlerliste,
        ])->renderResponse('Weg/Schritt');
    }

    /**
     * Der gemeinsame Ablauf jeder Aktion: Site prüfen, Recht prüfen, senden, Ergebnis merken, 303.
     *
     * @param \Closure(Zugang, array<mixed>): array{antwort?: Antwort, meldung?: string, werte: array<string, string>, stapel?: list<array{id: int, answer: string, note: string}>} $senden
     */
    private function speichern(ServerRequestInterface $request, string $schritt, \Closure $senden): ResponseInterface
    {
        $rumpf = $request->getParsedBody();
        $rumpf = is_array($rumpf) ? $rumpf : [];
        $site = $this->sitewahl->erlaubt($rumpf['site'] ?? null, $this->rahmen->benutzer());
        if ($site === null) {
            return new HtmlResponse(htmlspecialchars($this->anzeige->t('verboten')), 403);
        }
        $verbindung = $this->verbindungen->fuerSite($site->getIdentifier());
        if ($verbindung === null) {
            $this->rueckmeldung->merken('fehler', $this->anzeige->t('fehler.nicht_verbunden'));

            return $this->zurueck($site);
        }
        try {
            $zugang = $this->verbindungen->zugang($verbindung);
        } catch (TresorFehler) {
            $this->rueckmeldung->merken('fehler', $this->anzeige->t('fehler.neu_verbinden'));

            return $this->zurueck($site);
        }
        // Wer das Formular nicht sehen durfte, schickt auch nichts ab.
        if (!$verbindung->darfVorbereiten()) {
            $this->rueckmeldung->merken('fehler', $this->anzeige->t('weg.ohne_recht.' . $this->recht($verbindung)['stand']));

            return $this->zurueck($site, $schritt);
        }

        $ergebnis = $senden($zugang, $rumpf);
        $antwort = $ergebnis['antwort'] ?? null;
        if ($antwort === null) {
            $this->rueckmeldung->merken('info', $this->anzeige->t($ergebnis['meldung'] ?? 'weg.pruefschritte.unveraendert'), [], $ergebnis['werte']);

            return $this->zurueck($site, $schritt);
        }
        if ($antwort->ok()) {
            return $this->weiter($zugang, $site, $schritt);
        }

        $problem = $antwort->problem;
        $link = $this->anzeige->adresse($problem?->url);
        $link = $link !== null ? ['uri' => $link, 'text' => $this->anzeige->t('weg.im_dienst_beheben')] : null;
        if ($problem !== null && $antwort->status === 403 && in_array($problem->reason, self::RECHT_GRUENDE, true)) {
            // Der Dienst weiß es besser als der gespeicherte Stand: ab jetzt steht der Satz statt des Formulars.
            $this->verbindungen->faehigkeitenAktualisieren($site->getIdentifier(), ['publish' => ['state' => $problem->reason]] + $verbindung->faehigkeiten);
            $this->rueckmeldung->merken('fehler', $problem->detail, [], [], $link);

            return $this->zurueck($site, $schritt);
        }

        $felder = Eingaben::fehler($problem->errors ?? [], $ergebnis['stapel'] ?? []);
        if ($felder !== []) {
            $text = $this->anzeige->t('weg.fehler.felder');
        } else {
            $fehler = $this->anzeige->fehler($antwort);
            $text = trim($this->anzeige->t('weg.fehler.nicht_gespeichert') . ' ' . $fehler['detail'] . ' ' . $fehler['hinweis']);
        }
        $this->rueckmeldung->merken('fehler', $text, $felder, $ergebnis['werte'], $link);

        return $this->zurueck($site, $schritt);
    }

    /**
     * Nach dem Speichern zum nächsten offenen Schritt, den der Dienst nennt – das will man als Nächstes tun. Ist es
     * derselbe oder keiner, zurück zur Übersicht.
     */
    private function weiter(Zugang $zugang, Site $site, string $schritt): ResponseInterface
    {
        $weg = $this->client->get($zugang, 'sites/' . rawurlencode($zugang->siteId) . '/declaration/path');
        $daten = $weg->ok() && is_array($weg->daten['data'] ?? null) ? $weg->daten['data'] : [];
        $naechster = Schritte::finden($this->schritte->liste($daten['steps'] ?? null), $daten['next'] ?? null);
        if ($naechster !== null && $naechster['schluessel'] !== $schritt) {
            $this->rueckmeldung->merken('erfolg', $this->anzeige->t('weg.gespeichert.weiter', $naechster['titel']));

            return $this->zurueck($site, $naechster['schluessel']);
        }
        $this->rueckmeldung->merken('erfolg', $this->anzeige->t('weg.gespeichert'));

        return $this->zurueck($site);
    }

    private function rechtAuffrischen(Zugang $zugang, Verbindung $verbindung): Verbindung
    {
        $website = $this->client->get($zugang, 'sites/' . rawurlencode($verbindung->siteId));
        $token = $website->ok() ? ($website->daten['data']['token'] ?? null) : null;
        if (!is_array($token)) {
            return $verbindung;
        }
        /** @var array<string, mixed> $token */
        $this->verbindungen->faehigkeitenAktualisieren($verbindung->siteIdentifier, $token);

        return $this->verbindungen->fuerSite($verbindung->siteIdentifier) ?? $verbindung;
    }

    /**
     * @return array{darf: bool, stand: string}
     */
    private function recht(Verbindung $verbindung): array
    {
        if ($verbindung->darfVorbereiten()) {
            return ['darf' => true, 'stand' => 'erlaubt'];
        }
        $stand = $verbindung->freigaberecht();

        // „erlaubt“ ohne `declaration:prepare` (ein Token aus der Zeit davor) ist für dieses Modul ein fehlendes Recht.
        return ['darf' => false, 'stand' => in_array($stand, self::RECHT_GRUENDE, true) ? $stand : 'recht_fehlt'];
    }

    /**
     * Wie der Dienst die handelnde Person nennt: ein Beleg, keine Identität (die Verantwortung trägt die Admin, die
     * dem Token das Recht erteilt hat).
     */
    private function handelnde(): string
    {
        $person = $this->rahmen->benutzer()->user ?? [];
        $name = trim(is_string($person['realName'] ?? null) ? $person['realName'] : '');

        return $name !== '' ? $name : (is_string($person['username'] ?? null) ? $person['username'] : '');
    }

    private function zurueck(Site $site, ?string $schritt = null): ResponseInterface
    {
        $parameter = ['site' => $site->getIdentifier()] + ($schritt !== null ? ['schritt' => $schritt] : []);

        return new RedirectResponse((string)$this->adressen->buildUriFromRoute(self::UNTERMODUL, $parameter), 303);
    }
}
