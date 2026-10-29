<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;

use function PHPUnit\Framework\assertSame;

final class HomePageCest
{
    /**
     * The front page is the editor; resolving the action also proves the HTTP stack boots.
     */
    public function homeRedirectsToEditor(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(new ServerRequest(uri: '/'));

        assertSame(302, $response->getStatusCode());
        assertSame('/editor/', $response->getHeaderLine('Location'));
    }
}
