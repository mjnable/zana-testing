<?php

declare(strict_types=1);

namespace Zana\Testing\Security;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Zana\Configuration\ConfigurationInterface;
use Zana\Core\ApplicationInterface;
use Zana\Http\HttpKernel;
use Zana\Routing\Route;
use Zana\Routing\Router;
use Zana\Security\Inspection\RouteAccess;
use Zana\Testing\Security\Internal\HandlerProbe;

/**
 * Attacks every route of the application in memory (2.22, FE-68, layer 2 of
 * ADR-033), so a new route is attacked as soon as it exists:
 *
 * - a route that needs a user: an anonymous request, and one with an
 *   expired session, must not reach its handler. This also tests an
 *   application's own sign-in middleware declared in security.access_middleware;
 * - a public route that changes data behind CsrfMiddleware: a missing token, a
 *   forged one, and a cross-site Origin must not reach its handler;
 * - a route with #[RateLimit]: the limit plus one request must not all reach it;
 * - every route anyone may reach: script and injection payloads, a list where a
 *   value is expected, and a 2 MB body must not cause a server error, and an
 *   HTML page must not print a script payload as it is.
 *
 * Who may reach a route is read with mj-zana/security's RouteAccess, as the
 * security rules of `check` read it; without mj-zana/security, only the last
 * attack runs. A request is known to reach the handler because a probe is the
 * route's last middleware; for the access and CSRF attacks it answers itself,
 * so the handler never runs and nothing is written.
 *
 * Not covered, and why: attacks as a signed-in user, such as CSRF on a route
 * that needs one, or one user reaching another's data. How to sign a user in
 * belongs to the application, and so do its permissions.
 */
final class RouteAttacks
{
    /** Kept under PHP's own limits, and above any form a person fills in. */
    private const int OVERSIZED_BYTES = 2 * 1024 * 1024;

    /** Higher limits are not tested in memory. */
    private const int MAX_RATE_LIMIT = 200;

    private const string MARKER = 'zana-attack-7f3c';

    /** @var array<string, string> label => value */
    private const array PAYLOADS = [
        'a script' => "<script>alert('" . self::MARKER . "')</script>",
        'an attribute breakout' => "\"><img src=x onerror=alert('" . self::MARKER . "')>",
        'SQL' => "' OR '1'='1' -- " . self::MARKER,
        'a template expression' => '{{7*7}}${7*7}<%= 7*7 %>' . self::MARKER,
        'a path traversal' => '../../../../etc/passwd',
        'a NUL byte' => "a\0b",
        'a long value' => self::MARKER,   // made 10,000 characters long below
    ];

    /** Field and query names an application commonly reads. */
    private const array FIELDS = ['q', 'search', 'name', 'email', 'message', 'page', 'id', 'to', 'title'];

    /** @var array<string, string> */
    private array $except = [];

    public function __construct(private readonly ApplicationInterface $application)
    {
    }

    /**
     * Leave routes out, each with the reason, which the report shows.
     *
     * @param array<string, string> $except "GET /webhook" => 'Signed by the payment provider'
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
        $config = $container->get(ConfigurationInterface::class);
        assert($router instanceof Router && $kernel instanceof HttpKernel && $config instanceof ConfigurationInterface);

        $global = array_values(array_filter($config->array('http.middleware', []), is_string(...)));
        $security = class_exists(RouteAccess::class);
        $declared = $security ? RouteAccess::declaredMiddleware($config) : [];
        $cookie = $config->has('session.cookie') ? $config->string('session.cookie') : 'zana_session';

        $known = [];
        foreach ($router->routes() as $route) {
            foreach (self::names($route) as $name) {
                $known[$name] = true;
            }
        }
        foreach (array_keys($this->except) as $name) {
            if (!isset($known[$name])) {
                throw new \InvalidArgumentException("Leaving {$name} out of the attacks: no route is called so. Name it as the report does, e.g. \"POST /contact\".");
            }
        }

        foreach ($router->routes() as $route) {
            $methods = array_values(array_diff($route->methods, ['HEAD', 'OPTIONS']));
            if ($methods === []) {
                continue;
            }
            $name = self::names($route)[0];
            foreach (self::names($route) as $alias) {
                if (isset($this->except[$alias])) {
                    $report->excluded[$name] = $this->except[$alias];
                    continue 2;
                }
            }
            $path = self::path($route);
            if ($path === null) {
                $report->skip($name, 'no sample value matches the patterns of its parameters');
                continue;
            }

            $probe = new HandlerProbe();
            $route->middleware($probe);
            $report->routes++;
            $access = $security ? RouteAccess::of($route, $global, $declared) : null;
            $send = function (ServerRequest $request) use ($kernel, $report): ?ResponseInterface {
                $report->requests++;
                try {
                    return $kernel->handle($request);
                } catch (\Throwable $e) {
                    return null;   // reported by the caller
                }
            };

            if ($access !== null && $access->claimsUser()) {   // what it says, tested against what it does
                $probe->stop = true;
                foreach ($methods as $method) {
                    $attacks = ['an anonymous request' => self::request($method, $path)];
                    if ($access->sessions || $access->application !== null) {
                        $attacks['an expired session'] = self::request($method, $path)->withCookieParams([$cookie => bin2hex(random_bytes(32))]);
                    }
                    foreach ($attacks as $label => $request) {
                        $before = $probe->hits;
                        $response = $send($request);
                        if ($probe->hits > $before) {
                            $report->fail("{$method} {$route->path}", $label, 'reached the handler, although the route needs a user' . match (true) {
                                $access->application !== null => " ({$access->application} is declared to refuse anonymous visitors)",
                                !$access->authorized => ' (AuthorizationMiddleware is not on the route, so nothing enforces its access rule)',
                                default => '',
                            } . '.');
                        } elseif ($response === null) {
                            $report->fail("{$method} {$route->path}", $label, 'the kernel threw instead of answering.');
                        }
                    }
                }
                $probe->stop = false;   // from here on the route behaves as before
                continue;   // signing in belongs to the application: nothing more to attack here
            }

            if ($access !== null && $access->public !== null && $access->changesData && $access->csrfProtected) {
                $probe->stop = true;
                $method = self::unsafe($methods);
                foreach ([
                    'no CSRF token' => [[], []],
                    'a forged CSRF token' => [['_token' => 'forged.' . self::MARKER, '_form' => 'default'], []],
                    'a cross-site request' => [['_token' => 'forged.' . self::MARKER, '_form' => 'default'], ['Origin' => 'https://attacker.example', 'Sec-Fetch-Site' => 'cross-site']],
                ] as $label => [$fields, $headers]) {
                    $before = $probe->hits;
                    $send(self::form($method, $path, $fields, $headers));
                    if ($probe->hits > $before) {
                        $report->fail("{$method} {$route->path}", $label, 'reached the handler: CsrfMiddleware did not refuse it.');
                    }
                }
            }

            if ($access !== null && $access->rateLimit !== null) {
                $max = $access->rateLimit->max;
                if ($max > self::MAX_RATE_LIMIT) {
                    $report->skip($name, "its rate limit, {$max} requests, is above what is attacked in memory (" . self::MAX_RATE_LIMIT . ')');
                } else {
                    $probe->stop = true;
                    $method = $methods[0];
                    $before = $probe->hits;
                    for ($i = 0; $i <= $max; $i++) {
                        $send(self::form($method, $path, [], [], '198.51.100.250'));
                    }
                    if ($probe->hits - $before > $max) {
                        $report->fail("{$method} {$route->path}", 'repeated requests', ($probe->hits - $before) . " of " . ($max + 1) . " requests reached the handler; #[RateLimit] allows {$max}.");
                    }
                }
            }

            // Hostile input, with the handler running: anyone may send it.
            $probe->stop = false;
            foreach ($methods as $method) {
                foreach (self::hostile($method, $path) as $label => $request) {
                    $response = $send($request);
                    $where = "{$method} {$route->path}";
                    if ($response === null) {
                        $report->fail($where, $label, 'the kernel threw instead of answering.');
                    } elseif ($response->getStatusCode() >= 500) {
                        $report->fail($where, $label, "answered {$response->getStatusCode()}: hostile input caused a server error.");
                    } elseif (str_contains(strtolower($response->getHeaderLine('Content-Type')), 'html') && str_contains((string) $response->getBody(), "<script>alert('" . self::MARKER . "')")) {
                        $report->fail($where, $label, 'the page prints the script as it was sent: a visitor could be made to run it.');
                    }
                }
            }
        }

        return $report;
    }

    /**
     * "GET /x" for each method, and "GET|POST /x" as check prints it.
     *
     * @return list<string>
     */
    private static function names(Route $route): array
    {
        $names = array_map(static fn (string $method): string => "{$method} {$route->path}", $route->methods);

        return [...$names, implode('|', $route->methods) . " {$route->path}"];
    }

    /**
     * The path with a sample value for each parameter that its pattern accepts, or null.
     */
    private static function path(Route $route): ?string
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)/', $route->path, $matches);
        if ($matches[1] === []) {
            return $route->path;
        }
        $values = [];
        $patterns = $route->patterns();
        foreach ($matches[1] as $parameter) {
            // A pattern that lists its values, such as "news|services", gives its own samples first.
            $listed = array_values(array_filter(
                explode('|', trim((string) ($patterns[$parameter] ?? ''), '^$()?:')),
                static fn (string $value): bool => preg_match('/^[A-Za-z0-9_.~-]+$/', $value) === 1,
            ));
            foreach ([...$listed, '1', 'test', '00000000-0000-4000-8000-000000000000', 'en', 'a1', str_repeat('a', 64)] as $sample) {
                $values[$parameter] = $sample;
                $path = $route->buildPath($values + array_fill_keys($matches[1], $sample));
                if ($route->matchPath($path) !== null) {
                    continue 2;
                }
            }

            return null;
        }
        $path = $route->buildPath($values);

        return $route->matchPath($path) === null ? null : $path;
    }

    /**
     * @param list<string> $methods
     */
    private static function unsafe(array $methods): string
    {
        foreach ($methods as $method) {
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                return $method;
            }
        }

        return $methods[0];
    }

    /**
     * @return array<string, ServerRequest>
     */
    private static function hostile(string $method, string $path): array
    {
        $requests = [];
        foreach (self::PAYLOADS as $label => $value) {
            $value = $label === 'a long value' ? str_repeat($value, (int) ceil(10_000 / strlen($value))) : $value;
            $fields = array_fill_keys(self::FIELDS, $value);
            if ($method === 'GET' || $method === 'DELETE') {
                $requests["{$label} in the query"] = self::request($method, $path . '?' . http_build_query($fields))->withQueryParams($fields);
            } else {
                $requests["{$label} in a form"] = self::form($method, $path, $fields);
                $requests["{$label} in JSON"] = self::json($method, $path, $fields);
            }
        }
        $lists = array_fill_keys(self::FIELDS, ['a' => ['b' => self::MARKER]]);
        if ($method === 'GET' || $method === 'DELETE') {
            $requests['a list where a value is expected'] = self::request($method, $path . '?' . http_build_query($lists))->withQueryParams($lists);
        } else {
            $requests['a list where a value is expected'] = self::form($method, $path, $lists);
            $requests['a 2 MB body'] = self::form($method, $path, ['message' => str_repeat('x', self::OVERSIZED_BYTES)]);
            $requests['a body that is not JSON'] = self::request($method, $path, ['Content-Type' => 'application/json'])->withBody(Stream::create('{"' . self::MARKER));
        }

        return $requests;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(string $method, string $uri, array $headers = [], string $address = '198.51.100.7'): ServerRequest
    {
        return new ServerRequest($method, 'http://localhost' . $uri, ['Accept' => 'text/html', ...$headers], null, '1.1', ['REMOTE_ADDR' => $address]);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $headers
     */
    private static function form(string $method, string $path, array $fields, array $headers = [], string $address = '198.51.100.7'): ServerRequest
    {
        return self::request($method, $path, ['Content-Type' => 'application/x-www-form-urlencoded', ...$headers], $address)
            ->withBody(Stream::create(http_build_query($fields)))
            ->withParsedBody($fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function json(string $method, string $path, array $fields): ServerRequest
    {
        return self::request($method, $path, ['Content-Type' => 'application/json', 'Accept' => 'application/json'])
            ->withBody(Stream::create((string) json_encode($fields, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)))
            ->withParsedBody($fields);
    }
}
