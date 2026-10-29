<?php

declare(strict_types=1);

namespace App\Web\Shared\Http;

use App\Api\Shared\ApiResponder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;

/**
 * Answers a missing or invalid CSRF token ("nonce") with 422, as JSON for API calls so the editor
 * can show the same error shape as every other API failure.
 */
final readonly class CsrfFailureHandler implements RequestHandlerInterface
{
    public function __construct(
        private ApiResponder $responder,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === '/api' || str_starts_with($path, '/api/')) {
            return $this->responder->error(Status::UNPROCESSABLE_ENTITY, 'Missing or invalid CSRF token. Reload the editor and try again.');
        }

        $response = $this->responseFactory->createResponse(Status::UNPROCESSABLE_ENTITY)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->getBody()->write('The form expired. Go back, reload the page and try again.');

        return $response;
    }
}
