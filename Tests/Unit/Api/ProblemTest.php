<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Unit\Api;

use Lubomedia\Barrierepruefung\Api\Problem;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ProblemTest extends UnitTestCase
{
    #[Test]
    public function liestReasonDetailErrorsBlockers(): void
    {
        $p = Problem::ausAntwort(422, [
            'reason' => 'nicht_bereit',
            'detail' => 'x',
            'blockers' => ['land' => 'Land fehlt'],
            'errors' => ['contact' => ['Pflicht']],
            'url' => 'https://dienst.example/weiter',
        ]);

        self::assertNotNull($p);
        self::assertSame('nicht_bereit', $p->reason);
        self::assertSame('x', $p->detail);
        self::assertSame(['land' => 'Land fehlt'], $p->blockers);
        self::assertSame(['contact' => ['Pflicht']], $p->errors);
        self::assertSame('https://dienst.example/weiter', $p->url);
    }

    #[Test]
    public function ohneReasonIstReasonNull(): void
    {
        $p = Problem::ausAntwort(404, ['message' => 'Nicht gefunden.']);

        self::assertNotNull($p);
        self::assertNull($p->reason);
        self::assertSame('Nicht gefunden.', $p->detail);
        self::assertSame([], $p->errors);
    }

    #[Test]
    public function leererRumpfBekommtAllgemeinenText(): void
    {
        $p = Problem::ausAntwort(502, []);

        self::assertNotNull($p);
        self::assertNull($p->reason);
        self::assertStringContainsString('502', $p->detail);
    }

    #[Test]
    public function erfolgIstKeinProblem(): void
    {
        self::assertNull(Problem::ausAntwort(200, ['data' => []]));
        self::assertNull(Problem::ausAntwort(304, []));
    }

    #[Test]
    public function nichtErreichbarHatEigenenReason(): void
    {
        self::assertSame('nicht_erreichbar', Problem::nichtErreichbar('weg')->reason);
    }
}
