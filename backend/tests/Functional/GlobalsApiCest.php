<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\User\Role;
use App\Tests\Support\Contract;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\HttpSession;
use App\Tests\Support\TestDatabase;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * Global styles (README §9): reading, permissions, compare-and-swap saves, and published pages
 * following a change.
 */
final class GlobalsApiCest
{
    public function _after(): void
    {
        // Globals are site-wide: leave the shared test database on the schema defaults.
        TestDatabase::connection()->createCommand()->delete('{{%site_settings}}')->execute();
    }

    public function authorsReadDefaultsButCannotSave(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));

        $response = $session->request('GET', '/api/globals');
        assertSame(200, $response->getStatusCode());
        $body = HttpSession::json($response);
        assertSame(0, $body['version']);
        assertSame(Contract::json('globals.json'), $body['globals']);

        $save = $session->request('PUT', '/api/globals', ['globals' => $body['globals'], 'baseVersion' => 0]);
        assertSame(403, $save->getStatusCode());
        assertSame(401, (new HttpSession($tester))->request('GET', '/api/globals')->getStatusCode());
    }

    public function savingValidatesComparesAndRerendersPublishedPages(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Editor));
        $page = HttpSession::json($session->request('POST', '/api/pages', ['title' => 'Uses globals']));
        $id = $page['id'];
        $document = Contract::json('fixtures/documents/landing.json');
        $document['nodes']['head0001']['style'] = ['color' => ['desktop' => 'global:primary']];
        assertSame(200, $session->request('PUT', "/api/pages/$id/draft", ['document' => $document, 'baseVersion' => 1])->getStatusCode());
        $session->request('POST', "/api/pages/$id/publish", ['baseVersion' => 2]);
        assertStringContainsString('.cvb-el-head0001{color:#5b50e6}', self::liveCss($session, $page['slug']));

        $globals = HttpSession::json($session->request('GET', '/api/globals'))['globals'];
        $globals['colors'][0]['value'] = 'red';
        $invalid = $session->request('PUT', '/api/globals', ['globals' => $globals, 'baseVersion' => 0]);
        assertSame(422, $invalid->getStatusCode());
        assertSame('colors.0.value', HttpSession::json($invalid)['errors'][0]['path']);

        $globals['colors'][0]['value'] = '#ff0000';
        assertSame(409, $session->request('PUT', '/api/globals', ['globals' => $globals, 'baseVersion' => 5])->getStatusCode());

        $saved = $session->request('PUT', '/api/globals', ['globals' => $globals, 'baseVersion' => 0]);
        assertSame(200, $saved->getStatusCode());
        assertSame(1, HttpSession::json($saved)['version']);
        assertStringContainsString('.cvb-el-head0001{color:#ff0000}', self::liveCss($session, $page['slug']));

        // The stored version moved on: saving against the old base is a conflict.
        assertSame(409, $session->request('PUT', '/api/globals', ['globals' => $globals, 'baseVersion' => 0])->getStatusCode());
        $read = HttpSession::json($session->request('GET', '/api/globals'));
        assertSame(1, $read['version']);
        assertSame('#ff0000', $read['globals']['colors'][0]['value']);
        // Lists stay lists and empty objects stay objects through storage.
        $globals['fonts'] = [['id' => 'plain', 'name' => 'Plain', 'style' => new \stdClass()]];
        assertSame(200, $session->request('PUT', '/api/globals', ['globals' => $globals, 'baseVersion' => 1])->getStatusCode());
        $raw = (string) $session->request('GET', '/api/globals')->getBody();
        assertStringContainsString('"fonts":[{"id":"plain","name":"Plain","style":{}}]', $raw);
    }

    private static function liveCss(HttpSession $session, string $slug): string
    {
        $html = (string) $session->request('GET', '/' . $slug)->getBody();
        preg_match('~href="(/assets/page/\d+\.[a-f0-9]+\.css)"~', $html, $match);

        return (string) $session->request('GET', $match[1] ?? '/missing')->getBody();
    }
}
