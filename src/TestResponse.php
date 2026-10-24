<?php

declare(strict_types=1);

namespace Zana\Testing;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

/**
 * A response with assertions. JSON paths use dots and numeric indexes:
 * "data.items.0.id".
 */
final class TestResponse
{
    private bool $decoded = false;

    private mixed $json = null;

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function response(): ResponseInterface
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function body(): string
    {
        return (string) $this->response->getBody();
    }

    /**
     * The decoded JSON body; invalid JSON fails the test.
     */
    public function json(): mixed
    {
        if (!$this->decoded) {
            try {
                $this->json = json_decode($this->body(), true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                Assert::fail("The response body is not valid JSON ({$e->getMessage()}):\n" . $this->excerpt());
            }
            $this->decoded = true;
        }

        return $this->json;
    }

    /**
     * The JSON value at $path; a missing path fails the test.
     */
    public function jsonPath(string $path): mixed
    {
        $value = $this->json();
        foreach ($path === '' ? [] : explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                Assert::fail("The JSON response has no value at \"{$path}\":\n" . $this->excerpt());
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->status(), "Expected status {$status}, got {$this->status()}:\n" . $this->excerpt());

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertNoContent(): self
    {
        return $this->assertStatus(204);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertUnprocessable(): self
    {
        return $this->assertStatus(422);
    }

    /**
     * The header is present, and equals $value when given.
     */
    public function assertHeader(string $name, ?string $value = null): self
    {
        Assert::assertTrue($this->response->hasHeader($name), "The response has no {$name} header.");
        if ($value !== null) {
            Assert::assertSame($value, $this->header($name), "Unexpected {$name} header.");
        }

        return $this;
    }

    /**
     * The JSON body contains $subset: every key it lists, recursively, with
     * the same values.
     *
     * @param array<mixed> $subset
     */
    public function assertJson(array $subset): self
    {
        $actual = $this->json();
        $mismatch = self::mismatch($subset, $actual, '');
        Assert::assertNull($mismatch, "The JSON response does not contain the expected subset: {$mismatch}\n" . $this->excerpt());

        return $this;
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        Assert::assertSame($expected, $this->jsonPath($path), "Unexpected JSON value at \"{$path}\".");

        return $this;
    }

    /**
     * @param array<mixed> $subset
     */
    private static function mismatch(array $subset, mixed $actual, string $path): ?string
    {
        if (!is_array($actual)) {
            return ($path === '' ? 'the body' : "\"{$path}\"") . ' is not an object or array';
        }

        foreach ($subset as $key => $expected) {
            $at = $path === '' ? (string) $key : "{$path}.{$key}";
            if (!array_key_exists($key, $actual)) {
                return "\"{$at}\" is missing";
            }
            if (is_array($expected) && $expected !== []) {
                $inner = self::mismatch($expected, $actual[$key], $at);
                if ($inner !== null) {
                    return $inner;
                }
            } elseif ($expected !== $actual[$key]) {
                return "\"{$at}\" is " . json_encode($actual[$key]) . ', expected ' . json_encode($expected);
            }
        }

        return null;
    }

    private function excerpt(): string
    {
        $body = $this->body();

        return strlen($body) > 2000 ? substr($body, 0, 2000) . '…' : $body;
    }
}
