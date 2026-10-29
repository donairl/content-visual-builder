<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\User\Role;
use App\Tests\Support\Contract;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\HttpSession;
use App\Tests\Support\TestDatabase;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;

/**
 * Template library (README §11).
 */
final class TemplatesCest
{
    public function savedTemplatesAreListedReadAndOwned(FunctionalTester $tester): void
    {
        $author = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $hero = Contract::json('templates/hero.json');

        $created = $author->request('POST', '/api/templates', ['name' => 'My hero', 'kind' => 'section', 'document' => $hero['document']]);
        assertSame(201, $created->getStatusCode());
        $template = HttpSession::json($created);
        assertSame(['My hero', 'section'], [$template['name'], $template['kind']]);

        $page = $author->request('POST', '/api/templates', ['name' => 'Landing', 'kind' => 'page', 'document' => Contract::json('templates/landing.json')['document']]);
        assertSame(201, $page->getStatusCode());

        $other = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        assertContains($template['id'], array_column(HttpSession::json($other->request('GET', '/api/templates'))['items'], 'id'));
        $read = HttpSession::json($other->request('GET', '/api/templates/' . $template['id']));
        assertSame($hero['document']['rootIds'], $read['document']['rootIds']);
        assertSame(403, $other->request('PATCH', '/api/templates/' . $template['id'], ['name' => 'Mine now'])->getStatusCode());
        assertSame(403, $other->request('DELETE', '/api/templates/' . $template['id'])->getStatusCode());

        assertSame(200, $author->request('PATCH', '/api/templates/' . $template['id'], ['name' => 'Renamed hero'])->getStatusCode());
        assertSame(204, $author->request('DELETE', '/api/templates/' . $template['id'])->getStatusCode());
        assertNotContains($template['id'], array_column(HttpSession::json($author->request('GET', '/api/templates'))['items'], 'id'));
        assertSame(404, $author->request('GET', '/api/templates/' . $template['id'])->getStatusCode());
    }

    public function templatesAreValidated(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Editor));
        $landing = Contract::json('templates/landing.json')['document'];

        $section = $session->request('POST', '/api/templates', ['name' => 'Too big', 'kind' => 'section', 'document' => $landing]);
        assertSame(422, $section->getStatusCode());
        assertSame('rootIds', HttpSession::json($section)['errors'][0]['path']);

        $kind = $session->request('POST', '/api/templates', ['name' => 'Odd', 'kind' => 'popup', 'document' => $landing]);
        assertSame('kind', HttpSession::json($kind)['errors'][0]['path']);

        $landing['nodes']['lhrohead']['props']['tag'] = 'script';
        $invalid = $session->request('POST', '/api/templates', ['name' => 'Broken', 'kind' => 'page', 'document' => $landing]);
        assertSame(422, $invalid->getStatusCode());
        assertSame('nodes.lhrohead.props.tag', HttpSession::json($invalid)['errors'][0]['path']);
    }
}
