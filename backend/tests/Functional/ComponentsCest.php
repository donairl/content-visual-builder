<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\User\Role;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\HttpSession;
use App\Tests\Support\TestDatabase;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * Reusable components (README §10): creation rules, permissions, and live pages following
 * component publishes and deletes.
 */
final class ComponentsCest
{
    public function componentsAreValidatedOnCreate(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));

        $twoRoots = self::card('Two');
        $twoRoots['rootIds'][] = 'extra001';
        $twoRoots['nodes']['extra001'] = self::node('extra001', 'spacer', []);
        $response = $session->request('POST', '/api/components', ['name' => 'Bad', 'document' => $twoRoots]);
        assertSame(422, $response->getStatusCode());
        assertSame('rootIds', HttpSession::json($response)['errors'][0]['path']);

        $nested = self::card('Nested');
        $nested['nodes']['card0001']['children'][] = 'inst0001';
        $nested['nodes']['inst0001'] = self::node('inst0001', 'component', ['componentId' => 1]);
        $response = $session->request('POST', '/api/components', ['name' => 'Bad', 'document' => $nested]);
        assertSame(422, $response->getStatusCode());
        assertSame('nodes.inst0001.type', HttpSession::json($response)['errors'][0]['path']);

        assertSame(422, $session->request('POST', '/api/components', ['name' => '  ', 'document' => self::card('x')])->getStatusCode());
    }

    public function livePagesFollowComponentPublishesAndDeletes(FunctionalTester $tester): void
    {
        $author = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $created = $author->request('POST', '/api/components', ['name' => 'Card', 'document' => self::card('First title')]);
        assertSame(201, $created->getStatusCode());
        $component = HttpSession::json($created);
        assertSame([1, 1, false], [$component['version'], $component['publishedVersion'], $component['hasUnpublishedChanges']]);
        assertContains($component['id'], array_column(HttpSession::json($author->request('GET', '/api/components'))['items'], 'id'));

        // A page using the component twice.
        $page = HttpSession::json($author->request('POST', '/api/pages', ['title' => 'With cards']));
        $id = $page['id'];
        $document = [
            'schemaVersion' => 1,
            'rootIds' => ['inst0001', 'inst0002'],
            'nodes' => [
                'inst0001' => self::node('inst0001', 'component', ['componentId' => $component['id']]),
                'inst0002' => self::node('inst0002', 'component', ['componentId' => $component['id']]),
            ],
            'settings' => new \stdClass(),
        ];
        assertSame(200, $author->request('PUT', "/api/pages/$id/draft", ['document' => $document, 'baseVersion' => 1])->getStatusCode());
        assertSame(200, $author->request('POST', "/api/pages/$id/publish", ['baseVersion' => 2])->getStatusCode());
        $live = (string) $author->request('GET', '/' . $page['slug'])->getBody();
        assertSame(2, substr_count($live, 'First title'));
        assertStringNotContainsString('id="card"', $live, 'CSS ids inside components are not rendered');
        $preview = (string) $author->request('GET', "/preview/$id")->getBody();
        assertStringContainsString('.cvb-el-card0001{padding-top:8px}', $preview);

        // Someone else's component: readable, not editable by an author.
        $other = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $cid = $component['id'];
        assertSame(200, $other->request('GET', "/api/components/$cid")->getStatusCode());
        assertSame(403, $other->request('PUT', "/api/components/$cid/draft", ['document' => self::card('Hijack'), 'baseVersion' => 1])->getStatusCode());
        assertSame(403, $other->request('DELETE', "/api/components/$cid")->getStatusCode());

        // Draft edits stay off the live site until the component is published.
        assertSame(200, $author->request('PUT', "/api/components/$cid/draft", ['document' => self::card('Second title'), 'baseVersion' => 1])->getStatusCode());
        assertStringContainsString('First title', (string) $author->request('GET', '/' . $page['slug'])->getBody());
        assertSame(409, $author->request('POST', "/api/components/$cid/publish", ['baseVersion' => 1])->getStatusCode());
        $published = $author->request('POST', "/api/components/$cid/publish", ['baseVersion' => 2]);
        assertSame(200, $published->getStatusCode());
        assertSame(2, HttpSession::json($published)['publishedVersion']);
        $live = (string) $author->request('GET', '/' . $page['slug'])->getBody();
        assertSame(2, substr_count($live, 'Second title'));

        assertSame(200, $author->request('PATCH', "/api/components/$cid", ['name' => 'Renamed card'])->getStatusCode());
        assertSame('Renamed card', HttpSession::json($author->request('GET', "/api/components/$cid"))['name']);

        assertSame(204, $author->request('DELETE', "/api/components/$cid")->getStatusCode());
        $live = (string) $author->request('GET', '/' . $page['slug'])->getBody();
        assertStringNotContainsString('Second title', $live);
        assertStringContainsString('<div class="cvb-el cvb-el-inst0001 cvb-component"></div>', $live);
        assertSame(404, $author->request('GET', "/api/components/$cid")->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private static function card(string $title): array
    {
        $card = self::node('card0001', 'container', ['contentWidth' => 'full', 'tag' => 'div'], ['head0001']);
        $card['style'] = ['padding' => ['desktop' => ['top' => 8, 'unit' => 'px']]];
        $card['advanced']['cssId'] = 'card';

        return [
            'schemaVersion' => 1,
            'rootIds' => ['card0001'],
            'nodes' => [
                'card0001' => $card,
                'head0001' => self::node('head0001', 'heading', ['text' => $title, 'tag' => 'h3']),
            ],
            'settings' => new \stdClass(),
        ];
    }

    /**
     * @param array<string, mixed> $props
     * @param list<string> $children
     *
     * @return array<string, mixed>
     */
    private static function node(string $id, string $type, array $props, array $children = []): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'props' => $props === [] ? new \stdClass() : $props,
            'style' => new \stdClass(),
            'advanced' => ['cssId' => '', 'cssClasses' => '', 'hideOn' => []],
            'children' => $children,
        ];
    }
}
