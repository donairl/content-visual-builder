<?php

declare(strict_types=1);

namespace App\Web\Editor;

use App\Domain\User\User;
use App\Web\Shared\Http\LoginRedirect;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * `/editor` and `/editor/{path}`: serves the Next.js static export copied to `public/editor/`.
 *
 * The export is built with `trailingSlash: true`, so `/editor/edit/` is `edit/index.html`. Unknown
 * paths fall back to the root `index.html` and the client-side router takes over. A web server in
 * front normally serves the static files itself; this action covers the PHP dev server and setups
 * that route everything through PHP.
 *
 * HTML documents require a signed-in user (assets do not, they hold no data).
 */
final readonly class Action
{
    private const TYPES = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'txt' => 'text/plain; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    public function __construct(
        private Aliases $aliases,
        private CurrentRoute $currentRoute,
        private CurrentUser $currentUser,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $path = $this->currentRoute->getArgument('path');
        if ($path === null) {
            return $this->redirect('/editor/');
        }

        $root = realpath($this->aliases->get('@public/editor'));
        if ($root === false || !is_file($root . '/index.html')) {
            return $this->plain(
                Status::SERVICE_UNAVAILABLE,
                "The editor has not been built yet.\nRun `npm run build` in editor/ — it exports the app and copies it to backend/public/editor/.",
            );
        }

        $file = $this->resolve($root, rawurldecode($path));
        if ($file === null) {
            return $this->plain(Status::NOT_FOUND, 'Not found.');
        }

        // A directory requested without its trailing slash: redirect so relative URLs resolve.
        if (is_dir($file)) {
            return $this->redirect('/editor/' . trim($path, '/') . '/');
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($extension === 'html' && !$this->currentUser->getIdentity() instanceof User) {
            return LoginRedirect::to($this->responseFactory, $request);
        }

        return $this->responseFactory->createResponse(Status::OK)
            ->withHeader('Content-Type', self::TYPES[$extension] ?? 'application/octet-stream')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Next.js fingerprints everything under _next/static; HTML must always be revalidated.
            ->withHeader('Cache-Control', str_contains($file, '/_next/static/') ? 'public, max-age=31536000, immutable' : 'no-cache')
            ->withBody($this->streamFactory->createStreamFromFile($file));
    }

    /**
     * Maps a URL path below `/editor/` to a file inside the export, or `null`.
     *
     * Traversal is impossible twice over: `..`, NUL and backslashes are refused outright, and the
     * resolved real path must still lie inside the export directory.
     */
    private function resolve(string $root, string $path): ?string
    {
        if (str_contains($path, "\0") || str_contains($path, '\\') || preg_match('~(^|/)\.\.(/|$)~', $path) === 1) {
            return null;
        }

        $relative = trim($path, '/');
        $candidates = $relative === ''
            ? [$root . '/index.html']
            : [$root . '/' . $relative . '/index.html', $root . '/' . $relative, $root . '/' . $relative . '.html'];

        foreach ($candidates as $index => $candidate) {
            $real = self::inside($root, $candidate);
            if ($real !== null && is_file($real)) {
                // `edit/index.html` reached without the trailing slash → redirect, not serve.
                if ($index === 0 && $relative !== '' && !str_ends_with($path, '/')) {
                    return dirname($real);
                }

                return $real;
            }
        }

        // Unknown route inside the app (e.g. a client-side route): let the editor's router decide.
        // Missing assets stay 404 so a broken build is visible.
        if (preg_match('~\.[a-z0-9]+$~i', $relative) === 1) {
            return null;
        }

        return $root . '/index.html';
    }

    /**
     * The real path of `$candidate` if it exists inside `$root` (symlinks resolved), else `null`.
     */
    private static function inside(string $root, string $candidate): ?string
    {
        $real = realpath($candidate);

        return is_string($real) && str_starts_with($real, $root . '/') ? $real : null;
    }

    private function redirect(string $location): ResponseInterface
    {
        return $this->responseFactory->createResponse(Status::MOVED_PERMANENTLY)->withHeader('Location', $location);
    }

    private function plain(int $status, string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write($message);

        return $response;
    }
}
