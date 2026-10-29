<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\Support\WebTester;

final class NotFoundHandlerCest
{
    public function unknownSlugIs404(WebTester $I): void
    {
        $I->amOnPage('/non-existent-page');
        $I->seeResponseCodeIs(404);
        $I->see('Page not found');
    }

    public function unmatchedRouteIs404(WebTester $I): void
    {
        $I->amOnPage('/no/such/route');
        $I->seeResponseCodeIs(404);
        $I->see('Page not found');
    }

    public function unknownApiEndpointIsJson(WebTester $I): void
    {
        $I->amOnPage('/api/nothing-here');
        $I->seeResponseCodeIs(404);
        $I->seeInSource('{"error":"Unknown API endpoint."}');
    }
}
