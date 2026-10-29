<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Http\Status;

/**
 * Builds the JSON responses of the REST API (README §8): `{ "error": "message" }` for failures,
 * plus `errors: [{path, message}]` for validation failures.
 */
final readonly class ApiResponder
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function json(int $status, mixed $payload): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            // API responses carry drafts and CSRF tokens; never let a shared cache keep them.
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streamFactory->createStream(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ));
    }

    /**
     * @param array<string, mixed> $extra Additional top-level keys (e.g. `currentVersion` on a 409).
     */
    public function error(int $status, string $message, array $extra = []): ResponseInterface
    {
        return $this->json($status, ['error' => $message] + $extra);
    }

    /**
     * @param list<array{path: string, message: string}> $errors
     */
    public function validation(array $errors, string $message = 'The request is invalid.'): ResponseInterface
    {
        return $this->error(Status::UNPROCESSABLE_ENTITY, $message, ['errors' => $errors]);
    }

    public function noContent(): ResponseInterface
    {
        return $this->responseFactory->createResponse(Status::NO_CONTENT)->withHeader('Cache-Control', 'no-store');
    }
}
