<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\User\Role;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\HttpSession;
use App\Tests\Support\TestDatabase;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class LoginCest
{
    public function loginFormCarriesCsrfField(FunctionalTester $tester): void
    {
        $response = (new HttpSession($tester))->request('GET', '/login');

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('name="_csrf"', (string) $response->getBody());
    }

    public function wrongPasswordIsRejectedWithoutDetail(FunctionalTester $tester): void
    {
        $user = TestDatabase::createUser(Role::Author);
        $session = new HttpSession($tester);

        $wrong = $session->login($user->email, 'not-the-password');
        $unknown = $session->login('nobody@example.com', 'whatever-password');

        assertSame(422, $wrong->getStatusCode());
        assertSame(422, $unknown->getStatusCode());
        assertStringContainsString('Those credentials do not match our records.', (string) $wrong->getBody());
        assertStringNotContainsString('not-the-password', (string) $wrong->getBody());
    }

    public function sessionCookieIsHostOnly(FunctionalTester $tester): void
    {
        $user = TestDatabase::createUser(Role::Author);

        $response = (new HttpSession($tester))->login($user->email, TestDatabase::PASSWORD);
        $cookies = array_values(array_filter(
            $response->getHeader('Set-Cookie'),
            static fn(string $cookie): bool => str_starts_with($cookie, 'CVBSESSID='),
        ));

        assertSame(303, $response->getStatusCode());
        assertSame(1, count($cookies));
        // A `Domain` attribute would send the cookie to every subdomain as well.
        assertStringNotContainsString('domain=', strtolower($cookies[0]));
        assertStringContainsString('HttpOnly', $cookies[0]);
    }

    public function loginFollowsOnlyLocalReturnPaths(FunctionalTester $tester): void
    {
        $user = TestDatabase::createUser(Role::Author);

        $local = (new HttpSession($tester))->login($user->email, TestDatabase::PASSWORD, '/preview/7');
        assertSame(303, $local->getStatusCode());
        assertSame('/preview/7', $local->getHeaderLine('Location'));

        foreach (['//evil.example/x', 'https://evil.example', '/\\evil.example', "/x\r\nSet-Cookie: a=b"] as $return) {
            $response = (new HttpSession($tester))->login($user->email, TestDatabase::PASSWORD, $return);
            assertSame('/editor/', $response->getHeaderLine('Location'), $return);
        }
    }
}
