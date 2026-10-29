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

final class PreviewCest
{
    public function previewRequiresLogin(FunctionalTester $tester): void
    {
        $response = (new HttpSession($tester))->request('GET', '/preview/1');

        assertSame(302, $response->getStatusCode());
        assertSame('/login?return=%2Fpreview%2F1', $response->getHeaderLine('Location'));
    }

    public function previewRendersTheDraftForItsEditors(FunctionalTester $tester): void
    {
        $owner = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $page = HttpSession::json($owner->request('POST', '/api/pages', ['title' => 'Previewed']));
        $owner->request('PUT', '/api/pages/' . $page['id'] . '/draft', ['document' => Contract::json('fixtures/documents/styles.json'), 'baseVersion' => 1]);

        $response = $owner->request('GET', '/preview/' . $page['id']);

        assertSame(200, $response->getStatusCode());
        assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
        assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $html = (string) $response->getBody();
        assertStringContainsString('<span class="cvb-el cvb-el-btn00002 cvb-button cvb-button--sm">No link</span>', $html);
        assertStringContainsString('.cvb-el-spac0001{height:5vh}', $html);

        $stranger = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        assertSame(403, $stranger->request('GET', '/preview/' . $page['id'])->getStatusCode());
        assertSame(404, $owner->request('GET', '/preview/999999')->getStatusCode());
    }
}
