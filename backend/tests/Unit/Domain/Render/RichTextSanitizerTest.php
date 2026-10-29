<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Render;

use App\Domain\Render\RichTextSanitizer;
use App\Tests\Support\Contract;
use Codeception\Test\Unit;
use Codeception\Attribute\DataProvider;

use function PHPUnit\Framework\assertSame;

final class RichTextSanitizerTest extends Unit
{
    /**
     * Every case of `schema/fixtures/sanitize.json`, compared DOM-equal.
     */
    #[DataProvider('cases')]
    public function testSharedSanitizeCase(string $input, string $expected): void
    {
        $output = (new RichTextSanitizer(Contract::schema()))->sanitize($input);

        assertSame(Contract::canonicalDom($expected), Contract::canonicalDom($output), 'Output: ' . $output);
    }

    #[DataProvider('cases')]
    public function testSanitizingIsIdempotent(string $input): void
    {
        $sanitizer = new RichTextSanitizer(Contract::schema());
        $once = $sanitizer->sanitize($input);

        assertSame(Contract::canonicalDom($once), Contract::canonicalDom($sanitizer->sanitize($once)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        $cases = Contract::json('fixtures/sanitize.json');
        foreach (is_array($cases) ? $cases : [] as $case) {
            yield (string) $case['name'] => [(string) $case['input'], (string) $case['expected']];
        }
    }

    public function testCaseFileHasTheAgreedCount(): void
    {
        assertSame(21, iterator_count(self::cases()));
    }

    public function testLongInputIsNotTruncated(): void
    {
        $html = '<p>' . str_repeat('word ', 9000) . '</p>';

        assertSame(Contract::canonicalDom($html), Contract::canonicalDom((new RichTextSanitizer(Contract::schema()))->sanitize($html)));
    }

    public function testNestedDroppedElementsAndForeignRel(): void
    {
        $sanitizer = new RichTextSanitizer(Contract::schema());

        assertSame(
            Contract::canonicalDom('<p>keep</p>'),
            Contract::canonicalDom($sanitizer->sanitize('<form><select><option>a</option></select><svg><style>x</style></svg></form><p>keep</p><noscript><p>no</p></noscript>')),
        );
        assertSame(
            Contract::canonicalDom('<a href="#x">a</a>'),
            Contract::canonicalDom($sanitizer->sanitize('<a href="#x" rel="noopener noreferrer">a</a>')),
        );
    }
}
