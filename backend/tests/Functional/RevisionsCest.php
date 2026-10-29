<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Revision\RevisionRepository;
use App\Domain\User\Role;
use App\Tests\Support\Contract;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\HttpSession;
use App\Tests\Support\TestDatabase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

/**
 * Revision history (README §8 "Revisions").
 */
final class RevisionsCest
{
    public function draftSavesCoalesceAndPublishingAddsARevision(FunctionalTester $tester): void
    {
        $author = TestDatabase::createUser(Role::Author);
        $session = HttpSession::signedIn($tester, $author);
        $page = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'History']));
        $id = $page['id'];
        $document = Contract::json('fixtures/documents/landing.json');

        $session->request('PUT', "/api/pages/$id/draft", ['document' => $document, 'baseVersion' => 1]);
        $document['nodes']['head0001']['props']['text'] = 'Second save';
        // Sent as `[]`, stored and served back as `{}`.
        $document['nodes']['head0001']['style'] = [];
        $session->request('PUT', "/api/pages/$id/draft", ['document' => $document, 'baseVersion' => 2]);

        $items = HttpSession::json($session->request('GET', "/api/pages/$id/revisions"))['items'];
        assertCount(1, $items, 'consecutive saves by one author are one revision');
        assertSame(['draft', 3, $author->id], [$items[0]['kind'], $items[0]['version'], $items[0]['authorId']]);

        $session->request('POST', "/api/pages/$id/publish", ['baseVersion' => 3]);
        $items = HttpSession::json($session->request('GET', "/api/pages/$id/revisions"))['items'];
        assertSame(['published', 'draft'], array_column($items, 'kind'));

        // Another author's save starts a new revision even within the coalescing window.
        $editor = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Editor));
        $document['nodes']['head0001']['props']['text'] = 'Edited by the editor';
        $editor->request('PUT', "/api/pages/$id/draft", ['document' => $document, 'baseVersion' => 3]);
        $items = HttpSession::json($editor->request('GET', "/api/pages/$id/revisions"))['items'];
        assertSame(['draft', 'published', 'draft'], array_column($items, 'kind'));

        $revision = HttpSession::json($session->request('GET', "/api/pages/$id/revisions/" . $items[2]['id']));
        assertSame('Second save', $revision['document']['nodes']['head0001']['props']['text']);
        // Documents keep their empty objects through storage.
        $raw = (string) $session->request('GET', "/api/pages/$id/revisions/" . $items[2]['id'])->getBody();
        assertSame(1, preg_match('~"style":\{\}~', $raw));
    }

    public function revisionsFollowPagePermissions(FunctionalTester $tester): void
    {
        $owner = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $page = HttpSession::json($owner->request('POST', '/api/pages', ['title' => 'Private history']));
        $id = $page['id'];
        $owner->request('PUT', "/api/pages/$id/draft", ['document' => Contract::json('fixtures/documents/edge.json'), 'baseVersion' => 1]);
        $revisionId = HttpSession::json($owner->request('GET', "/api/pages/$id/revisions"))['items'][0]['id'];

        $stranger = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        assertSame(403, $stranger->request('GET', "/api/pages/$id/revisions")->getStatusCode());
        assertSame(403, $stranger->request('GET', "/api/pages/$id/revisions/$revisionId")->getStatusCode());
        assertSame(404, $owner->request('GET', "/api/pages/$id/revisions/999999")->getStatusCode());

        // A revision id of another page is not reachable through this page.
        $other = HttpSession::json($owner->request('POST', '/api/pages', ['title' => 'Other']));
        assertSame(404, $owner->request('GET', '/api/pages/' . $other['id'] . "/revisions/$revisionId")->getStatusCode());

        assertSame(204, $owner->request('DELETE', "/api/pages/$id")->getStatusCode());
        $left = TestDatabase::connection()->createCommand('SELECT COUNT(*) FROM {{%page_revisions}} WHERE page_id = :id', [':id' => $id])->queryScalar();
        assertSame(0, (int) $left);
    }

    public function onlyTheNewestRevisionsAreKept(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Editor));
        $page = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Busy']));
        $id = $page['id'];
        for ($i = 0; $i < RevisionRepository::KEEP + 3; $i++) {
            $session->request('POST', "/api/pages/$id/publish", ['baseVersion' => 1]);
        }

        assertCount(RevisionRepository::KEEP, HttpSession::json($session->request('GET', "/api/pages/$id/revisions"))['items']);
    }
}
