<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Web\Shared\Http\LoginRedirect;
use Codeception\Test\Unit;
use Codeception\Attribute\DataProvider;

use function PHPUnit\Framework\assertSame;

final class LoginRedirectTest extends Unit
{
    #[DataProvider('returns')]
    public function testSafeReturn(mixed $input, string $expected): void
    {
        assertSame($expected, LoginRedirect::safeReturn($input));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function returns(): iterable
    {
        yield 'local path' => ['/editor/edit/?id=3', '/editor/edit/?id=3'];
        yield 'root' => ['/', '/'];
        yield 'protocol-relative' => ['//evil.example/', '/editor/'];
        yield 'backslash host' => ['/\\evil.example', '/editor/'];
        yield 'absolute url' => ['https://evil.example/', '/editor/'];
        yield 'relative' => ['editor', '/editor/'];
        yield 'header injection' => ["/a\r\nLocation: x", '/editor/'];
        yield 'not a string' => [['/a'], '/editor/'];
        yield 'missing' => [null, '/editor/'];
    }
}
