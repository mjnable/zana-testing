<?php

declare(strict_types=1);

namespace Zana\Testing;

use Nyholm\Psr7\ServerRequest;
use Zana\Http\HttpKernel;

/**
 * Sends requests through HttpKernel::handle() in memory; nothing is emitted.
 *
 * An array body is sent as JSON (and set as the parsed body). Requests
 * accept application/json unless another Accept header is given.
 */
final readonly class HttpTestClient
{
    /**
     * @param array<string, string> $headers Sent with every request
     */
    public function __construct(
        private HttpKernel $kernel,
        private array $headers = [],
    ) {
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->kernel, [...$this->headers, $name => $value]);
    }

    public function withToken(string $token): self
    {
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /**
     * @param array<mixed>|string|null $body
     * @param array<string, string> $headers
     */
    public function get(string $uri, array|string|null $body = null, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, $body, $headers);
    }

    /**
     * @param array<mixed>|string|null $body
     * @param array<string, string> $headers
     */
    public function post(string $uri, array|string|null $body = null, array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $body, $headers);
    }

    /**
     * @param array<mixed>|string|null $body
     * @param array<string, string> $headers
     */
    public function put(string $uri, array|string|null $body = null, array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, $body, $headers);
    }

    /**
     * @param array<mixed>|string|null $body
     * @param array<string, string> $headers
     */
    public function patch(string $uri, array|string|null $body = null, array $headers = []): TestResponse
    {
        return $this->request('PATCH', $uri, $body, $headers);
    }

    /**
     * @param array<mixed>|string|null $body
     * @param array<string, string> $headers
     */
    public function delete(string $uri, array|string|null $body = null, array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, $body, $headers);
    }

    /**
     * @param array<mixed>|string|null $body
     * @param array<string, string> $headers
     */
    public function request(string $method, string $uri, array|string|null $body = null, array $headers = []): TestResponse
    {
        $headers = ['Accept' => 'application/json', ...$this->headers, ...$headers];
        if (is_array($body)) {
            $headers['Content-Type'] ??= 'application/json';
        }

        $request = new ServerRequest($method, $uri, $headers, is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body);

        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);
        if (is_array($body)) {
            $request = $request->withParsedBody($body);
        }

        return new TestResponse($this->kernel->handle($request));
    }

    /**
     * Submit an HTML form: the fields are sent URL-encoded (and set as the
     * parsed body), and HTML is accepted unless another Accept header is given.
     *
     * @param array<string, string|list<string>> $fields
     * @param array<string, string> $headers
     */
    public function submit(string $uri, array $fields, string $method = 'POST', array $headers = []): TestResponse
    {
        $headers = [...$this->headers, 'Accept' => 'text/html', 'Content-Type' => 'application/x-www-form-urlencoded', ...$headers];
        $request = new ServerRequest($method, $uri, $headers, http_build_query($fields));

        parse_str($request->getUri()->getQuery(), $query);

        return new TestResponse($this->kernel->handle($request->withQueryParams($query)->withParsedBody($fields)));
    }
}
