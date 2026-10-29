<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\User\Role;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\HttpSession;
use App\Tests\Support\TestDatabase;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * Authentication, CSRF and the JSON error contract around the API.
 */
final class ApiAuthCest
{
    public function guestGetsUnauthorizedJson(FunctionalTester $tester): void
    {
        $guest = new HttpSession($tester);

        foreach (['/api/session', '/api/pages', '/api/pages/1'] as $uri) {
            $response = $guest->request('GET', $uri);
            assertSame(401, $response->getStatusCode(), $uri);
            assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
            assertArrayHasKey('error', HttpSession::json($response));
        }
    }

    /**
     * The CSRF middleware runs before routing, so a write without the token never reaches an
     * action — for guests and signed-in users alike.
     */
    public function writeWithoutCsrfTokenIsRejected(FunctionalTester $tester): void
    {
        $guest = new HttpSession($tester);
        assertSame(422, $guest->request('POST', '/api/pages', ['title' => 'Nope'])->getStatusCode());

        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $response = $session->request('POST', '/api/pages', ['title' => 'No token'], csrf: false);
        assertSame(422, $response->getStatusCode());
        assertArrayHasKey('error', HttpSession::json($response));

        $response = $session->request('POST', '/api/pages', ['title' => 'Bad token'], headers: ['X-CSRF-Token' => 'forged']);
        assertSame(422, $response->getStatusCode());
    }

    public function sessionDescribesUserAndCapabilities(FunctionalTester $tester): void
    {
        $author = TestDatabase::createUser(Role::Author, 'Ann Author');
        $session = HttpSession::signedIn($tester, $author);

        $data = HttpSession::json($session->request('GET', '/api/session'));

        assertSame(['id' => $author->id, 'name' => 'Ann Author', 'email' => $author->email, 'role' => 'author'], $data['user']);
        assertSame(['edit_pages', 'publish_pages', 'delete_pages', 'upload_files'], $data['capabilities']);
        assertNotEmpty($data['csrfToken']);
    }

    public function unknownApiPathIsJson404(FunctionalTester $tester): void
    {
        $guest = new HttpSession($tester);

        foreach (['/api', '/api/nope', '/api/pages/1/history'] as $uri) {
            $response = $guest->request('GET', $uri);
            assertSame(404, $response->getStatusCode(), $uri);
            assertSame('Unknown API endpoint.', HttpSession::json($response)['error']);
        }
    }

    public function malformedJsonIsBadRequest(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));

        $response = $session->request('POST', '/api/pages', rawBody: '{"title": ');

        assertSame(400, $response->getStatusCode());
        assertArrayHasKey('error', HttpSession::json($response));
    }

    public function oversizedBodyIsRejectedBeforeParsing(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));

        $response = $session->request('POST', '/api/pages', rawBody: '{"title":"' . str_repeat('x', 3 * 1024 * 1024) . '"}');

        assertSame(413, $response->getStatusCode());
    }

    public function logoutEndsTheSession(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Editor));

        $response = $session->request('POST', '/logout');
        assertSame(303, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));

        assertSame(401, $session->request('GET', '/api/session')->getStatusCode());
    }
}
