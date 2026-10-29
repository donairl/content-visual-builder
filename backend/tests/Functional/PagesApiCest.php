<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\User\Role;
use App\Tests\Support\Contract;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\HttpSession;
use App\Tests\Support\TestDatabase;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * Page CRUD, ownership and the draft compare-and-swap.
 */
final class PagesApiCest
{
    public function createReturnsDetailWithBlankDocument(FunctionalTester $tester): void
    {
        $author = TestDatabase::createUser(Role::Author, 'Ann');
        $session = HttpSession::signedIn($tester, $author);

        $response = $session->request('POST', '/api/pages', ['title' => '  Spring Sale!  ']);

        assertSame(201, $response->getStatusCode());
        $page = HttpSession::json($response);
        assertSame('Spring Sale!', $page['title']);
        assertStringContainsString('spring-sale', (string) $page['slug']);
        assertSame('draft', $page['status']);
        assertSame($author->id, $page['authorId']);
        assertSame('Ann', $page['authorName']);
        assertSame(1, $page['version']);
        assertSame(null, $page['publishedVersion']);
        assertTrue($page['hasUnpublishedChanges']);
        assertSame('/' . $page['slug'], $page['url']);
        // An empty node map must stay a JSON object, or the editor's validator rejects the draft.
        assertStringContainsString('"nodes":{}', (string) $response->getBody());
        assertSame([], (new \App\Domain\Document\DocumentValidator(Contract::schema()))->validate($page['document']));
    }

    public function slugsAreValidatedAndDeduplicated(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Editor));

        $first = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Pricing Plans']));
        $second = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Pricing Plans']));
        assertSame($first['slug'] . '-2', $second['slug']);

        $taken = $session->request('POST', '/api/pages', ['title' => 'Copy', 'slug' => $first['slug']]);
        assertSame(422, $taken->getStatusCode());
        assertSame('slug', HttpSession::json($taken)['errors'][0]['path']);

        $reserved = $session->request('POST', '/api/pages', ['title' => 'Editor', 'slug' => 'Editor']);
        assertSame(422, $reserved->getStatusCode());
        assertSame('slug', HttpSession::json($reserved)['errors'][0]['path']);

        // Without an explicit slug a reserved one is avoided automatically.
        $auto = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Login']));
        assertSame('login-2', $auto['slug']);

        $noTitle = $session->request('POST', '/api/pages', ['title' => '']);
        assertSame(422, $noTitle->getStatusCode());
        assertSame('title', HttpSession::json($noTitle)['errors'][0]['path']);
    }

    public function renameUpdatesSummary(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $page = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Old name']));

        $response = $session->request('PATCH', '/api/pages/' . $page['id'], ['title' => 'New name', 'slug' => 'New Name 2026']);

        assertSame(200, $response->getStatusCode());
        $summary = HttpSession::json($response);
        assertSame('New name', $summary['title']);
        assertSame('new-name-2026', $summary['slug']);
        assertSame('/new-name-2026', $summary['url']);
    }

    /**
     * Ownership: an author may not read, save, publish or delete another author's page, while an
     * editor may act on anyone's.
     */
    public function authorCannotTouchAnotherAuthorsPage(FunctionalTester $tester): void
    {
        $owner = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $page = HttpSession::json($owner->request('POST', '/api/pages', ['title' => 'Owned page']));
        $id = $page['id'];

        $other = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        assertSame(403, $other->request('GET', "/api/pages/$id")->getStatusCode());
        assertSame(403, $other->request('PATCH', "/api/pages/$id", ['title' => 'Mine now'])->getStatusCode());
        assertSame(403, $other->request('PUT', "/api/pages/$id/draft", ['document' => $page['document'], 'baseVersion' => 1])->getStatusCode());
        assertSame(403, $other->request('POST', "/api/pages/$id/publish", ['baseVersion' => 1])->getStatusCode());
        assertSame(403, $other->request('POST', "/api/pages/$id/unpublish")->getStatusCode());
        assertSame(403, $other->request('DELETE', "/api/pages/$id")->getStatusCode());

        // Authors only list their own pages.
        $listed = array_column(HttpSession::json($other->request('GET', '/api/pages'))['items'], 'id');
        assertNotContains($id, $listed);

        $editor = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Editor));
        assertSame(200, $editor->request('GET', "/api/pages/$id")->getStatusCode());
        assertContains($id, array_column(HttpSession::json($editor->request('GET', '/api/pages'))['items'], 'id'));
        assertSame(204, $editor->request('DELETE', "/api/pages/$id")->getStatusCode());
        assertSame(404, $owner->request('GET', "/api/pages/$id")->getStatusCode());
    }

    public function listFiltersByStatusAndQuery(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $session->request('POST', '/api/pages', ['title' => 'Zebra crossing']);
        $session->request('POST', '/api/pages', ['title' => 'Aardvark facts']);

        $items = HttpSession::json($session->request('GET', '/api/pages', query: ['q' => 'ZEBRA']))['items'];
        assertCount(1, $items);
        assertSame('Zebra crossing', $items[0]['title']);

        assertCount(0, HttpSession::json($session->request('GET', '/api/pages', query: ['status' => 'published']))['items']);
        assertSame(422, $session->request('GET', '/api/pages', query: ['status' => 'archived'])->getStatusCode());
    }

    public function draftSaveIsCompareAndSwap(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $page = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Versioned']));
        $id = $page['id'];
        $document = Contract::json('fixtures/documents/landing.json');

        $saved = $session->request('PUT', "/api/pages/$id/draft", ['document' => $document, 'baseVersion' => 1]);
        assertSame(200, $saved->getStatusCode());
        $body = HttpSession::json($saved);
        assertSame(2, $body['version']);
        assertArrayHasKey('updatedAt', $body);

        // A second editor still holding version 1 loses.
        $stale = $session->request('PUT', "/api/pages/$id/draft", ['document' => $document, 'baseVersion' => 1]);
        assertSame(409, $stale->getStatusCode());
        $conflict = HttpSession::json($stale);
        assertArrayHasKey('error', $conflict);
        assertSame(2, $conflict['currentVersion']);
        assertSame($body['updatedAt'], $conflict['updatedAt']);

        $detail = HttpSession::json($session->request('GET', "/api/pages/$id"));
        assertSame(2, $detail['version']);
        assertSame(['hero0001', 'feat0001'], $detail['document']['rootIds']);
    }

    public function invalidDocumentIsRejectedWithPaths(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $page = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Broken']));
        $document = Contract::json('fixtures/documents/landing.json');
        $document['nodes']['head0001']['props']['tag'] = 'script';
        $document['nodes']['head0001']['style']['color'] = ['desktop' => 'red'];

        $response = $session->request('PUT', '/api/pages/' . $page['id'] . '/draft', ['document' => $document, 'baseVersion' => 1]);

        assertSame(422, $response->getStatusCode());
        $paths = array_column(HttpSession::json($response)['errors'], 'path');
        assertContains('nodes.head0001.props.tag', $paths);
        assertContains('nodes.head0001.style.color.desktop', $paths);

        $missing = $session->request('PUT', '/api/pages/' . $page['id'] . '/draft', ['document' => $document]);
        assertSame(422, $missing->getStatusCode());
        assertSame('baseVersion', HttpSession::json($missing)['errors'][0]['path']);
    }

    public function oversizedDocumentIs413(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $page = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Huge']));
        $document = Contract::json('fixtures/documents/styles.json');
        // Over limits.maxDocumentBytes (2 MiB) but under the 3 MiB request cap.
        $document['nodes']['text0002']['props']['html'] = str_repeat('a', 2_200_000);

        $response = $session->request('PUT', '/api/pages/' . $page['id'] . '/draft', ['document' => $document, 'baseVersion' => 1]);

        assertSame(413, $response->getStatusCode());
    }

    public function richTextIsSanitizedOnSave(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $page = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Sanitized']));
        $document = Contract::json('fixtures/documents/styles.json');
        $document['nodes']['text0002']['props']['html'] = '<p onclick="x()">Hi<script>alert(1)</script></p>';

        $session->request('PUT', '/api/pages/' . $page['id'] . '/draft', ['document' => $document, 'baseVersion' => 1]);

        $stored = HttpSession::json($session->request('GET', '/api/pages/' . $page['id']));
        assertSame('<p>Hi</p>', $stored['document']['nodes']['text0002']['props']['html']);
    }

    public function unknownPageIs404(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Admin));

        assertSame(404, $session->request('GET', '/api/pages/999999')->getStatusCode());
        assertSame(404, $session->request('POST', '/api/pages/999999/publish', ['baseVersion' => 1])->getStatusCode());
    }
}
