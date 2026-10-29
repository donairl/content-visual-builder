<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\User\Role;
use App\Tests\Support\Contract;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\HttpSession;
use App\Tests\Support\TestDatabase;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * Publishing, the public page and its stylesheets.
 */
final class PublishCest
{
    public function publishedPageIsServedWithSecurityHeaders(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $page = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Launch <Day>']));
        $id = $page['id'];
        $session->request('PUT', "/api/pages/$id/draft", ['document' => Contract::json('fixtures/documents/landing.json'), 'baseVersion' => 1]);

        // Publishing an outdated version is a conflict, like saving one.
        assertSame(409, $session->request('POST', "/api/pages/$id/publish", ['baseVersion' => 1])->getStatusCode());

        $published = $session->request('POST', "/api/pages/$id/publish", ['baseVersion' => 2]);
        assertSame(200, $published->getStatusCode());
        $summary = HttpSession::json($published);
        assertSame('published', $summary['status']);
        assertSame(2, $summary['publishedVersion']);
        assertFalse($summary['hasUnpublishedChanges']);

        $response = $session->request('GET', '/' . $page['slug']);
        assertSame(200, $response->getStatusCode());
        assertStringContainsString("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
        assertStringContainsString("frame-ancestors 'self'", $response->getHeaderLine('Content-Security-Policy'));
        assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));

        $html = (string) $response->getBody();
        assertStringNotContainsString('/editor', $html);
        assertStringNotContainsString('<script', $html);
        assertStringContainsString('<title>Launch &lt;Day&gt;</title>', $html);
        assertStringContainsString('<meta name="description" content="Landing page fixture">', $html);
        assertStringContainsString('<div class="cvb-root"><section id="hero"', $html);

        // Both stylesheets resolve; the page CSS is exactly the generated CSS.
        assertSame(1, preg_match('~href="(/assets/page/\d+\.[a-f0-9]+\.css)"~', $html, $pageCss));
        $css = $session->request('GET', $pageCss[1]);
        assertSame(200, $css->getStatusCode());
        assertStringContainsString('text/css', $css->getHeaderLine('Content-Type'));
        assertStringContainsString('immutable', $css->getHeaderLine('Cache-Control'));
        assertSame(Contract::read('fixtures/documents/landing.expected.css'), (string) $css->getBody());

        assertSame(1, preg_match('~href="(/assets/cvb-base\.[a-f0-9]+\.css)"~', $html, $baseCss));
        $base = $session->request('GET', $baseCss[1]);
        assertSame(200, $base->getStatusCode());
        assertSame(Contract::read('base.css'), (string) $base->getBody());

        // A wrong hash is not served (the URL is cached forever).
        assertSame(404, $session->request('GET', '/assets/page/' . $id . '.0000000000000000.css')->getStatusCode());
        assertSame(302, $session->request('GET', '/assets/cvb-base.0000000000000000.css')->getStatusCode());
    }

    public function draftEditsDoNotChangeTheLivePage(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $page = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Stable']));
        $id = $page['id'];
        $document = Contract::json('fixtures/documents/landing.json');
        $session->request('PUT', "/api/pages/$id/draft", ['document' => $document, 'baseVersion' => 1]);
        $session->request('POST', "/api/pages/$id/publish", ['baseVersion' => 2]);

        $document['nodes']['head0001']['props']['text'] = 'Changed in the draft';
        assertSame(200, $session->request('PUT', "/api/pages/$id/draft", ['document' => $document, 'baseVersion' => 2])->getStatusCode());

        $live = (string) $session->request('GET', '/' . $page['slug'])->getBody();
        assertStringNotContainsString('Changed in the draft', $live);
        assertTrue(HttpSession::json($session->request('GET', "/api/pages/$id"))['hasUnpublishedChanges']);
    }

    public function draftAndUnknownSlugsAre404(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $page = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Not yet']));

        assertSame(404, $session->request('GET', '/' . $page['slug'])->getStatusCode());
        assertSame(404, (new HttpSession($tester))->request('GET', '/' . $page['slug'])->getStatusCode());
        assertSame(404, $session->request('GET', '/no-such-page')->getStatusCode());
    }

    public function unpublishTakesThePageOffline(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Editor));
        $page = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Short lived']));
        $id = $page['id'];
        $session->request('POST', "/api/pages/$id/publish", ['baseVersion' => 1]);
        assertSame(200, $session->request('GET', '/' . $page['slug'])->getStatusCode());

        $response = $session->request('POST', "/api/pages/$id/unpublish");

        assertSame(200, $response->getStatusCode());
        assertSame('draft', HttpSession::json($response)['status']);
        assertSame(404, $session->request('GET', '/' . $page['slug'])->getStatusCode());
    }

    public function publishRejectsAnInvalidStoredDraft(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $page = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Corrupted']));
        // Simulates a draft saved under an older schema that no longer validates.
        TestDatabase::connection()->createCommand()
            ->update('{{%pages}}', ['draft_document' => '{"schemaVersion":1,"rootIds":["gone0001"],"nodes":{},"settings":{}}'], ['id' => $page['id']])
            ->execute();

        $response = $session->request('POST', '/api/pages/' . $page['id'] . '/publish', ['baseVersion' => 1]);

        assertSame(422, $response->getStatusCode());
        assertSame('rootIds', HttpSession::json($response)['errors'][0]['path']);
    }

    public function pagesRerenderKeepsPublishedOutput(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $page = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Rerendered']));
        $id = $page['id'];
        $session->request('PUT', "/api/pages/$id/draft", ['document' => Contract::json('fixtures/documents/styles.json'), 'baseVersion' => 1]);
        $session->request('POST', "/api/pages/$id/publish", ['baseVersion' => 2]);
        $before = (string) $session->request('GET', '/' . $page['slug'])->getBody();

        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/yii') . ' pages:rerender 2>&1',
            $output,
            $exitCode,
        );

        assertSame(0, $exitCode, implode("\n", $output));
        assertMatchesRegularExpression('~Re-rendered \d+ published page~', implode("\n", $output));
        assertSame($before, (string) $session->request('GET', '/' . $page['slug'])->getBody());
    }
}
