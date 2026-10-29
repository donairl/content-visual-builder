<?php

declare(strict_types=1);

namespace App\Web\Shared\Middleware;

use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Header;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;

/**
 * Decodes a JSON request body into `parsedBody`.
 *
 * PHP only populates `$_POST` for form encodings, so without this the API actions and the CSRF
 * middleware would see an empty body. Malformed JSON is answered with `400` rather than being
 * treated as an empty submission — a truncated draft must not look like a blank document.
 *
 * Bodies above {@see MAX_BYTES} are refused with `413` before decoding: a document may be at most
 * 2 MB (`limits.maxDocumentBytes`), and the envelope around it is small.
 */
final readonly class JsonBodyParser implements MiddlewareInterface
{
    public const MAX_BYTES = 3 * 1024 * 1024;

    /**
     * Methods that never carry a body worth parsing.
     */
    private const BODYLESS_METHODS = [
        Method::GET,
        Method::HEAD,
        Method::OPTIONS,
    ];

    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!self::shouldParse($request)) {
            return $handler->handle($request);
        }

        // Cheap rejection on the declared length, then the real check on what was read.
        $declared = $request->getHeaderLine(Header::CONTENT_LENGTH);
        if (ctype_digit($declared) && (int) $declared > self::MAX_BYTES) {
            return $this->error(Status::PAYLOAD_TOO_LARGE, 'The request body is too large.');
        }

        $raw = self::readBody($request);
        if (strlen($raw) > self::MAX_BYTES) {
            return $this->error(Status::PAYLOAD_TOO_LARGE, 'The request body is too large.');
        }

        // An empty body on a JSON request is a submission with no fields, not an error; leave
        // `parsedBody` alone so it stays `null`.
        if ($raw === '') {
            return $handler->handle($request);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error(Status::BAD_REQUEST, 'The request body is not valid JSON.');
        }

        // A bare scalar or list cannot be assigned to `parsedBody` under PSR-7, and no endpoint
        // takes one.
        if (!is_array($decoded) || self::isNonEmptyList($decoded)) {
            return $this->error(Status::BAD_REQUEST, 'The request body must be a JSON object.');
        }

        return $handler->handle($request->withParsedBody($decoded));
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function isNonEmptyList(array $value): bool
    {
        return $value !== [] && array_is_list($value);
    }

    private static function shouldParse(ServerRequestInterface $request): bool
    {
        if (in_array(strtoupper($request->getMethod()), self::BODYLESS_METHODS, true)) {
            return false;
        }

        // A real form post has already been parsed by the SAPI; do not overwrite it.
        if ($request->getParsedBody() !== null) {
            return false;
        }

        $contentType = strtolower($request->getHeaderLine(Header::CONTENT_TYPE));

        return str_contains($contentType, 'json');
    }

    /**
     * Reads the body without consuming it, so downstream middleware and the error handler can
     * still see the original payload.
     */
    private static function readBody(ServerRequestInterface $request): string
    {
        $body = $request->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $raw = $body->getContents();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $raw;
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($status)
            ->withHeader(Header::CONTENT_TYPE, 'application/json; charset=utf-8')
            ->withBody($this->streamFactory->createStream(json_encode(['error' => $message], JSON_THROW_ON_ERROR)));
    }
}
