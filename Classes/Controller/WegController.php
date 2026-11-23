<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Controller;

use Lubomedia\Barrierepruefung\Api\Antwort;
use Lubomedia\Barrierepruefung\Api\Client;
use Lubomedia\Barrierepruefung\Api\Zugang;
use Lubomedia\Barrierepruefung\Erklaerung\Abruf;
use Lubomedia\Barrierepruefung\Erklaerung\Bereinigung;
use Lubomedia\Barrierepruefung\Erklaerung\Fragment;
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
 *
 * Am Ende des Wegs die Freigabe: Vorschau des Entwurfs, Aussage zur Konformität, Veröffentlichen. Das Formular trägt
 * die Kennung des gezeigten Entwurfs (`draft_etag`), die Nummer der Fassung (`expected_version`) und einen beim
 * Anzeigen erzeugten `Idempotency-Key`: ein inzwischen geänderter Entwurf wird nicht still veröffentlicht, und ein
 * Doppelklick ergibt keine zweite Fassung. Ohne `declaration:publish` gibt es weder Formular noch Knopf.
 */
#[AsController]
final class WegController
{
    public const UNTERMODUL = 'barrierepruefung_weg';

    /** Ein Idempotency-Key, wie `uuid()` ihn erzeugt. */
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

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
        private readonly Abruf $abruf,
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
        $vorlage->assignMultiple([
            'recht' => $this->recht($verbindung),
            'freigaberecht' => $this->freigaberecht($verbindung),
            'freigabeUri' => (string)$this->adressen->buildUriFromRoute(self::UNTERMODUL . '.freigabe', ['site' => $kennung]),
            'lauf' => $lauf,
        ]);

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
     * derselbe (etwa noch offene Prüfschritte), bleibt die Ansicht auf ihm; ist es keiner, zurück zur Übersicht.
     */
    private function weiter(Zugang $zugang, Site $site, string $schritt): ResponseInterface
    {
        $weg = $this->client->get($zugang, 'sites/' . rawurlencode($zugang->siteId) . '/declaration/path');
        $daten = $weg->ok() && is_array($weg->daten['data'] ?? null) ? $weg->daten['data'] : [];
        $naechster = Schritte::finden($this->schritte->liste($daten['steps'] ?? null), $daten['next'] ?? null);
        if ($naechster !== null && $naechster['schluessel'] === $schritt) {
            $this->rueckmeldung->merken('erfolg', $this->anzeige->t('weg.gespeichert.noch_offen'));

            return $this->zurueck($site, $schritt);
        }
        if ($naechster !== null) {
            $this->rueckmeldung->merken('erfolg', $this->anzeige->t('weg.gespeichert.weiter', $naechster['titel']));

            return $this->zurueck($site, $naechster['schluessel']);
        }
        $this->rueckmeldung->merken('erfolg', $this->anzeige->t('weg.gespeichert'));

        return $this->zurueck($site);
    }

    /**
     * Die Freigabe: Vorschau, Aussage zur Konformität und – mit dem Recht und nur, wenn kein Schritt mehr offen ist –
     * das Formular. Jede Anzeige erzeugt einen neuen `Idempotency-Key`; derselbe Schlüssel gilt nur für dieses eine
     * Formular, auch wenn es zweimal abgeschickt wird.
     */
    public function freigabe(ServerRequestInterface $request): ResponseInterface
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
        $verbindung = $this->rechtAuffrischen($zugang, $verbindung);
        $recht = $this->freigaberecht($verbindung);
        $vorlage->assign('freigaberecht', $recht);

        $entwurf = $this->client->get($zugang, 'sites/' . rawurlencode($verbindung->siteId) . '/declaration/draft');
        if (!$entwurf->ok()) {
            return $vorlage->assignMultiple(['zustand' => 'fehler', 'fehler' => $this->anzeige->fehler($entwurf)])->renderResponse('Weg/Freigabe');
        }
        $daten = is_array($entwurf->daten['data'] ?? null) ? $entwurf->daten['data'] : [];

        // Ob noch ein Schritt offen ist und welche Fassung gerade gilt, weiß der Weg.
        $weg = $this->client->get($zugang, 'sites/' . rawurlencode($verbindung->siteId) . '/declaration/path');
        $wegDaten = $weg->ok() && is_array($weg->daten['data'] ?? null) ? $weg->daten['data'] : [];
        $schritte = $this->schritte->liste($wegDaten['steps'] ?? null);
        $naechster = Schritte::finden($schritte, $wegDaten['next'] ?? null);
        $bereit = $weg->ok() && ($wegDaten['next'] ?? null) === null && $schritte !== [];
        $veroeffentlicht = is_array($wegDaten['published'] ?? null) ? $wegDaten['published'] : null;
        $am = $this->anzeige->zeitpunkt($veroeffentlicht['published_at'] ?? null);

        $felder = is_array($rueckmeldung['felder'] ?? null) ? $rueckmeldung['felder'] : [];
        $werte = is_array($rueckmeldung['werte'] ?? null) ? $rueckmeldung['werte'] : [];
        $formular = $this->formulare->freigabe($daten, $felder, $werte);
        $mitFormular = $recht['darf'] && $bereit && $formular['etag'] !== '' && $formular['fassung'] !== null;
        $dokument = is_array($daten['document'] ?? null) ? $daten['document'] : [];
        $sprache = is_string($daten['locale'] ?? null) && preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $daten['locale']) === 1 ? $daten['locale'] : 'de';

        return $vorlage->assignMultiple([
            'zustand' => 'freigabe',
            // Ab h3 unter der h2 des Moduls, und nie ungefiltert: das HTML kommt aus einem fremden System.
            'vorschau' => (new Bereinigung())->bereinigen(Fragment::ebenenVerschieben(is_string($daten['html'] ?? null) ? $daten['html'] : '', 3)),
            'sprache' => $sprache,
            'dokument' => is_string($dokument['title'] ?? null) ? $dokument['title'] : '',
            'formular' => $formular,
            'mitFormular' => $mitFormular,
            'schluessel' => $mitFormular ? self::schluesselFuer($werte, $formular, $kennung) : '',
            'bereit' => $bereit,
            'wegBekannt' => $weg->ok(),
            'naechster' => $naechster,
            'veroeffentlicht' => $veroeffentlicht === null ? null : [
                'fassung' => is_int($veroeffentlicht['version'] ?? null) ? $veroeffentlicht['version'] : null,
                'am' => $am !== null ? $this->anzeige->datum($am) : null,
                'veraltet' => ($veroeffentlicht['outdated'] ?? false) === true,
            ],
            'handelnde' => $this->handelnde(),
            'fehlerliste' => Formulare::fehlerliste($felder, $formular['gerendert']),
        ])->renderResponse('Weg/Freigabe');
    }

    public function freigeben(ServerRequestInterface $request): ResponseInterface
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
        // Wer den Knopf nicht sehen durfte, veröffentlicht auch nichts.
        if (!$verbindung->darfFreigeben()) {
            $this->rueckmeldung->merken('fehler', $this->anzeige->t('weg.ohne_recht.' . $this->freigaberecht($verbindung)['stand']));

            return $this->zurFreigabe($site);
        }

        $schluessel = is_string($rumpf['idempotency_key'] ?? null) ? $rumpf['idempotency_key'] : '';
        $kennung = is_string($rumpf['draft_etag'] ?? null) ? trim($rumpf['draft_etag']) : '';
        $fassung = is_string($rumpf['expected_version'] ?? null) && preg_match('/^[1-9]\d{0,8}$/', $rumpf['expected_version']) === 1 ? (int)$rumpf['expected_version'] : 0;
        // Ohne den Schlüssel aus der Anzeige kein Versuch: ein hier erzeugter wäre bei jedem Absenden ein anderer.
        if (preg_match(self::UUID, $schluessel) !== 1 || $kennung === '' || strlen($kennung) > 64 || $fassung === 0) {
            $this->rueckmeldung->merken('fehler', $this->anzeige->t('weg.freigabe.formular_ungueltig'));

            return $this->zurFreigabe($site);
        }

        $ermittelt = is_string($rumpf['ermittelt'] ?? null) ? $rumpf['ermittelt'] : '';
        $wahl = is_string($rumpf['conformance_status'] ?? null) ? $rumpf['conformance_status'] : $ermittelt;
        $begruendung = is_string($rumpf['override_reason'] ?? null) ? $rumpf['override_reason'] : '';
        $werte = ['conformance_status' => $wahl, 'override_reason' => $begruendung];
        $abweichend = $wahl !== '' && $wahl !== $ermittelt;
        if ($abweichend && trim($begruendung) === '') {
            $this->rueckmeldung->merken('fehler', $this->anzeige->t('weg.freigabe.fehler.felder'), ['override_reason' => $this->anzeige->t('weg.freigabe.begruendung.fehlt')], $werte);

            return $this->zurFreigabe($site);
        }

        $antwort = $this->client->senden(
            $zugang,
            'POST',
            'sites/' . rawurlencode($verbindung->siteId) . '/declaration/publish',
            ['draft_etag' => $kennung, 'expected_version' => $fassung, 'actor_name' => $this->handelnde()]
                + ($abweichend ? ['conformance_status' => $wahl, 'override_reason' => trim($begruendung)] : []),
            ['Idempotency-Key' => $schluessel],
        );

        if ($antwort->ok()) {
            $neu = is_array($antwort->daten['data'] ?? null) ? $antwort->daten['data'] : [];
            $wiederholt = strtolower($antwort->kopf['idempotent-replayed'][0] ?? '') === 'true';

            return $this->veroeffentlicht($verbindung, $site, is_int($neu['version'] ?? null) ? $neu['version'] : $fassung, $neu['public_url'] ?? null, $wiederholt ? 'weg.freigabe.wiederholt' : null);
        }

        // Keine Antwort, Zeitüberlauf, Fehler des Dienstes: die Fassung kann trotzdem angelegt sein. Der Weg sagt, ob es
        // sie gibt; sonst ist der Ausgang offen, und das Formular kommt mit DEMSELBEN Schlüssel wieder – ein erneutes
        // Absenden ergibt beim Dienst höchstens eine Fassung.
        if ($antwort->status === 0 || $antwort->status >= 500) {
            $weg = $this->client->get($zugang, 'sites/' . rawurlencode($verbindung->siteId) . '/declaration/path');
            $gilt = $weg->ok() && is_array($weg->daten['data']['published'] ?? null) ? $weg->daten['data']['published'] : [];
            if (($gilt['version'] ?? null) === $fassung) {
                return $this->veroeffentlicht($verbindung, $site, $fassung, $gilt['public_url'] ?? null, 'weg.freigabe.nachtraeglich');
            }
            $fehler = $this->anzeige->fehler($antwort);
            $this->rueckmeldung->merken(
                'fehler',
                trim($this->anzeige->t('weg.freigabe.unklar', (string)$fassung) . ' ' . $fehler['detail']),
                [],
                // Die Rückmeldung gilt für die ganze Sitzung; der Schlüssel gehört zu genau dieser Site.
                $werte + ['idempotency_key' => $schluessel, 'draft_etag' => $kennung, 'expected_version' => (string)$fassung, 'site' => $site->getIdentifier()],
            );

            return $this->zurFreigabe($site);
        }

        $problem = $antwort->problem;
        $link = $this->anzeige->adresse($problem?->url);
        $link = $link !== null ? ['uri' => $link, 'text' => $this->anzeige->t('weg.im_dienst_beheben')] : null;
        if ($problem !== null && $antwort->status === 403 && in_array($problem->reason, self::RECHT_GRUENDE, true)) {
            $this->verbindungen->faehigkeitenAktualisieren($site->getIdentifier(), ['publish' => ['state' => $problem->reason]] + $verbindung->faehigkeiten);
            $this->rueckmeldung->merken('fehler', $problem->detail, [], [], $link);

            return $this->zurFreigabe($site);
        }
        if ($problem?->reason === 'entwurf_geaendert') {
            // Zurück zur Freigabe: sie holt den neuen Entwurf, mit neuer Kennung und neuem Schlüssel.
            $this->rueckmeldung->merken('fehler', $this->anzeige->t('weg.freigabe.geaendert'));

            return $this->zurFreigabe($site);
        }
        if ($problem?->reason === 'nicht_bereit') {
            $this->rueckmeldung->merken('fehler', $problem->detail, [], [], null, $this->blocker($zugang, $site, $problem->blockers));

            return $this->zurueck($site);
        }

        $felder = Eingaben::fehler($problem->errors ?? []);
        $fehler = $this->anzeige->fehler($antwort);
        $text = trim($this->anzeige->t('weg.freigabe.nicht_veroeffentlicht') . ' ' . $fehler['detail'] . ' ' . $fehler['hinweis']);
        $this->rueckmeldung->merken('fehler', $text, $felder, $werte, $link);

        return $this->zurFreigabe($site);
    }

    /**
     * Die Fassung steht im Dienst: die Erklärung gleich neu holen (Seiten mit dem Element zeigen sie sofort, nicht erst
     * nach Ablauf des Caches), Rückmeldung mit der Nummer, zur Übersicht.
     */
    private function veroeffentlicht(Verbindung $verbindung, Site $site, int $fassung, mixed $oeffentlich, ?string $zusatz): ResponseInterface
    {
        $this->abruf->auffrischen($verbindung);
        $text = $this->anzeige->t('weg.freigabe.erfolg', (string)$fassung) . ($zusatz !== null ? ' ' . $this->anzeige->t($zusatz) : '');
        $adresse = $this->anzeige->adresse($oeffentlich);
        $this->rueckmeldung->merken('erfolg', $text, [], [], $adresse !== null ? ['uri' => $adresse, 'text' => $this->anzeige->t('erklaerung.oeffnen')] : null);

        return $this->zurueck($site);
    }

    /**
     * Der Schlüssel der Anzeige: derselbe wie beim letzten Versuch, wenn dessen Ausgang offen blieb und Site, Entwurf
     * und Fassung noch dieselben sind – sonst ein neuer. Ein neuer Entwurf ist eine neue Entscheidung und bekommt einen
     * neuen; eine andere Site nie den Schlüssel einer anderen (die Rückmeldung gilt für die ganze Sitzung).
     *
     * @param array<string, string> $werte   aus der Rückmeldung
     * @param array<string, mixed>  $formular
     */
    private static function schluesselFuer(array $werte, array $formular, string $site): string
    {
        $alt = $werte['idempotency_key'] ?? '';
        if (preg_match(self::UUID, $alt) === 1
            && ($werte['site'] ?? null) === $site
            && ($werte['draft_etag'] ?? null) === $formular['etag']
            && ($werte['expected_version'] ?? null) === (string)$formular['fassung']
        ) {
            return $alt;
        }

        return self::uuid();
    }

    /**
     * Die Blocker einer abgelehnten Freigabe, jeder mit dem Schritt, unter dem der Weg denselben Grund führt. Welcher
     * Grund zu welchem Schritt gehört, sagt der Dienst (`steps[].reasons[].key`); ein Grund ohne Schritt steht ohne Link.
     *
     * @param array<mixed> $blocker `blockers` der 422: Liste aus `{key, text}` (oder Schlüssel ⇒ Text)
     * @return list<array{text: string, uri: string|null}>
     */
    private function blocker(Zugang $zugang, Site $site, array $blocker): array
    {
        $weg = $this->client->get($zugang, 'sites/' . rawurlencode($zugang->siteId) . '/declaration/path');
        $schrittJeGrund = [];
        foreach ($weg->ok() && is_array($weg->daten['data']['steps'] ?? null) ? $weg->daten['data']['steps'] : [] as $schritt) {
            if (!is_array($schritt) || !is_string($schritt['key'] ?? null) || $schritt['key'] === '') {
                continue;
            }
            foreach (is_array($schritt['reasons'] ?? null) ? $schritt['reasons'] : [] as $grund) {
                if (is_array($grund) && is_string($grund['key'] ?? null)) {
                    $schrittJeGrund[$grund['key']] ??= $schritt['key'];
                }
            }
        }
        $liste = [];
        foreach ($blocker as $schluessel => $eintrag) {
            $grund = is_array($eintrag) && is_string($eintrag['key'] ?? null) ? $eintrag['key'] : (is_string($schluessel) ? $schluessel : '');
            $text = is_array($eintrag) ? (is_string($eintrag['text'] ?? null) ? $eintrag['text'] : '') : (is_string($eintrag) ? $eintrag : '');
            if ($text === '') {
                continue;
            }
            $ziel = $schrittJeGrund[$grund] ?? null;
            $liste[] = [
                'text' => $text,
                'uri' => $ziel === null ? null : (string)$this->adressen->buildUriFromRoute(self::UNTERMODUL, ['site' => $site->getIdentifier(), 'schritt' => $ziel]),
            ];
        }

        return $liste;
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
     * Das Recht zu veröffentlichen: `declaration:publish` und der Stand „erlaubt“. Fehlt das eine oder das andere,
     * steht der Satz mit dem Weg zum Recht statt des Formulars.
     *
     * @return array{darf: bool, stand: string}
     */
    private function freigaberecht(Verbindung $verbindung): array
    {
        if ($verbindung->darfFreigeben()) {
            return ['darf' => true, 'stand' => 'erlaubt'];
        }
        $stand = $verbindung->freigaberecht();

        return ['darf' => false, 'stand' => in_array($stand, self::RECHT_GRUENDE, true) ? $stand : 'recht_fehlt'];
    }

    /**
     * Ein neuer Idempotency-Key (UUID, Version 4) – erzeugt beim Anzeigen des Formulars, nie beim Absenden.
     */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function zurFreigabe(Site $site): ResponseInterface
    {
        return new RedirectResponse((string)$this->adressen->buildUriFromRoute(self::UNTERMODUL . '.freigabe', ['site' => $site->getIdentifier()]), 303);
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
