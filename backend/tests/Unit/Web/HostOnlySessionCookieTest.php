<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Web\Shared\Middleware\HostOnlySessionCookie;
use Codeception\Test\Unit;
use HttpSoft\Message\Response;
use HttpSoft\Message\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Session\NullSession;
use Yiisoft\Session\Session;
use Yiisoft\Session\SessionInterface;

use function PHPUnit\Framework\assertSame;

final class HostOnlySessionCookieTest extends Unit
{
    public function testDropsTheDomainFromTheSessionCookie(): void
    {
        // NullSession is named "null" and has no configured domain.
        $response = self::process(new NullSession(), [
            'null=abc123; Domain=example.com; Path=/; HttpOnly; SameSite=Lax',
            'other=1; Domain=example.com; Path=/',
        ]);

        assertSame([
            'null=abc123; Path=/; HttpOnly; SameSite=Lax',
            'other=1; Domain=example.com; Path=/',
        ], $response->getHeader('Set-Cookie'));
    }

    public function testKeepsAnExplicitlyConfiguredDomain(): void
    {
        $previous = (string) ini_get('session.cookie_domain');
        ini_set('session.cookie_domain', 'example.com');
        try {
            $cookie = 'CVBSESSID=abc123; Domain=example.com; Path=/';
            $response = self::process(new Session(['name' => 'CVBSESSID']), [$cookie]);
        } finally {
            ini_set('session.cookie_domain', $previous);
        }

        assertSame([$cookie], $response->getHeader('Set-Cookie'));
    }

    public function testLeavesResponsesWithoutCookiesAlone(): void
    {
        assertSame([], self::process(new NullSession(), [])->getHeader('Set-Cookie'));
    }

    /**
     * @param list<string> $cookies
     */
    private static function process(SessionInterface $session, array $cookies): ResponseInterface
    {
        $handler = new class ($cookies) implements RequestHandlerInterface {
            /**
             * @param list<string> $cookies
             */
            public function __construct(private readonly array $cookies) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                foreach ($this->cookies as $cookie) {
                    $response = $response->withAddedHeader('Set-Cookie', $cookie);
                }

                return $response;
            }
        };

        return (new HostOnlySessionCookie($session))->process(new ServerRequest(uri: '/'), $handler);
    }
}
