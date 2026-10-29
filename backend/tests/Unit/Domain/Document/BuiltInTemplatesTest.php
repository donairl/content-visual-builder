<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Document;

use App\Domain\Document\DocumentValidator;
use App\Tests\Support\Contract;
use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

/**
 * The built-in templates the editor ships (`schema/templates/*.json`, README §11) must be valid
 * documents, so inserting one never produces a page that cannot be saved.
 */
final class BuiltInTemplatesTest extends Unit
{
    #[DataProvider('templates')]
    public function testTemplateIsValid(string $file): void
    {
        $template = Contract::json('templates/' . $file);

        assertNotSame('', $template['name']);
        assertContains($template['kind'], ['page', 'section']);
        assertSame([], (new DocumentValidator(Contract::schema()))->validate($template['document']));
        if ($template['kind'] === 'section') {
            assertCount(1, $template['document']['rootIds']);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function templates(): iterable
    {
        foreach (glob(Contract::dir() . '/templates/*.json') ?: [] as $path) {
            yield basename($path) => [basename($path)];
        }
    }
}
