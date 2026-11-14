<?php

declare(strict_types=1);

namespace Zana\Testing\Security;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
 * As a signed-in user too, when signIn() says how to sign one in (a user
 * without roles): a route that needs a permission must refuse them; a route
 * that needs only a user gets the CSRF attacks (unless the sign-in is a bearer
 * token, which CSRF cannot reach) and the hostile input. When no signed-in
 * request gets through to any such route, the sign-in itself fails.
 *
 * Not covered, and why: one user reaching another's data. Which records
 * belong to whom is the application's knowledge, for its own tests.
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

    /** @var (\Closure(ServerRequestInterface): ServerRequestInterface)|null */
    private ?\Closure $signIn = null;

    /** @var list<string> The permissions the signed-in user holds */
    private array $holding = [];

    public function __construct(private readonly ApplicationInterface $application)
    {
    }

    /**
     * Attack as a signed-in user too (2.22): $signIn turns an anonymous request
     * into one of a user of the application, e.g. by adding a bearer token or
     * a session cookie. $holding names the permissions that user holds; a
     * route that needs another must refuse them. Sign in the user with the
     * fewest permissions, ideally none, so that most routes are tried both ways.
     *
     * @param \Closure(ServerRequestInterface): ServerRequestInterface $signIn
     * @param list<string> $holding
     */
    public function signIn(\Closure $signIn, array $holding = []): self
    {
        $this->signIn = $signIn;
        $this->holding = $holding;

        return $this;
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

        $signedInReached = false;   // a signed-in request got through to a route that needs only a user
        $userOnlyRoutes = 0;
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
            $send = function (ServerRequestInterface $request) use ($kernel, $report): ?ResponseInterface {
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
                            } . '.' . ($access->public === null ? '' : ' It also says it is public: if its handler checks access itself, such as a token in the link, move it to a class without #[RequiresAuthentication] or #[RequiresPermission].'));
                        } elseif ($response === null) {
                            $report->fail("{$method} {$route->path}", $label, 'the kernel threw instead of answering.');
                        }
                    }
                }
                if ($this->signIn !== null) {
                    $this->attackSignedIn($report, $send, $probe, $access, $route, $path, $methods, $signedInReached, $userOnlyRoutes);
                }
                $probe->stop = false;   // from here on the route behaves as before
                continue;
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
            self::attackWithHostileInput($report, $send, $route, $path, $methods, null);
        }

        if ($this->signIn !== null && $userOnlyRoutes > 0 && !$signedInReached) {
            $report->fail('sign-in', 'the signIn function', "no signed-in request reached any of the {$userOnlyRoutes} route(s) the signed-in user may reach, so it does not sign a user in, or holding names a permission the user does not hold, and the signed-in attacks proved nothing.");
        }

        return $report;
    }

    /**
     * The attacks as a signed-in user, without roles, on a route that says it needs a user.
     *
     * @param \Closure(ServerRequestInterface): ?ResponseInterface $send
     * @param list<string> $methods
     */
    private function attackSignedIn(AttackReport $report, \Closure $send, HandlerProbe $probe, RouteAccess $access, Route $route, string $path, array $methods, bool &$reached, int &$userOnlyRoutes): void
    {
        $signIn = $this->signIn ?? throw new \LogicException('No signIn function.');
        $bearer = str_starts_with(strtolower($signIn(self::request('GET', $path))->getHeaderLine('Authorization')), 'bearer ');

        // A permission the user does not hold: the route must refuse them.
        $missing = array_values(array_diff($access->permissions, $this->holding));
        if ($missing !== []) {
            $probe->stop = true;
            foreach ($methods as $method) {
                $before = $probe->hits;
                $send($signIn(in_array($method, ['GET', 'DELETE'], true) ? self::request($method, $path) : self::form($method, $path, [])));
                if ($probe->hits > $before) {
                    $report->fail("{$method} {$route->path}", 'a signed-in user without the permission', 'reached the handler, although the route needs ' . implode(', ', $missing) . ', which the signed-in user does not hold'
                        . ($this->holding === [] ? '' : ' (it holds ' . implode(', ', $this->holding) . ')') . '. If it does hold it, name it in holding.');
                }
            }

            return;
        }

        // The route needs only a user, or permissions the user holds: a signed-in request must get through, or the
        // attacks prove nothing. Behind
        // CSRF, only a request that changes nothing, or a bearer token, is expected to get through.
        $open = $access->csrfProtected && !$bearer ? array_values(array_intersect($methods, ['GET'])) : $methods;
        if ($open !== []) {
            $userOnlyRoutes++;
            $probe->stop = true;
            $before = $probe->hits;
            $send($signIn(self::request($open[0], $path)));
            if ($probe->hits > $before) {
                $reached = true;
            } elseif ($access->permissions !== []) {
                $report->fail("{$open[0]} {$route->path}", 'the permissions in holding', 'the signed-in user was refused, although holding says it holds ' . implode(', ', $access->permissions) . ', which the route needs: holding names a permission the user does not hold, or the route refuses one it should allow.');
            }
        }

        if ($access->changesData && $access->csrfProtected) {
            if ($bearer) {
                $report->skip("{$route->path}", 'not attacked for CSRF as a signed-in user: the signIn function uses a bearer token, which CSRF cannot reach');
            } else {
                $method = self::unsafe($methods);
                foreach ([
                    'no CSRF token, signed in' => [[], []],
                    'a forged CSRF token, signed in' => [['_token' => 'forged.' . self::MARKER, '_form' => 'default'], []],
                    'a cross-site request, signed in' => [['_token' => 'forged.' . self::MARKER, '_form' => 'default'], ['Origin' => 'https://attacker.example', 'Sec-Fetch-Site' => 'cross-site']],
                ] as $label => [$fields, $headers]) {
                    $before = $probe->hits;
                    $send($signIn(self::form($method, $path, $fields, $headers)));
                    if ($probe->hits > $before) {
                        $report->fail("{$method} {$route->path}", $label, 'reached the handler: CsrfMiddleware did not refuse a request another site could make the user send.');
                    }
                }
            }
        }

        // Hostile input from a signed-in user, with the handler running. Behind CSRF, a signed-in session's hostile
        // form would be refused before the handler, so only the methods that change nothing, or a bearer token, run.
        $probe->stop = false;
        $hostileMethods = $access->csrfProtected && !$bearer
            ? array_values(array_filter($methods, static fn (string $m): bool => in_array($m, ['GET'], true)))
            : $methods;
        self::attackWithHostileInput($report, $send, $route, $path, $hostileMethods, $signIn, ', signed in');
    }

    /**
     * An ordinary request first, then every hostile one, with the handler running.
     *
     * @param \Closure(ServerRequestInterface): ?ResponseInterface $send
     * @param list<string> $methods
     * @param (\Closure(ServerRequestInterface): ServerRequestInterface)|null $signIn
     */
    private static function attackWithHostileInput(AttackReport $report, \Closure $send, Route $route, string $path, array $methods, ?\Closure $signIn, string $as = ''): void
    {
        $wrap = $signIn ?? static fn (ServerRequestInterface $request): ServerRequestInterface => $request;
        foreach ($methods as $method) {
            // An ordinary request first: if it already fails, hostile input is not the cause.
            $ordinary = $send($wrap(in_array($method, ['GET', 'DELETE'], true) ? self::request($method, $path) : self::form($method, $path, [])));
            if ($ordinary === null || $ordinary->getStatusCode() >= 500) {
                $report->fail("{$method} {$route->path}", 'an ordinary request' . $as, ($ordinary === null ? 'the kernel threw' : "answered {$ordinary->getStatusCode()}") . " to {$method} {$path}, before any hostile input, so hostile input was not tried. "
                    . ($path === $route->path
                        ? 'Check that the test environment has what the route needs, such as a service it calls.'
                        : 'A value in its path that the application does not know should be answered with a 4xx, such as 404, not a server error; or the test environment lacks what the route needs.'));
                continue;
            }
            foreach (self::hostile($method, $path) as $label => $request) {
                $request = $wrap($request);
                $label .= $as;
                $warnings = [];
                $previous = null;
                $previous = set_error_handler(function (int $level, string $message, string $file, int $line) use (&$warnings, &$previous): bool {
                    // Even one silenced with @: hostile input reached code that did not expect it. error_reporting()
                    // cannot tell, as test runners change it.
                    if (!str_contains(str_replace('\\', '/', $file), '/vendor/')) {
                        $warnings[] = "{$message} (" . basename($file) . ":{$line})";
                    }

                    return is_callable($previous) ? (bool) $previous($level, $message, $file, $line) : true;   // reported here, not printed
                }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
                try {
                    $response = $send($request);
                } finally {
                    restore_error_handler();
                }
                $where = "{$method} {$route->path}";
                if ($warnings !== []) {
                    $report->fail($where, $label, 'caused a PHP warning in the application: ' . implode('; ', array_unique($warnings)) . '. Check the type of what the request sends before using it.');
                }
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
