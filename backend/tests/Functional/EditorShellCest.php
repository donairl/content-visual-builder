<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\User\Role;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\HttpSession;
use App\Tests\Support\TestDatabase;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * The editor shell serving the Next.js static export from `public/editor/`.
 *
 * When no real export is present a tiny fake one is created for the duration of the test and
 * removed afterwards; an existing export is never modified.
 */
final class EditorShellCest
{
    private string $root = '';
    private bool $created = false;

    public function _before(): void
    {
        $this->root = dirname(__DIR__, 2) . '/public/editor';
    }

    public function _after(): void
    {
        if ($this->created) {
            foreach (['_next/static/app.js', 'edit/index.html', 'index.html'] as $file) {
                @unlink($this->root . '/' . $file);
            }
            foreach (['_next/static', '_next', 'edit', ''] as $dir) {
                @rmdir(rtrim($this->root . '/' . $dir, '/'));
            }
            $this->created = false;
        }
    }

    public function missingExportAnswers503(FunctionalTester $tester): void
    {
        if (is_file($this->root . '/index.html')) {
            $tester->comment('A real editor export is present; skipping the missing-export check.');
            return;
        }

        $response = (new HttpSession($tester))->request('GET', '/editor/');

        assertSame(503, $response->getStatusCode());
        assertStringContainsString('npm run build', (string) $response->getBody());
    }

    public function servesExportBehindLogin(FunctionalTester $tester): void
    {
        $this->ensureExport();
        $guest = new HttpSession($tester);

        $bare = $guest->request('GET', '/editor');
        assertSame(301, $bare->getStatusCode());
        assertSame('/editor/', $bare->getHeaderLine('Location'));

        $login = $guest->request('GET', '/editor/');
        assertSame(302, $login->getStatusCode());
        assertSame('/login?return=%2Feditor%2F', $login->getHeaderLine('Location'));

        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $index = $session->request('GET', '/editor/');
        assertSame(200, $index->getStatusCode());
        assertStringContainsString('text/html', $index->getHeaderLine('Content-Type'));

        assertSame(301, $session->request('GET', '/editor/edit')->getStatusCode());
        assertSame(200, $session->request('GET', '/editor/edit/')->getStatusCode());
        // Client-side routes fall back to the app shell.
        assertSame(200, $session->request('GET', '/editor/pages/42/')->getStatusCode());

        $asset = $session->request('GET', '/editor/' . $this->staticAsset());
        assertSame(200, $asset->getStatusCode());
        assertStringContainsString('javascript', $asset->getHeaderLine('Content-Type'));
        assertSame(404, $session->request('GET', '/editor/_next/static/missing.js')->getStatusCode());
    }

    public function pathTraversalIsRefused(FunctionalTester $tester): void
    {
        $this->ensureExport();
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));

        foreach (['/editor/../composer.json', '/editor/%2e%2e/composer.json', '/editor/..%2fcomposer.json', '/editor/_next/../../../composer.json'] as $uri) {
            $response = $session->request('GET', $uri);
            assertSame(404, $response->getStatusCode(), $uri);
        }
    }

    /**
     * A script of the export under test, relative to `public/editor/` (the real build's names are
     * content hashes, so one is looked up rather than hard-coded).
     */
    private function staticAsset(): string
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/_next/static', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'js') {
                return substr($file->getPathname(), strlen($this->root) + 1);
            }
        }

        throw new \RuntimeException('The editor export has no script under _next/static.');
    }

    private function ensureExport(): void
    {
        if (is_file($this->root . '/index.html')) {
            return;
        }

        mkdir($this->root . '/edit', 0775, true);
        mkdir($this->root . '/_next/static', 0775, true);
        file_put_contents($this->root . '/index.html', '<!DOCTYPE html><title>Editor</title>');
        file_put_contents($this->root . '/edit/index.html', '<!DOCTYPE html><title>Edit</title>');
        file_put_contents($this->root . '/_next/static/app.js', 'console.log(1)');
        $this->created = true;
    }
}
