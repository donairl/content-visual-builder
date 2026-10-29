<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\Support\WebTester;

final class HomePageCest
{
    public function homeRedirectsToEditor(WebTester $I): void
    {
        $I->wantTo('be sent from / to the editor.');
        $I->stopFollowingRedirects();
        $I->amOnPage('/');
        $I->seeResponseCodeIs(302);
        $I->followRedirect();
        $I->seeCurrentUrlEquals('/editor/');
    }

    public function loginPageWorks(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->seeResponseCodeIs(200);
        $I->see('Sign in', 'h1');
        $I->seeElement('input[name="_csrf"]');
    }
}
