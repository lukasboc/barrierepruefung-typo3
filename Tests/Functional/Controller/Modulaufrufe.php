<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Functional\Controller;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Http\Application;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Ruft Backend-Module wie ein Browser auf: durch die ganze Middleware-Kette des Backends (Anmeldung per
 * Sitzungs-Cookie, Routen-Token, Modulrechte), mit einer Attrappe des Dienstes je „METHODE Pfad“.
 */
trait Modulaufrufe
{
    /** @var list<RequestInterface> */
    private array $anfragen = [];

    /** @var array<string, ResponseInterface> */
    private array $antworten = [];

    private bool $attrappeGesetzt = false;

    private string $sitzung = '';

    /**
     * @param array<string, ResponseInterface> $antworten Schlüssel „GET /api/v1/sites/01X“; alles andere antwortet 404
     */
    private function attrappe(array $antworten): void
    {
        $this->antworten = $antworten;
        $this->anfragen = [];
        if ($this->attrappeGesetzt) {
            return;
        }
        $this->attrappeGesetzt = true;
        $container = $this->getContainer();
        \assert($container instanceof \Symfony\Component\DependencyInjection\Container);
        $container->set('barrierepruefung.guzzle', new GuzzleClient(['handler' => function (RequestInterface $anfrage): mixed {
            $this->anfragen[] = $anfrage;
            $antwort = $this->antworten[$anfrage->getMethod() . ' ' . $anfrage->getUri()->getPath()]
                ?? new Response(404, ['Content-Type' => 'application/problem+json'], '{"status":404,"detail":"Nicht gefunden."}');

            return Create::promiseFor($antwort);
        }]));
    }

    private function anmelden(int $benutzer): BackendUserAuthentication
    {
        $angemeldet = $this->setUpBackendUser($benutzer);
        $this->sitzung = $angemeldet->getSession()->getJwt();

        return $angemeldet;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|null $rumpf POST-Felder (verschachtelt wie $_POST); null = GET
     */
    private function aufrufen(string $route, array $query = [], ?array $rumpf = null): ResponseInterface
    {
        $pfad = (string)$this->get(UriBuilder::class)->buildUriFromRoute($route, $query);
        // Der Schlüssel hinter dem Routen-Token liegt sonst nur im Speicher der Formularschutz-Instanz.
        $this->get(FormProtectionFactory::class)->createForType('backend')->persistSessionToken();

        return $this->anfrage($pfad, $rumpf);
    }

    private function folgen(ResponseInterface $antwort): ResponseInterface
    {
        self::assertSame(303, $antwort->getStatusCode(), 'Erwartet: 303 auf die Übersicht');

        return $this->anfrage($antwort->getHeaderLine('Location'), null);
    }

    /**
     * Schickt das Formular mit der Kennung ab, mit seinen versteckten Feldern und $felder.
     *
     * @param array<string, string> $felder
     */
    private function formularAbsenden(string $html, string $formular, array $felder = []): ResponseInterface
    {
        $dom = $this->dom($html);
        $form = $dom->getElementById($formular);
        self::assertNotNull($form, 'Formular ' . $formular . ' fehlt');
        self::assertSame('post', strtolower($form->getAttribute('method')));
        $werte = [];
        foreach ((new \DOMXPath($dom))->query('.//input | .//textarea', $form) ?: [] as $feld) {
            \assert($feld instanceof \DOMElement);
            // Wie ein Browser: von Optionsfeldern und Kästchen nur die gewählten.
            $art = strtolower($feld->getAttribute('type'));
            if ($feld->getAttribute('name') === '' || (in_array($art, ['radio', 'checkbox'], true) && !$feld->hasAttribute('checked'))) {
                continue;
            }
            $werte[$feld->getAttribute('name')] = $feld->tagName === 'textarea' ? $feld->textContent : $feld->getAttribute('value');
        }

        return $this->anfrage($form->getAttribute('action'), $felder + $werte);
    }

    /**
     * @param array<string, mixed>|null $rumpf
     */
    private function anfrage(string $pfad, ?array $rumpf): ResponseInterface
    {
        $teile = parse_url($pfad);
        $methode = $rumpf === null ? 'GET' : 'POST';
        $server = [
            'HTTP_HOST' => 'typo3-testing.local',
            'SERVER_NAME' => 'typo3-testing.local',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
            'REQUEST_URI' => $pfad,
            'QUERY_STRING' => $teile['query'] ?? '',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $this->instancePath . '/index.php',
            'DOCUMENT_ROOT' => $this->instancePath,
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_METHOD' => $methode,
        ];
        parse_str($teile['query'] ?? '', $query);
        $request = (new ServerRequest('https://typo3-testing.local' . $pfad, $methode, 'php://temp', ['Accept-Language' => 'de'], $server))
            ->withQueryParams($query)
            ->withCookieParams(['be_typo_user' => $this->sitzung]);
        if ($rumpf !== null) {
            $request = $request->withParsedBody($rumpf);
        }
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        return $this->get(Application::class)->handle($request);
    }

    private function dom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return $dom;
    }

    /**
     * @return list<RequestInterface>
     */
    private function anfragenAn(string $teil): array
    {
        return array_values(array_filter($this->anfragen, static fn(RequestInterface $a): bool => str_contains((string)$a->getUri(), $teil)));
    }
}
