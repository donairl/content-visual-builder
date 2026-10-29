<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Domain\User\Role;
use App\Tests\Support\TestDatabase;
use App\Tests\Support\WebTester;

/**
 * Through the real dev server: cookies, the CSRF hidden field and the redirect after login.
 */
final class LoginCest
{
    public function signInAndOut(WebTester $I): void
    {
        $user = TestDatabase::createUser(Role::Editor);

        $I->amOnPage('/login?return=/api/session');
        $I->fillField('email', $user->email);
        $I->fillField('password', TestDatabase::PASSWORD);
        $I->click('Sign in');

        $I->seeCurrentUrlEquals('/api/session');
        $I->seeResponseCodeIs(200);
        $I->see('"role":"editor"');
        $I->see('"csrfToken"');
    }

    public function wrongPasswordStaysOnForm(WebTester $I): void
    {
        $user = TestDatabase::createUser(Role::Author);

        $I->amOnPage('/login');
        $I->fillField('email', $user->email);
        $I->fillField('password', 'wrong-password');
        $I->click('Sign in');

        $I->seeResponseCodeIs(422);
        $I->see('Those credentials do not match our records.');
    }
}
