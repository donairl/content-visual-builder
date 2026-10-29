<?php

declare(strict_types=1);

namespace App\Web\Shared\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Session\SessionInterface;

use function preg_replace;
use function str_starts_with;

/**
 * Makes the session cookie host-only unless a cookie domain is configured explicitly.
 *
 * yiisoft/session (3.0) writes `Domain=<request host>` when no domain is configured. A cookie with a
 * `Domain` attribute is also sent to every subdomain of that host, so a session for `example.com`
 * would leak to `anything.example.com`. Without the attribute the browser returns the cookie to the
 * exact host that set it — what PHP's own session handling does, and what an empty
 * `session.cookie_domain` means.
 *
 * Sits directly outside `SessionMiddleware` so it sees the header that middleware adds.
 */
final readonly class HostOnlySessionCookie implements MiddlewareInterface
{
    public function __construct(
        private SessionInterface $session,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $cookies = $response->getHeader('Set-Cookie');
        if ($cookies === [] || $this->session->getCookieParameters()['domain'] !== '') {
            return $response;
        }

        $prefix = $this->session->getName() . '=';
        $rewritten = [];
        foreach ($cookies as $cookie) {
            $rewritten[] = str_starts_with($cookie, $prefix)
                ? (string) preg_replace('~;\s*Domain=[^;]*~i', '', $cookie)
                : $cookie;
        }

        return $response->withHeader('Set-Cookie', $rewritten);
    }
}
