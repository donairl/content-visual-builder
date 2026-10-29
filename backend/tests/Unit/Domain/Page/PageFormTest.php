<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Page;

use App\Domain\Page\PageForm;
use App\Domain\Page\PageRepository;
use Codeception\Test\Unit;
use Codeception\Attribute\DataProvider;
use Yiisoft\Validator\Validator;

use function PHPUnit\Framework\assertSame;

final class PageFormTest extends Unit
{
    private PageForm $form;

    protected function _before(): void
    {
        // No database: slug uniqueness is answered by a double holding two taken slugs.
        $pages = new class extends PageRepository {
            public function __construct() {}

            public function slugExists(string $slug, ?int $exceptId = null): bool
            {
                return in_array($slug, ['about', 'about-2'], true) && $exceptId !== 1;
            }
        };

        $this->form = new PageForm(new Validator(), $pages);
    }

    #[DataProvider('slugs')]
    public function testSlugify(string $input, string $expected): void
    {
        assertSame($expected, PageForm::slugify($input));
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function slugs(): iterable
    {
        yield ['Hello World', 'hello-world'];
        yield ['  --Spring   Sale!!--  ', 'spring-sale'];
        yield ['Ünïcödé Straße', 'unicode-strasse'];
        yield ['2026 Plans', '2026-plans'];
        yield ['!!!', ''];
        yield [str_repeat('a', 150), str_repeat('a', 100)];
    }

    public function testValidPasses(): void
    {
        assertSame([], $this->form->validate('Contact us', 'contact-us'));
    }

    #[DataProvider('reserved')]
    public function testReservedSlugsAreRejected(string $slug): void
    {
        assertSame([['path' => 'slug', 'message' => 'This slug is reserved.']], $this->form->validate('Title', $slug));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function reserved(): iterable
    {
        foreach (PageForm::RESERVED_SLUGS as $slug) {
            yield $slug => [$slug];
        }
    }

    public function testReservedListMatchesTheContract(): void
    {
        assertSame(['api', 'assets', 'editor', 'login', 'logout', 'preview', 'uploads'], PageForm::RESERVED_SLUGS);
    }

    public function testDuplicateSlugIsRejectedExceptForItsOwnPage(): void
    {
        assertSame('slug', $this->form->validate('About', 'about')[0]['path'] ?? null);
        assertSame([], $this->form->validate('About', 'about', exceptId: 1));
    }

    public function testMalformedValues(): void
    {
        assertSame('slug', $this->form->validate('T', 'Not A Slug')[0]['path'] ?? null);
        assertSame('slug', $this->form->validate('T', 'double--hyphen')[0]['path'] ?? null);
        assertSame('slug', $this->form->validate('T', '')[0]['path'] ?? null);
        assertSame('title', $this->form->validate('   ', 'ok')[0]['path'] ?? null);
        assertSame('title', $this->form->validate(str_repeat('x', 201), 'ok')[0]['path'] ?? null);
        assertSame('title', $this->form->validate(['array'], 'ok')[0]['path'] ?? null);
    }

    public function testAvailableSlugSkipsTakenAndReserved(): void
    {
        assertSame('about-3', $this->form->availableSlug('About'));
        assertSame('login-2', $this->form->availableSlug('Login'));
        assertSame('page', $this->form->availableSlug('???'));
        assertSame('about', $this->form->availableSlug('About', exceptId: 1));
    }
}
