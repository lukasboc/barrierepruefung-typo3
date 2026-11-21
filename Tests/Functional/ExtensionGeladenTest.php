<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ExtensionGeladenTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['fluid_styled_content'];

    protected array $testExtensionsToLoad = ['lubomedia/barrierepruefung-typo3'];

    #[Test]
    public function extensionIstGeladen(): void
    {
        self::assertTrue(ExtensionManagementUtility::isLoaded('barrierepruefung'));
    }
}
