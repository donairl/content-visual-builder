<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Web\Shared\Middleware\JsonBodyParser;
use Codeception\Test\Unit;
use HttpSoft\Message\Response;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function PHPUnit\Framework\assertSame;

final class JsonBodyParserTest extends Unit
{
    public function testDecodesObjects(): void
    {
        [$response, $seen] = self::parse('PUT', '{"baseVersion":2,"document":{}}');

        assertSame(200, $response->getStatusCode());
        assertSame(['baseVersion' => 2, 'document' => []], $seen?->getParsedBody());
    }

    public function testMalformedJsonIs400(): void
    {
        assertSame(400, self::parse('POST', '{"a":')[0]->getStatusCode());
        assertSame(400, self::parse('POST', '[1,2]')[0]->getStatusCode());
        assertSame(400, self::parse('POST', '"text"')[0]->getStatusCode());
    }

    public function testOversizedBodyIs413(): void
    {
        assertSame(413, self::parse('PUT', str_repeat(' ', JsonBodyParser::MAX_BYTES + 1))[0]->getStatusCode());
        assertSame(413, self::parse('PUT', '{}', ['Content-Length' => (string) (JsonBodyParser::MAX_BYTES + 1)])[0]->getStatusCode());
    }

    public function testNonJsonAndBodylessRequestsPassThrough(): void
    {
        assertSame(null, self::parse('GET', '{"a":1}')[1]?->getParsedBody());
        assertSame(null, self::parse('POST', '', [])[1]?->getParsedBody());
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{ResponseInterface, ServerRequestInterface|null}
     */
    private static function parse(string $method, string $body, array $headers = []): array
    {
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $seen = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request;

                return new Response();
            }
        };

        $request = new ServerRequest(
            method: $method,
            uri: '/api/x',
            headers: $headers + ['Content-Type' => 'application/json'],
            body: (new StreamFactory())->createStream($body),
        );
        $response = (new JsonBodyParser(new ResponseFactory(), new StreamFactory()))->process($request, $handler);

        return [$response, $handler->seen];
    }
}
