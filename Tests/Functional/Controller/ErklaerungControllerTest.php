<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Functional\Controller;

use GuzzleHttp\Psr7\Response;
use Lubomedia\Barrierepruefung\Erklaerung\Abruf;
use Lubomedia\Barrierepruefung\Verbindung\Tresor;
use Lubomedia\Barrierepruefung\Verbindung\Verbindung;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Registry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ErklaerungControllerTest extends FunctionalTestCase
{
    use Modulaufrufe;

    private const ROUTE = 'barrierepruefung_erklaerung';
    private const DIENST = 'https://dienst.example/api/v1';
    private const ADMIN = 1;
    private const REDAKTEURIN = 2;
    private const CTYPE = 'barrierepruefung_erklaerung';
    /** 14.11.2026, 09:00 UTC; der Fehler danach */
    private const ABGERUFEN = 1_794_646_800;
    private const FEHLER = 1_794_650_400;

    protected array $coreExtensionsToLoad = ['fluid_styled_content'];

    protected array $testExtensionsToLoad = ['lubomedia/barrierepruefung-typo3'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/erklaerung_inhalte.csv');

        $schreiber = $this->get(SiteWriter::class);
        $deutsch = ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/'];
        $schreiber->write('a', [
            'rootPageId' => 1,
            'base' => 'https://stadt-a.de/',
            'websiteTitle' => 'Stadt A',
            'languages' => [$deutsch, ['languageId' => 1, 'title' => 'English', 'locale' => 'en_GB.UTF-8', 'base' => '/en/']],
        ]);
        $schreiber->write('b', ['rootPageId' => 2, 'base' => 'https://gemeinde-b.de/', 'websiteTitle' => 'Gemeinde B', 'languages' => [$deutsch]]);
    }

    private function verbinde(string $site, string $siteId): void
    {
        $this->get(Verbindungen::class)->speichern(new Verbindung(
            $site,
            $siteId,
            self::DIENST,
            Tresor::ausInstallation()->verschluesseln('tok-' . $site),
            ['abilities' => ['sites:read', 'declaration:read'], 'publish' => ['state' => 'recht_fehlt']],
            'meta-' . $site,
            'datei-' . $site,
            1762128000,
        ));
    }

    /**
     * Was `sys_registry` über die Erklärung weiß (das Modul liest nur dort, nie beim Anzeigen vom Dienst).
     *
     * @param array<string, mixed> $ueber
     * @param array<string, mixed>|null $erklaerung
     */
    private function registry(string $siteId, array $ueber = [], ?array $erklaerung = null): void
    {
        $erklaerung ??= [
            'locale' => 'de', 'version' => 7, 'outdated' => false, 'assessed_at' => '2026-11-10', 'published_at' => '2026-11-14T09:00:00+00:00',
            'public_url' => 'https://dienst.example/e/stadt-a', 'html' => '<h1>Erklärung</h1>',
        ];
        $this->get(Registry::class)->set(Abruf::REGISTRY, 'fassung_' . $siteId, $ueber + ['erklaerung' => $erklaerung, 'etag' => '"e7"', 'abgerufen_am' => self::ABGERUFEN]);
    }

    /**
     * @return array<string, mixed>
     */
    private function aufnahme(): array
    {
        $daten = json_decode((string)file_get_contents(__DIR__ . '/../../../Build/e2e/antworten/declaration.json'), true);
        \assert(is_array($daten));

        return $daten;
    }

    private function html(\Psr\Http\Message\ResponseInterface $antwort): string
    {
        self::assertSame(200, $antwort->getStatusCode(), substr((string)$antwort->getBody(), 0, 2000));

        return (string)$antwort->getBody();
    }

    /**
     * @return list<\DOMElement>
     */
    private function suche(string $html, string $pfad): array
    {
        $treffer = (new \DOMXPath($this->dom($html)))->query($pfad);
        $elemente = [];
        foreach ($treffer !== false ? $treffer : [] as $knoten) {
            if ($knoten instanceof \DOMElement) {
                $elemente[] = $knoten;
            }
        }

        return $elemente;
    }

    private function text(string $html): string
    {
        return (string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html)));
    }

    private function seitenAnzahl(): int
    {
        return count($this->zeilen('pages', []));
    }

    /**
     * Zeilen ohne jede Einschränkung (auch verborgene und gelöschte): `Connection::select()` versteckt verborgene Seiten.
     *
     * @param array<string, string|int> $kriterien
     *
     * @return list<array<string, mixed>>
     */
    private function zeilen(string $tabelle, array $kriterien): array
    {
        $abfrage = $this->getConnectionPool()->getQueryBuilderForTable($tabelle);
        $abfrage->getRestrictions()->removeAll();
        $abfrage->select('*')->from($tabelle);
        foreach ($kriterien as $feld => $wert) {
            $abfrage->andWhere($abfrage->expr()->eq($feld, $abfrage->createNamedParameter($wert)));
        }

        return $abfrage->executeQuery()->fetchAllAssociative();
    }

    #[Test]
    public function listetSeitenNurDieserSite(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->registry('01SITEA');
        $this->attrappe([]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        $zeilen = $this->suche($html, '//table[@id="barrierepruefung-seiten"]//tbody/tr');
        $text = $this->text($html);
        // 101 (sichtbar), 104 (Element verborgen), 106 (Übersetzung), 107 (Seite verborgen); nicht: 102 fremde Site, 103 anderer CType,
        // 105 gelöscht, 109 auf gelöschter Seite, 108 Version eines Elements und 110 auf der Version einer Seite (anderer Arbeitsbereich).
        self::assertCount(4, $zeilen);
        self::assertStringContainsString('Barrierefreiheit', $text);
        self::assertStringContainsString('Noch nicht freigegeben', $text);
        self::assertStringNotContainsString('Erklärung Gemeinde B', $text);
        self::assertStringNotContainsString('Gelöschte Seite', $text);
        self::assertStringNotContainsString('Version der Seite', $text);
        self::assertCount(2, $this->suche($html, '//table[@id="barrierepruefung-seiten"]//span[contains(@class,"badge")][normalize-space(.)="verborgen"]'), 'verborgenes Element und verborgene Seite stehen als Wort');
        self::assertCount(2, $this->suche($html, '//table[@id="barrierepruefung-seiten"]//span[contains(@class,"badge")][normalize-space(.)="sichtbar"]'));
        // Link ins Seitenmodul der Seite.
        $links = array_map(static fn(\DOMElement $a): string => html_entity_decode($a->getAttribute('href')), $this->suche($html, '//table[@id="barrierepruefung-seiten"]//a'));
        self::assertNotSame([], array_filter($links, static fn(string $l): bool => str_contains($l, 'layout') && str_contains($l, 'id=4')), implode("\n", $links));
        self::assertSame([], $this->anfragenAn('/declaration'), 'Das Anzeigen fragt den Dienst nicht.');

        // Die andere Site zeigt nur ihre Seite.
        $this->verbinde('b', '01SITEB');
        $b = $this->text($this->html($this->aufrufen(self::ROUTE, ['site' => 'b'])));
        self::assertStringContainsString('Erklärung Gemeinde B', $b);
        self::assertStringNotContainsString('Noch nicht freigegeben', $b);
    }

    #[Test]
    public function sprachfassungBekommtDenSatz(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->registry('01SITEA');
        $this->anmelden(self::ADMIN);
        $this->attrappe([]);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        // Genau die Übersetzung (106) trägt ihn, die Standardsprache nicht.
        self::assertSame(1, substr_count($this->text($html), 'Zeigt die Erklärung in der freigegebenen Sprache (Deutsch), nicht übersetzt.'));
        $zeile = $this->suche($html, '//table[@id="barrierepruefung-seiten"]//tr[contains(.,"English")]');
        self::assertCount(1, $zeile);
        self::assertStringContainsString('nicht übersetzt', $zeile[0]->textContent);
    }

    #[Test]
    public function seiteAnlegenIstVerborgen(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe([]);
        $this->anmelden(self::ADMIN);
        $vorher = $this->seitenAnzahl();

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        $antwort = $this->formularAbsenden($html, 'barrierepruefung-seite-anlegen');
        $danach = $this->html($this->folgen($antwort));

        self::assertSame($vorher + 1, $this->seitenAnzahl());
        $seite = $this->zeilen('pages', ['title' => 'Barrierefreiheitserklärung'])[0] ?? null;
        self::assertIsArray($seite);
        self::assertSame(1, (int)$seite['hidden']);
        self::assertSame(1, (int)$seite['doktype']);
        self::assertSame(1, (int)$seite['pid'], 'Unter der Wurzelseite der Site');
        $element = $this->zeilen('tt_content', ['pid' => (int)$seite['uid']]);
        self::assertCount(1, $element);
        self::assertSame(self::CTYPE, $element[0]['CType']);
        self::assertSame(0, (int)$element[0]['colPos']);

        // Rückmeldung (kein Toast) mit Link ins Seitenmodul und dem Satz, was jetzt zu tun ist.
        $rueckmeldung = $this->suche($danach, '//div[contains(@class,"callout-success")][@role="status"]');
        self::assertCount(1, $rueckmeldung);
        self::assertStringContainsString('verborgen', $rueckmeldung[0]->textContent);
        self::assertStringContainsString('Fußzeile', $rueckmeldung[0]->textContent);
        $links = array_map(static fn(\DOMElement $a): string => html_entity_decode($a->getAttribute('href')), $this->suche($danach, '//div[contains(@class,"callout-success")]//a'));
        self::assertNotSame([], array_filter($links, static fn(string $l): bool => str_contains($l, 'id=' . $seite['uid'])), implode("\n", $links));
        // Und sie steht in der Liste, als verborgen.
        self::assertStringContainsString('Barrierefreiheitserklärung', $this->text($danach));
        // Zweites Anzeigen: die Rückmeldung ist weg (genau einmal).
        self::assertCount(0, $this->suche($this->html($this->aufrufen(self::ROUTE, ['site' => 'a'])), '//div[contains(@class,"callout-success")]'));
    }

    #[Test]
    public function seiteAnlegenMitSeitenrechtOhneAdminRecht(): void
    {
        $this->attrappe([]);
        $this->gruppeDarfAnlegen();
        $this->anmelden(self::REDAKTEURIN);
        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        $this->folgen($this->formularAbsenden($html, 'barrierepruefung-seite-anlegen'));

        $seite = $this->zeilen('pages', ['title' => 'Barrierefreiheitserklärung'])[0] ?? null;
        self::assertIsArray($seite, 'Mit Recht legt auch eine Redakteurin an – der DataHandler prüft, nicht das Modul.');
        self::assertSame(1, (int)$seite['hidden']);
    }

    #[Test]
    public function ohneSeitenrechtKeineSeite(): void
    {
        $this->attrappe([]);
        // Sie darf die Site sehen und das Modul benutzen, aber unter der Wurzelseite nichts anlegen.
        $this->gruppeDarfAnlegen();
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_group' => 1], ['uid' => 1]);
        $this->anmelden(self::REDAKTEURIN);
        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        $vorher = $this->seitenAnzahl();
        $inhalte = count($this->zeilen('tt_content', []));

        $antwort = $this->formularAbsenden($html, 'barrierepruefung-seite-anlegen');
        $danach = $this->html($this->folgen($antwort));

        self::assertSame($vorher, $this->seitenAnzahl(), 'Es entsteht keine Seite.');
        self::assertSame($inhalte, count($this->zeilen('tt_content', [])), 'Und kein verwaistes Element.');
        $fehler = $this->suche($danach, '//div[contains(@class,"callout-danger")][@role="alert"]');
        self::assertCount(1, $fehler);
        self::assertStringContainsString('nicht angelegt', $fehler[0]->textContent);
        self::assertStringContainsString('Recht', $fehler[0]->textContent);
    }

    #[Test]
    public function seiteAnlegenFuerSiteAusserhalbDesMountsIstVerboten(): void
    {
        $this->attrappe([]);
        $this->gruppeDarfAnlegen();
        $this->anmelden(self::REDAKTEURIN);
        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        $vorher = $this->seitenAnzahl();

        $antwort = $this->formularAbsenden($html, 'barrierepruefung-seite-anlegen', ['site' => 'b']);

        self::assertSame(403, $antwort->getStatusCode());
        self::assertSame($vorher, $this->seitenAnzahl());
    }

    #[Test]
    public function zeigtDenStandDerVeroeffentlichtenErklaerung(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->registry('01SITEA');
        $this->attrappe([]);
        $this->anmelden(self::ADMIN);

        $text = $this->text($this->html($this->aufrufen(self::ROUTE, ['site' => 'a'])));

        self::assertStringContainsString('veröffentlicht', $text);
        self::assertStringContainsString('Stand 10.11.2026', $text);
        self::assertStringContainsString('Fassung 7', $text);
        self::assertStringNotContainsString('veraltet', $text);
    }

    #[Test]
    public function veralteteErklaerungStehtAlsWort(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->registry('01SITEA', [], ['locale' => 'de', 'version' => 3, 'outdated' => true, 'published_at' => '2026-11-14T09:00:00+00:00', 'html' => '<p>x</p>']);
        $this->attrappe([]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        self::assertCount(1, $this->suche($html, '//span[contains(@class,"badge")][normalize-space(.)="veraltet"]'));
    }

    #[Test]
    public function nochNichtVeroeffentlicht(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->get(Registry::class)->set(Abruf::REGISTRY, 'fassung_01SITEA', ['letzter_fehler' => 'Keine veröffentlichte Erklärung.', 'letzter_fehler_am' => self::ABGERUFEN, 'letzter_status' => 404]);
        $this->attrappe([]);
        $this->anmelden(self::ADMIN);

        $text = $this->text($this->html($this->aufrufen(self::ROUTE, ['site' => 'a'])));

        self::assertStringContainsString('noch nicht veröffentlicht', $text);
        self::assertStringContainsString('Im Dienst ist derzeit keine Erklärung veröffentlicht.', $text);
        self::assertStringNotContainsString('Zuletzt erreichbar', $text);
    }

    #[Test]
    public function nochNieAbgerufenSagtDasUndBietetDenAbgleichAn(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe([]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        self::assertStringContainsString('noch nicht abgerufen', $this->text($html));
        self::assertStringNotContainsString('noch nicht veröffentlicht', $this->text($html), 'Ob der Dienst eine hat, weiß das Modul noch nicht.');
        self::assertCount(1, $this->suche($html, '//form[@id="barrierepruefung-abgleichen"]//button[normalize-space(.)="Jetzt abgleichen"]'));
    }

    #[Test]
    public function zuletztErreichbarBeiRueckfall(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->registry('01SITEA', ['letzter_fehler' => 'Dienst nicht erreichbar', 'letzter_fehler_am' => self::FEHLER, 'letzter_status' => 503]);
        $this->attrappe([]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        $text = $this->text($html);

        self::assertStringContainsString('Zuletzt erreichbar am 14.11.2026', $text);
        self::assertStringContainsString('Grund: Dienst nicht erreichbar', $text);
        self::assertStringContainsString('Auf der Website steht weiter die Fassung vom 14.11.2026', $text);
        self::assertStringContainsString('veröffentlicht', $text, 'Die gute Fassung bleibt Teil der Anzeige.');
        self::assertCount(1, $this->suche($html, '//div[contains(@class,"callout-warning")]'));
    }

    #[Test]
    public function netzfehlerIstAuchEinAusfall(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->registry('01SITEA', ['letzter_fehler' => 'Zeitlimit', 'letzter_fehler_am' => self::FEHLER, 'letzter_status' => 0]);
        $this->attrappe([]);
        $this->anmelden(self::ADMIN);

        $text = $this->text($this->html($this->aufrufen(self::ROUTE, ['site' => 'a'])));

        self::assertStringContainsString('Zuletzt erreichbar am 14.11.2026', $text);
        self::assertStringContainsString('Grund: Zeitlimit', $text);
    }

    #[Test]
    public function nichtVeroeffentlichtBeiGuterFassungSagtWasAufDerWebsiteSteht(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->registry('01SITEA', ['letzter_fehler' => 'Keine Erklärung.', 'letzter_fehler_am' => self::FEHLER, 'letzter_status' => 404]);
        $this->attrappe([]);
        $this->anmelden(self::ADMIN);

        $text = $this->text($this->html($this->aufrufen(self::ROUTE, ['site' => 'a'])));

        self::assertStringContainsString('Im Dienst ist derzeit keine Erklärung veröffentlicht. Auf der Website steht weiter die Fassung vom 14.11.2026.', $text);
        self::assertStringNotContainsString('Zuletzt erreichbar', $text);
    }

    #[Test]
    public function widerrufenerTokenSagtDenWegZumNeuVerbinden(): void
    {
        foreach ([401, 403] as $status) {
            $this->verbinde('a', '01SITEA');
            $this->registry('01SITEA', ['letzter_fehler' => 'Unauthorized', 'letzter_fehler_am' => self::FEHLER, 'letzter_status' => $status]);
            $this->attrappe([]);
            $this->anmelden(self::ADMIN);

            $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
            $text = $this->text($html);

            self::assertStringContainsString('Die Verbindung ist nicht mehr gültig', $text, 'Status ' . $status);
            self::assertStringContainsString('Auf der Website steht weiter die Fassung vom 14.11.2026', $text);
            self::assertStringNotContainsString('Zuletzt erreichbar', $text);
            $uebersicht = array_filter($this->suche($html, '//div[contains(@class,"callout-warning")]//a'), static fn(\DOMElement $a): bool => str_contains(html_entity_decode($a->getAttribute('href')), 'uebersicht'));
            self::assertCount(1, $uebersicht, 'Der Weg zum Neu-Verbinden ist ein Link in die Übersicht.');
        }
    }

    #[Test]
    public function abgleichenHoltDieErklaerungVomDienst(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe(['GET /api/v1/sites/01SITEA/declaration' => new Response(200, ['Content-Type' => 'application/json', 'ETag' => '"x"'], (string)json_encode($this->aufnahme()))]);
        $this->anmelden(self::ADMIN);
        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        self::assertSame([], $this->anfragenAn('/declaration'));

        $danach = $this->html($this->folgen($this->formularAbsenden($html, 'barrierepruefung-abgleichen')));

        self::assertCount(1, $this->anfragenAn('/declaration'));
        self::assertCount(1, $this->suche($danach, '//div[contains(@class,"callout-success")][@role="status"]'));
        self::assertStringContainsString('Fassung 4', $this->text($danach));
    }

    #[Test]
    public function abgleichenMeldetDenAusfallAlsFehler(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->registry('01SITEA');
        $this->attrappe(['GET /api/v1/sites/01SITEA/declaration' => new Response(503, ['Content-Type' => 'application/problem+json'], '{"status":503,"detail":"Wartung"}')]);
        $this->anmelden(self::ADMIN);
        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        $danach = $this->html($this->folgen($this->formularAbsenden($html, 'barrierepruefung-abgleichen')));

        self::assertCount(1, $this->suche($danach, '//div[contains(@class,"callout-danger")][@role="alert"]'));
        self::assertStringContainsString('Fassung 7', $this->text($danach), 'Die letzte gute Fassung bleibt stehen.');
    }

    #[Test]
    public function ohneVerbindungSagtDasUndLegtTrotzdemSeitenAn(): void
    {
        $this->attrappe([]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        self::assertStringContainsString('nicht verbunden', $this->text($html));
        self::assertCount(0, $this->suche($html, '//form[@id="barrierepruefung-abgleichen"]'));
        self::assertCount(1, $this->suche($html, '//form[@id="barrierepruefung-seite-anlegen"]'));
    }

    private function gruppeDarfAnlegen(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups', ['tables_modify' => 'pages,tt_content', 'tables_select' => 'pages,tt_content'], ['uid' => 1]);
    }
}
