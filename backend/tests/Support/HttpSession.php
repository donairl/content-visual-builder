<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\User\User;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function PHPUnit\Framework\assertSame;

/**
 * A browser-like client for functional tests: keeps the session cookie between in-process
 * requests and knows the CSRF token, so tests can act as a signed-in user.
 */
final class HttpSession
{
    /**
     * @var array<string, string>
     */
    private array $cookies = [];

    public ?string $csrfToken = null;

    public function __construct(
        private readonly FunctionalTester $tester,
    ) {}

    public static function signedIn(FunctionalTester $tester, User $user): self
    {
        $session = new self($tester);
        $session->login($user->email, TestDatabase::PASSWORD);

        $response = $session->request('GET', '/api/session');
        assertSame(200, $response->getStatusCode(), 'Login did not produce a session.');
        $session->csrfToken = (string) self::json($response)['csrfToken'];

        return $session;
    }

    public function login(string $email, string $password, string $return = ''): ResponseInterface
    {
        $form = $this->request('GET', '/login');
        if (preg_match('~name="_csrf" value="([^"]+)"~', (string) $form->getBody(), $m) !== 1) {
            throw new RuntimeException('No CSRF field on the login form.');
        }

        return $this->request(
            'POST',
            '/login',
            formBody: ['_csrf' => html_entity_decode($m[1]), 'email' => $email, 'password' => $password],
            query: $return === '' ? [] : ['return' => $return],
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @param array<string, mixed>|null $formBody Already-parsed form fields (as the SAPI would give).
     * @param array<string, mixed> $files
     */
    public function request(
        string $method,
        string $uri,
        mixed $json = null,
        array $headers = [],
        array $query = [],
        ?array $formBody = null,
        array $files = [],
        bool $csrf = true,
        ?string $rawBody = null,
    ): ResponseInterface {
        if ($csrf && $this->csrfToken !== null && $method !== 'GET') {
            $headers['X-CSRF-Token'] ??= $this->csrfToken;
        }

        $body = null;
        if ($rawBody !== null || $json !== null) {
            $headers['Content-Type'] ??= 'application/json';
            $body = (new StreamFactory())->createStream($rawBody ?? json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        $request = new ServerRequest(
            uploadedFiles: $files,
            cookieParams: $this->cookies,
            queryParams: $query,
            parsedBody: $formBody,
            method: $method,
            uri: 'http://localhost' . $uri,
            headers: $headers,
            body: $body,
        );

        $response = $this->tester->sendRequest($request);
        $this->rememberCookies($response);

        return $response;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function rememberCookies(ResponseInterface $response): void
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $pair = explode(';', $header, 2)[0];
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $this->cookies[trim($name)] = urldecode($value);
        }
    }
}
