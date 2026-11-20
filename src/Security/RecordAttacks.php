<?php

declare(strict_types=1);

namespace Zana\Testing\Security;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zana\Core\ApplicationInterface;
use Zana\Http\HttpKernel;
use Zana\Routing\Route;
use Zana\Routing\Router;
use Zana\Security\CsrfTokens;

/**
 * One user must not reach another user's records (2.27): the attack the route
 * attacks of 2.22 leave to the application, because only the application
 * knows who owns what. It says so with two sign-ins and a way to make records:
 *
 *     (new RecordAttacks($app, owner: $ada, other: $grace, records: ['id' => fn () => $this->orderOf('ada')]))->run();
 *
 * For every route with a parameter named in $records, a fresh record of the
 * owner's is made, then:
 *
 * 1. the other user's request must be refused: 401, 403 or 404, nothing else,
 *    so a page that shows the record or a redirect after a change fails;
 * 2. the owner's same request must get through (anything but 401, 403, 404 or
 *    a server error), which proves the refusal was about the owner, not a
 *    record that does not exist or a sign-in that does not work.
 *
 * An application may check a write's input before its owner: when both users
 * get the same 400 or 422, nothing is known, and the route is reported as not
 * proven until bodies() gives it input the owner's request succeeds with.
 * Another user's 400 or 422 where the owner, with the same body, gets through
 * fails: the answer depended on who asked.
 *
 * GET by default; with writes(), POST, PUT, PATCH and DELETE too, with a
 * valid CSRF token when mj-zana/security makes them, so CSRF cannot refuse
 * them first. Requests ask as a browser does, with form bodies; json() asks
 * as an API client does, with JSON bodies. Every route is tried, public ones included: a public route can
 * leak a record as well. A route whose other parameters have no record is
 * reported as not attacked.
 */
final class RecordAttacks
{
    private const array REFUSED = [401, 403, 404];

    private const array WRITES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Answers about a request's input, which an application may give before it checks the owner. */
    private const array INVALID = [400, 422];

    /** @var array<string, string> */
    private array $except = [];

    /** @var array<string, array<string, mixed>> */
    private array $bodies = [];

    private bool $writes = false;

    private bool $json = false;

    /**
     * @param \Closure(ServerRequestInterface): ServerRequestInterface $owner Signs the owner of the records in
     * @param \Closure(ServerRequestInterface): ServerRequestInterface $other Signs in another user, who owns none of them
     * @param array<string, \Closure(): (int|string|array<string, int|string>)> $records By route parameter: makes a fresh
     *        record of the owner's and gives its id, or the ids of several parameters, e.g. ['project' => 7, 'task' => 12]
     */
    public function __construct(
        private readonly ApplicationInterface $application,
        private readonly \Closure $owner,
        private readonly \Closure $other,
        private readonly array $records,
    ) {
        if ($records === []) {
            throw new \InvalidArgumentException('Name at least one route parameter in records, with how to make a record of the owner\'s.');
        }
    }

    /**
     * Try POST, PUT, PATCH and DELETE too. Each attempt gets a fresh record,
     * so a deletion does not change the next one.
     */
    public function writes(bool $writes = true): self
    {
        $this->writes = $writes;

        return $this;
    }

    /**
     * Ask as an API client does: Accept application/json, and the writes'
     * bodies as JSON, with the CSRF token, if any, in the X-CSRF-Token header.
     * By default, as a browser does: HTML, and form bodies.
     */
    public function json(bool $json = true): self
    {
        $this->json = $json;

        return $this;
    }

    /**
     * A valid body for a write, by route, so the owner's request gets through
     * where an empty one would be refused for its input. Without one, when
     * both users get the same 400 or 422, the route is reported as not proven:
     * the application checks the input before the owner.
     *
     * @param array<string, array<string, mixed>> $bodies "PUT /orders/{id}" => ['note' => 'Leave at the door']
     */
    public function bodies(array $bodies): self
    {
        $this->bodies = $bodies;

        return $this;
    }

    /**
     * Leave routes out, each with the reason, which the report shows, e.g. a
     * route that shares a record on purpose by its link.
     *
     * @param array<string, string> $except "GET /orders/{id}/receipt" => 'Shared by link with the buyer\'s accountant'
     */
    public function except(array $except): self
    {
        foreach ($except as $route => $reason) {
            if (trim($reason) === '') {
                throw new \InvalidArgumentException("Leaving {$route} out of the attacks needs a reason.");
            }
        }
        $this->except = $except;

        return $this;
    }

    public function run(): AttackReport
    {
        $report = new AttackReport();
        $container = $this->application->container();
        if (!class_exists(Router::class) || !$container->has(Router::class)) {
            return $report;
        }
        $router = $container->get(Router::class);
        $kernel = $container->get(HttpKernel::class);
        assert($router instanceof Router && $kernel instanceof HttpKernel);
        $csrf = class_exists(CsrfTokens::class) && $container->has(CsrfTokens::class) ? $container->get(CsrfTokens::class) : null;

        $known = [];
        foreach ($router->routes() as $route) {
            foreach ($route->methods as $method) {
                $known["{$method} {$route->path}"] = true;
            }
        }
        foreach (array_keys($this->except) as $name) {
            if (!isset($known[$name])) {
                throw new \InvalidArgumentException("Leaving {$name} out of the attacks: no route is called so. Name it as the report does, e.g. \"GET /orders/{id}\".");
            }
        }

        $send = function (ServerRequestInterface $request) use ($kernel, $report): ?ResponseInterface {
            $report->requests++;
            try {
                return $kernel->handle($request);
            } catch (\Throwable) {
                return null;
            }
        };

        $candidates = 0;   // attempts on a route with a record parameter
        $proven = 0;       // attempts where the owner got through, so the other's refusal meant something
        foreach ($router->routes() as $route) {
            $parameters = self::parameters($route);
            $named = array_values(array_intersect($parameters, array_keys($this->records)));
            if ($named === []) {
                continue;
            }
            $methods = array_values(array_filter(
                $route->methods,
                fn (string $method): bool => $method === 'GET' || ($this->writes && in_array($method, self::WRITES, true)),
            ));
            if ($methods === []) {
                continue;
            }
            $report->routes++;

            foreach ($methods as $method) {
                $name = "{$method} {$route->path}";
                if (isset($this->except[$name])) {
                    $report->excluded[$name] = $this->except[$name];
                    continue;
                }
                $candidates++;   // left out on purpose, it is no attempt
                $values = $this->makeRecords($named, $report, $name);
                if ($values === null) {
                    continue;
                }
                $missing = array_values(array_diff($parameters, array_keys($values)));
                if ($missing !== []) {
                    $report->skip($name, 'no record for {' . implode('}, {', $missing) . '}: name ' . (count($missing) === 1 ? 'it' : 'them') . ' in records');
                    continue;
                }
                $path = $route->buildPath($values);
                if ($route->matchPath($path) === null) {
                    $report->skip($name, "the record's id ({$path}) does not match the route's patterns");
                    continue;
                }

                $body = $this->bodies[$name] ?? [];
                $request = fn (): ServerRequestInterface => self::request($method, $path, $csrf, $body, $this->json);
                $other = $send(($this->other)($request()));
                $owner = $send(($this->owner)($request()));   // after: a write by the owner may change the record

                if ($other === null) {
                    $report->fail($name, "another user's record", 'the kernel threw instead of answering.');
                    continue;
                }
                if ($owner === null || $owner->getStatusCode() >= 500) {
                    $report->fail($name, "the owner's own record", $owner === null ? 'the kernel threw instead of answering.' : "answered {$owner->getStatusCode()}.");
                    continue;
                }
                $ownerReached = !in_array($owner->getStatusCode(), self::REFUSED, true);
                if (in_array($other->getStatusCode(), self::INVALID, true) && $owner->getStatusCode() === $other->getStatusCode()) {
                    // Both refused for their input: the application checks it before the owner, and nothing is known yet.
                    $report->skip($name, "both users got {$other->getStatusCode()}, an answer about the input, before any about the owner: give a body the owner's request succeeds with, in bodies");
                    continue;
                }
                if (!in_array($other->getStatusCode(), self::REFUSED, true)) {
                    $report->fail($name, "another user's record", "answered {$other->getStatusCode()} to a user who does not own it"
                        . ($other->getStatusCode() >= 300 && $other->getStatusCode() < 400 ? ' (a redirect, so the request was taken)' : '')
                        . ': refuse it with 403, or 404 so its existence is not told.');
                    continue;
                }
                if (!$ownerReached) {
                    $report->skip($name, "the owner was refused too ({$owner->getStatusCode()}), so the other's refusal proves nothing: check the owner's sign-in, and that the record exists");
                    continue;
                }
                $proven++;
            }
        }

        if ($candidates > 0 && $proven === 0 && $report->failures === []) {
            $report->fail('records', 'the owner function', "none of the {$candidates} attempt(s) proved anything: in none did the owner get through to their own record while the other user was refused. See the routes not attacked, and check the owner's sign-in and the records made.");
        }

        return $report;
    }

    /**
     * @param list<string> $named
     * @return array<string, string>|null
     */
    private function makeRecords(array $named, AttackReport $report, string $route): ?array
    {
        $values = [];
        foreach ($named as $parameter) {
            try {
                $made = ($this->records[$parameter])();
            } catch (\Throwable $e) {
                $report->fail($route, 'making a record', "records['{$parameter}'] threw " . $e::class . ': ' . $e->getMessage());

                return null;
            }
            foreach (is_array($made) ? $made : [$parameter => $made] as $key => $value) {
                $values[(string) $key] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private static function parameters(Route $route): array
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)/', $route->path, $matches);

        return $matches[1];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function request(string $method, string $path, ?object $csrf, array $body = [], bool $json = false): ServerRequestInterface
    {
        $request = new ServerRequest($method, 'http://localhost' . $path, ['Accept' => $json ? 'application/json' : 'text/html'], null, '1.1', ['REMOTE_ADDR' => '198.51.100.7']);
        if ($method === 'GET') {
            return $request;
        }
        if ($json) {
            // An API reads the body as JSON; a CSRF token, for one signed in by cookie, goes in its header.
            if ($csrf instanceof CsrfTokens) {
                $request = $request->withHeader(CsrfTokens::HEADER, $csrf->issue());
            }

            return $request->withHeader('Content-Type', 'application/json')
                ->withBody(Stream::create(json_encode((object) $body, JSON_THROW_ON_ERROR)))
                ->withParsedBody($body);
        }
        $fields = [...$body, ...($csrf instanceof CsrfTokens ? [CsrfTokens::FIELD => $csrf->issue(), CsrfTokens::FORM_FIELD => CsrfTokens::DEFAULT_FORM] : [])];

        return $request->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody(Stream::create(http_build_query($fields)))
            ->withParsedBody($fields);
    }
}
