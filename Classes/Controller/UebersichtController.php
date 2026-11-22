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
use Lubomedia\Barrierepruefung\Verbindung\Verbinden;
use Lubomedia\Barrierepruefung\Verbindung\Verbindung;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungsergebnis;
use Lubomedia\Barrierepruefung\Verbindung\Verifikation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Uid\Uuid;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\InputButton;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Untermodul „Übersicht“: Site verbinden, Domain bestätigen, Prüfung starten, Stand zeigen.
 * Jede Aktion antwortet mit 303 auf die Übersicht (PRG); das Ergebnis steht als Rückmeldung oben im Inhalt.
 * Kein automatisches Neuladen: den Stand holt „Status aktualisieren“ (WCAG 2.2.2, 3.2.5).
 */
#[AsController]
final class UebersichtController
{
    public const UNTERMODUL = 'barrierepruefung_uebersicht';
    public const STANDARD_DIENST = 'https://barrierepruefung.de/api/v1';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** Gründe aus Verbinden, die zu genau einem Feld gehören. */
    private const FELD_JE_GRUND = [
        'dienst_adresse_ungueltig' => 'dienst_url',
        'dienst_unsicher' => 'dienst_url',
        'nicht_erreichbar' => 'dienst_url',
        'site_id_ungueltig' => 'site_id',
        'token_fehlt' => 'api_token',
    ];

    public function __construct(
        private readonly Modulrahmen $rahmen,
        private readonly Sitewahl $sitewahl,
        private readonly Rueckmeldung $rueckmeldung,
        private readonly Verbindungen $verbindungen,
        private readonly Verbinden $verbinden,
        private readonly Verifikation $verifikation,
        private readonly Client $client,
        private readonly IconFactory $symbole,
        private readonly Anzeige $anzeige,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $vorlage = $this->rahmen->rahmen($request, self::UNTERMODUL);
        $site = $this->sitewahl->gewaehlt($request, $this->rahmen->benutzer());
        $rueckmeldung = $this->rueckmeldung->nehmen();
        $vorlage->assign('rueckmeldung', $rueckmeldung);
        if ($site === null) {
            return $vorlage->assign('keineSite', true)->renderResponse('Uebersicht/Index');
        }

        $verbindung = $this->verbindungen->fuerSite($site->getIdentifier());
        if ($verbindung === null) {
            return $this->verbindenZeigen($vorlage, $rueckmeldung, null, false);
        }
        try {
            $zugang = $this->verbindungen->zugang($verbindung);
        } catch (TresorFehler) {
            return $this->verbindenZeigen($vorlage, $rueckmeldung, $verbindung, true);
        }

        $this->knopf($vorlage, 'barrierepruefung-aktualisieren', 'aktualisieren', 'aktion.aktualisieren', 'actions-refresh', 3);
        $antwort = $this->client->get($zugang, 'sites/' . rawurlencode($verbindung->siteId));
        $stand = $this->grundstand($site, $verbindung);
        if (!$antwort->ok()) {
            $vorlage->assignMultiple([
                'fehler' => $this->fehler($antwort),
                // Kennt der Dienst das Token nicht mehr, hilft nur ein neues.
                'verbindungsformular' => $antwort->status === 401,
            ] + $this->formularDaten($rueckmeldung, $verbindung));
        } else {
            $daten = is_array($antwort->daten['data'] ?? null) ? $antwort->daten['data'] : [];
            if (is_array($daten['token'] ?? null)) {
                /** @var array<string, mixed> $token */
                $token = $daten['token'];
                $this->verbindungen->faehigkeitenAktualisieren($site->getIdentifier(), $token);
            }
            $stand = $this->stand($stand, $daten, $zugang);
            $this->knopf($vorlage, 'barrierepruefung-pruefen', 'pruefen', 'aktion.pruefen', 'actions-play', 2);
        }

        return $vorlage->assignMultiple([
            'stand' => $stand,
            'idempotencyKey' => Uuid::v4()->toRfc4122(),
        ])->renderResponse('Uebersicht/Index');
    }

    public function verbinden(ServerRequestInterface $request): ResponseInterface
    {
        $benutzer = $this->rahmen->benutzer();
        $rumpf = $this->rumpf($request);
        $site = $this->sitewahl->erlaubt($rumpf['site'] ?? null, $benutzer);
        if (!$benutzer->isAdmin() || $site === null) {
            return $this->verboten();
        }

        $dienst = $this->text($rumpf['dienst_url'] ?? null);
        $siteId = $this->text($rumpf['site_id'] ?? null);
        $ergebnis = $this->verbinden->verbinden($site, $dienst, $siteId, $this->text($rumpf['api_token'] ?? null));
        if ($ergebnis->ok) {
            $this->rueckmeldung->merken('erfolg', $this->t('verbinden.erfolg'));
        } else {
            [$text, $felder] = $this->verbindungsfehler($ergebnis, $dienst);
            // Das Token kommt nie zurück ins Formular.
            $this->rueckmeldung->merken('fehler', $text, $felder, ['dienst_url' => $dienst, 'site_id' => $siteId]);
        }

        return $this->rahmen->zurueck($request, self::UNTERMODUL, $site);
    }

    public function trennen(ServerRequestInterface $request): ResponseInterface
    {
        $benutzer = $this->rahmen->benutzer();
        $site = $this->sitewahl->erlaubt($this->rumpf($request)['site'] ?? null, $benutzer);
        if (!$benutzer->isAdmin() || $site === null) {
            return $this->verboten();
        }
        $this->verbindungen->trennen($site->getIdentifier());
        $this->rueckmeldung->merken('info', $this->t('trennen.erfolg'));

        return $this->rahmen->zurueck($request, self::UNTERMODUL, $site);
    }

    public function bestaetigen(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->sitewahl->erlaubt($this->rumpf($request)['site'] ?? null, $this->rahmen->benutzer());
        if ($site === null) {
            return $this->verboten();
        }
        $verbindung = $this->verbindungen->fuerSite($site->getIdentifier());
        if ($verbindung === null) {
            $this->rueckmeldung->merken('fehler', $this->t('fehler.nicht_verbunden'));

            return $this->rahmen->zurueck($request, self::UNTERMODUL, $site);
        }

        try {
            $ergebnis = $this->verifikation->bestaetigen($verbindung);
        } catch (TresorFehler) {
            $this->rueckmeldung->merken('fehler', $this->t('fehler.neu_verbinden'));

            return $this->rahmen->zurueck($request, self::UNTERMODUL, $site);
        }

        if ($ergebnis->bestaetigt) {
            $this->rueckmeldung->merken('erfolg', $this->t('bestaetigen.erfolg.' . $ergebnis->methode));
        } elseif ($ergebnis->fehler !== null) {
            // Wie bei „Jetzt prüfen“: das Detail des Dienstes und ein Hinweis nach `reason`, nie ein Rohcode.
            $fehler = $this->fehler($ergebnis->fehler);
            $this->rueckmeldung->merken('fehler', trim($this->t('bestaetigen.fehler.api') . ' ' . $fehler['detail'] . ' ' . $fehler['hinweis']));
        } else {
            $gruende = [];
            foreach ($ergebnis->gruende as $grund) {
                [$methode, $warum] = array_pad(explode(':', $grund, 2), 2, 'unbekannt');
                $gruende[] = $this->t('nachweis.methode.' . $methode) . ': ' . $this->wort('nachweis.', $warum);
            }
            $this->rueckmeldung->merken('fehler', $this->t('bestaetigen.fehler', implode(' ', $gruende)));
        }

        return $this->rahmen->zurueck($request, self::UNTERMODUL, $site);
    }

    public function pruefen(ServerRequestInterface $request): ResponseInterface
    {
        $rumpf = $this->rumpf($request);
        $site = $this->sitewahl->erlaubt($rumpf['site'] ?? null, $this->rahmen->benutzer());
        if ($site === null) {
            return $this->verboten();
        }
        $schluessel = $this->text($rumpf['idempotency_key'] ?? null);
        $verbindung = $this->verbindungen->fuerSite($site->getIdentifier());
        if (preg_match(self::UUID, $schluessel) !== 1) {
            // Ohne den Schlüssel aus der Anzeige gäbe ein Doppelklick zwei Prüfungen.
            $this->rueckmeldung->merken('fehler', $this->t('pruefen.ohne_schluessel'));
        } elseif ($verbindung === null) {
            $this->rueckmeldung->merken('fehler', $this->t('fehler.nicht_verbunden'));
        } else {
            try {
                $zugang = $this->verbindungen->zugang($verbindung);
                $antwort = $this->client->senden($zugang, 'POST', 'sites/' . rawurlencode($verbindung->siteId) . '/scans', [], ['Idempotency-Key' => $schluessel]);
                if ($antwort->status === 202) {
                    $this->rueckmeldung->merken('erfolg', $this->t('pruefen.erfolg'));
                } elseif ($antwort->ok()) {
                    $this->rueckmeldung->merken('info', $this->t('pruefen.laeuft_bereits'));
                } else {
                    $fehler = $this->fehler($antwort);
                    $this->rueckmeldung->merken('fehler', trim($this->t('pruefen.fehler') . ' ' . $fehler['detail'] . ' ' . $fehler['hinweis']));
                }
            } catch (TresorFehler) {
                $this->rueckmeldung->merken('fehler', $this->t('fehler.neu_verbinden'));
            }
        }

        return $this->rahmen->zurueck($request, self::UNTERMODUL, $site);
    }

    public function aktualisieren(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->sitewahl->erlaubt($this->rumpf($request)['site'] ?? null, $this->rahmen->benutzer());
        if ($site === null) {
            return $this->verboten();
        }
        // Den Stand holt die Übersicht nach dem Redirect; die Zeile sagt, dass etwas geschehen ist.
        $this->rueckmeldung->merken('info', $this->t('aktualisieren.info', date($this->t('format.zeit'))));

        return $this->rahmen->zurueck($request, self::UNTERMODUL, $site);
    }

    /**
     * @param array<string, mixed>|null $rueckmeldung
     */
    private function verbindenZeigen(ModuleTemplate $vorlage, ?array $rueckmeldung, ?Verbindung $alt, bool $neuVerbinden): ResponseInterface
    {
        return $vorlage->assignMultiple(['neuVerbinden' => $neuVerbinden] + $this->formularDaten($rueckmeldung, $alt))
            ->renderResponse('Uebersicht/Verbinden');
    }

    /**
     * Werte und Feldfehler für das Verbindungsformular: was die letzte Eingabe gebracht hat, sonst die gespeicherte
     * Verbindung, sonst die Vorgabe. Gilt für jede Stelle, an der das Formular steht (auch nach einer 401).
     *
     * @param array<string, mixed>|null $rueckmeldung
     * @return array{werte: array<string, mixed>, felder: array<string, mixed>}
     */
    private function formularDaten(?array $rueckmeldung, ?Verbindung $alt): array
    {
        $werte = is_array($rueckmeldung['werte'] ?? null) ? $rueckmeldung['werte'] : [];
        $werte += [
            'dienst_url' => $alt !== null ? $alt->dienstUrl : self::STANDARD_DIENST,
            'site_id' => $alt !== null ? $alt->siteId : '',
        ];

        return [
            'werte' => $werte,
            'felder' => is_array($rueckmeldung['felder'] ?? null) ? $rueckmeldung['felder'] : [],
        ];
    }

    /**
     * Was die Übersicht ohne den Dienst weiß.
     *
     * @return array<string, mixed>
     */
    private function grundstand(Site $site, Verbindung $verbindung): array
    {
        $pfad = $site->getBase()->getPath();

        return [
            'verbunden' => ['seit' => $this->datum($verbindung->verbundenAm)],
            'domain' => [
                'bestaetigt' => $verbindung->domainBestaetigtAm !== null,
                'am' => $verbindung->domainBestaetigtAm !== null ? $this->datum($verbindung->domainBestaetigtAm) : null,
                // Liegt die Site unter einem Pfad, findet der Dienst die Nachweisdatei nicht (VerifikationsDatei).
                'pfad' => $pfad !== '' && $pfad !== '/' ? $pfad : null,
            ],
            'kontingent' => null,
            'letzte' => null,
            'laufend' => null,
            'erklaerung' => null,
        ];
    }

    /**
     * @param array<string, mixed> $stand
     * @param array<mixed>         $daten `data` von GET /sites/{id}
     * @return array<string, mixed>
     */
    private function stand(array $stand, array $daten, Zugang $zugang): array
    {
        $bestaetigt = ($daten['verified'] ?? false) === true;
        $bestaetigtAm = $this->zeitpunkt($daten['verified_at'] ?? null);
        $stand['domain'] = ['bestaetigt' => $bestaetigt, 'am' => $bestaetigt && $bestaetigtAm !== null ? $this->datum($bestaetigtAm) : null] + (array)$stand['domain'];

        $kontingent = $daten['quota'] ?? null;
        if (is_array($kontingent) && is_int($kontingent['used'] ?? null)) {
            $bis = $this->zeitpunkt($kontingent['period_end'] ?? null);
            $stand['kontingent'] = [
                'genutzt' => $this->zahl($kontingent['used']),
                // Fluid hält die Zeichenkette „0“ für falsch: ob es eine Grenze gibt, sagt ein Wahrheitswert.
                'begrenzt' => is_int($kontingent['limit'] ?? null),
                'grenze' => is_int($kontingent['limit'] ?? null) ? $this->zahl($kontingent['limit']) : null,
                'bis' => $bis !== null ? $this->datum($bis) : null,
            ];
        }

        $letzte = $daten['latest_scan'] ?? null;
        if (is_array($letzte)) {
            $status = is_string($letzte['status'] ?? null) ? $letzte['status'] : '';
            $am = $this->zeitpunkt($letzte['finished_at'] ?? null);
            $stand['letzte'] = [
                'wort' => $this->wort('lauf.status.', $status),
                'abzeichen' => ['completed' => 'success', 'partial' => 'warning', 'failed' => 'danger'][$status] ?? 'default',
                'am' => $am !== null ? $this->datum($am) : null,
                'hatBefunde' => is_int($letzte['open_findings'] ?? null),
                'befunde' => is_int($letzte['open_findings'] ?? null) ? $this->zahl($letzte['open_findings']) : null,
                'bericht' => $this->adresse($letzte['report_url'] ?? null),
            ];
        }

        $laufend = $daten['running_scan'] ?? null;
        if (is_array($laufend) && is_string($laufend['id'] ?? null) && $laufend['id'] !== '') {
            // Die Startzeit steht nur am Lauf selbst.
            $lauf = $this->client->get($zugang, 'scans/' . rawurlencode($laufend['id']));
            $seit = $lauf->ok() ? $this->zeitpunkt($lauf->daten['data']['created_at'] ?? null) : null;
            $zeit = $seit !== null ? date($this->t('format.zeit'), $seit) : null;
            $stand['laufend'] = [
                'seit' => $seit === null ? null : (date('Y-m-d', $seit) === date('Y-m-d') ? $zeit : $this->datum($seit) . ', ' . $zeit),
            ];
        }

        $stand['erklaerung'] = $this->erklaerung($zugang);

        return $stand;
    }

    /**
     * Bis Task 13 aus GET /sites/{id}/declaration; 404 heißt: noch keine veröffentlicht.
     *
     * @return array<string, mixed>
     */
    private function erklaerung(Zugang $zugang): array
    {
        $antwort = $this->client->get($zugang, 'sites/' . rawurlencode($zugang->siteId) . '/declaration');
        if ($antwort->status === 404) {
            return ['zustand' => 'keine'];
        }
        $erklaerung = $antwort->daten['declaration'] ?? null;
        if (!$antwort->ok() || !is_array($erklaerung)) {
            return ['zustand' => 'unbekannt'];
        }
        $stand = $this->zeitpunkt($erklaerung['assessed_at'] ?? null) ?? $this->zeitpunkt($erklaerung['published_at'] ?? null);

        return [
            'zustand' => 'veroeffentlicht',
            'stand' => $stand !== null ? $this->datum($stand) : null,
            'fassung' => is_int($erklaerung['version'] ?? null) ? $erklaerung['version'] : null,
            'veraltet' => ($erklaerung['outdated'] ?? false) === true,
            'adresse' => $this->adresse($erklaerung['public_url'] ?? null),
        ];
    }

    /**
     * @return array{detail: string, hinweis: string}
     */
    private function fehler(Antwort $antwort): array
    {
        return $this->anzeige->fehler($antwort);
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function verbindungsfehler(Verbindungsergebnis $e, string $dienst): array
    {
        $grund = (string)$e->grund;
        if (str_starts_with($grund, 'api:')) {
            $hinweis = $this->t('fehler.' . substr($grund, 4));
            if ($hinweis === '') {
                $hinweis = $this->t('verbinden.fehler.api_allgemein');
            }

            return [trim($this->t('verbinden.fehler.api', (string)$e->detail) . ' ' . $hinweis), []];
        }
        $text = $this->t('verbinden.fehler.' . $grund, (string)($e->typo3Adresse ?? $dienst), (string)$e->dienstAdresse);
        if ($text === '') {
            $text = $this->t('verbinden.fehler.unbekannt', $grund);
        }
        if (isset(self::FELD_JE_GRUND[$grund])) {
            return [$this->t('verbinden.fehler.felder'), [self::FELD_JE_GRUND[$grund] => $text]];
        }

        return [$text, []];
    }

    private function knopf(ModuleTemplate $vorlage, string $formular, string $wert, string $beschriftung, string $symbol, int $gruppe): void
    {
        $knopf = GeneralUtility::makeInstance(InputButton::class)
            ->setForm($formular)
            ->setName('aktion')
            ->setValue($wert)
            ->setTitle($this->t($beschriftung))
            ->setShowLabelText(true)
            ->setIcon($this->symbole->getIcon($symbol, IconSize::SMALL));
        $vorlage->getDocHeaderComponent()->getButtonBar()->addButton($knopf, ButtonBar::BUTTON_POSITION_LEFT, $gruppe);
    }

    private function verboten(): ResponseInterface
    {
        return new HtmlResponse(htmlspecialchars($this->t('verboten')), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function rumpf(ServerRequestInterface $request): array
    {
        $rumpf = $request->getParsedBody();

        return is_array($rumpf) ? $rumpf : [];
    }

    private function text(mixed $wert): string
    {
        return is_string($wert) ? $wert : '';
    }

    private function t(string $schluessel, string ...$werte): string
    {
        return $this->anzeige->t($schluessel, ...$werte);
    }

    private function wort(string $praefix, string $wert): string
    {
        return $this->anzeige->wort($praefix, $wert);
    }

    private function datum(int $zeit): string
    {
        return $this->anzeige->datum($zeit);
    }

    private function zahl(int $zahl): string
    {
        return $this->anzeige->zahl($zahl);
    }

    private function zeitpunkt(mixed $wert): ?int
    {
        return $this->anzeige->zeitpunkt($wert);
    }

    private function adresse(mixed $wert): ?string
    {
        return $this->anzeige->adresse($wert);
    }
}
