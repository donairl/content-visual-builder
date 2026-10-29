<?php

declare(strict_types=1);

namespace App\Web\Shared\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Headers for published pages.
 *
 * The CSP allows exactly what Scope 1 widgets emit: the two same-origin stylesheets, images and
 * video files from this site or any HTTPS host, and YouTube/Vimeo embeds. No script source is
 * allowed at all — public pages run no JavaScript.
 *
 * `'unsafe-hashes'` plus the hash of `margin:0` admits only the `<body style="margin:0">` attribute
 * the contract's page document requires (README §5); every other inline style stays blocked.
 */
final class SecurityHeaders
{
    public const PUBLIC_PAGE_CSP = "default-src 'none'; "
        . "style-src 'self' 'unsafe-hashes' 'sha256-q3nqK4VzeI/SowYCYQ/tCfo056B/JMutuSpzbJbHczk='; "
        . "img-src 'self' https:; media-src 'self' https:; "
        . 'frame-src https://www.youtube-nocookie.com https://player.vimeo.com; '
        . "base-uri 'none'; form-action 'self'; frame-ancestors 'self'";

    public static function publicPage(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Content-Security-Policy', self::PUBLIC_PAGE_CSP)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /**
     * The preview inlines its CSS (a draft has no stored stylesheet), so it needs inline styles.
     */
    public static function preview(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader(
                'Content-Security-Policy',
                str_replace(
                    "style-src 'self' 'unsafe-hashes' 'sha256-q3nqK4VzeI/SowYCYQ/tCfo056B/JMutuSpzbJbHczk='",
                    "style-src 'self' 'unsafe-inline'",
                    self::PUBLIC_PAGE_CSP,
                ),
            )
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('X-Robots-Tag', 'noindex')
            ->withHeader('Cache-Control', 'no-store');
    }
}
