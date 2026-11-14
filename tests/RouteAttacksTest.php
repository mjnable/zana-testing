<?php

declare(strict_types=1);

namespace Zana\Testing\Tests;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Core\ApplicationBuilder;
use Zana\Core\ApplicationInterface;
use Zana\Http\HttpServiceProvider;
use Zana\Routing\Attribute\PublicRoute;
use Zana\Routing\Router;
use Zana\Routing\RoutingServiceProvider;
use Zana\Security\Attribute\RateLimit;
use Zana\Security\Attribute\RequiresAuthentication;
use Zana\Security\Middleware\AuthorizationMiddleware;
use Zana\Security\Middleware\CsrfMiddleware;
use Zana\Security\Middleware\RateLimitMiddleware;
use Zana\Security\SecurityServiceProvider;
use Zana\Testing\ApplicationTestCase;
use Zana\Testing\Security\RouteAttacks;

/**
 * 2.22, FE-68 layer 2: every route attacked in memory. Each attack is shown to
 * catch a route that fails it, and to pass a route that withstands it.
 */
final class RouteAttacksTest extends TestCase
{
    public function testRoutesThatWithstandTheAttacksPass(): void
    {
        $report = (new RouteAttacks($this->app(static function (Router $r): void {
            $r->get('/me', #[RequiresAuthentication] static fn (): string => 'private')->middleware(AuthorizationMiddleware::class);
            $r->get('/manage', static fn (): string => 'back office')->middleware(SignsInForReal::class);
            $r->post('/contact', #[PublicRoute(reason: 'The contact form')] static fn (): string => 'sent')->middleware(CsrfMiddleware::class);
            $r->post('/tokens', #[PublicRoute(reason: 'Signing in')] #[RateLimit(max: 3, per: 60)] static fn (): string => 'token')->middleware(RateLimitMiddleware::class);
            $r->get('/search', #[PublicRoute(reason: 'Search')] static fn (ServerRequestInterface $request): ResponseInterface => new Response(200, ['Content-Type' => 'text/html'], '<p>' . htmlspecialchars(self::text($request->getQueryParams()['q'] ?? '')) . '</p>'));
            $r->get('/books/{id:\d+}', #[PublicRoute(reason: 'The catalogue')] static fn (int $id): string => "book {$id}");
            $r->get('/admin/{section}', #[RequiresAuthentication] static fn (string $section): string => $section)->where('section', 'news|services')->middleware(AuthorizationMiddleware::class);
        })))->run();

        $this->assertTrue($report->passed(), $report->describe());
        $this->assertSame(7, $report->routes);
        $this->assertSame([], $report->skipped, 'a pattern that lists its values gives the sample');
        $this->assertGreaterThan(40, $report->requests);
    }

    public function testAnonymousRequestsThatReachAProtectedHandlerFail(): void
    {
        // The application declares a sign-in middleware that does not refuse anyone: layer 2 tests the claim.
        $report = (new RouteAttacks($this->app(static function (Router $r): void {
            $r->get('/manage', static fn (): string => 'back office')->middleware(SignsInNobody::class);
        }, ['security.access_middleware' => [SignsInNobody::class => 'Claims to send visitors to sign in']])))->run();

        $this->assertSame([
            ['route' => 'GET /manage', 'attack' => 'an anonymous request', 'detail' => 'reached the handler, although the route needs a user (' . SignsInNobody::class . ' is declared to refuse anonymous visitors).'],
            ['route' => 'GET /manage', 'attack' => 'an expired session', 'detail' => 'reached the handler, although the route needs a user (' . SignsInNobody::class . ' is declared to refuse anonymous visitors).'],
        ], $report->failures);
        $this->assertStringContainsString('✘ GET /manage — an anonymous request', $report->describe());
    }

    public function testAnAccessRuleNothingEnforcesFails(): void
    {
        $report = (new RouteAttacks($this->app(static fn (Router $r) => $r->get('/admin', #[RequiresAuthentication] static fn (): string => 'payroll'))))->run();

        $this->assertSame('reached the handler, although the route needs a user (AuthorizationMiddleware is not on the route, so nothing enforces its access rule).', $report->failures[0]['detail']);
        $this->assertSame('an anonymous request', $report->failures[0]['attack']);
    }

    public function testHostileInputThatBreaksOrReflectsFails(): void
    {
        $report = (new RouteAttacks($this->app(static function (Router $r): void {
            $r->get('/echo', #[PublicRoute(reason: 'Echo')] static fn (ServerRequestInterface $request): ResponseInterface => new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<p>' . self::text($request->getQueryParams()['q'] ?? '') . '</p>'));
            $r->post('/strict', #[PublicRoute(reason: 'A form')] static function (ServerRequestInterface $request): string {
                $body = (array) $request->getParsedBody();

                return strtoupper($body['name'] ?? '');   // a list makes this a TypeError, a 500
            });
        })))->run();

        $byRoute = [];
        foreach ($report->failures as $failure) {
            $byRoute[$failure['route']][] = $failure['attack'] . ': ' . $failure['detail'];
        }
        $this->assertSame(['a script in the query: the page prints the script as it was sent: a visitor could be made to run it.'], $byRoute['GET /echo']);
        $this->assertContains('a list where a value is expected: answered 500: hostile input caused a server error.', $byRoute['POST /strict']);
    }

    public function testARateLimitThatDoesNotHoldFails(): void
    {
        // A cache that forgets every count: the limiter lets every request through.
        $app = self::builder(static function (Router $r): void {
            $r->post('/tokens', #[PublicRoute(reason: 'Signing in')] #[RateLimit(max: 3, per: 60)] static fn (): string => 'token')->middleware(RateLimitMiddleware::class);
        }, [], new ForgetfulCache())->build();

        $report = (new RouteAttacks($app))->run();

        $this->assertContains(['route' => 'POST /tokens', 'attack' => 'repeated requests', 'detail' => '4 of 4 requests reached the handler; #[RateLimit] allows 3.'], $report->failures);
    }

    public function testARouteThatFailsAnOrdinaryRequestIsReportedOnce(): void
    {
        // Found on Siyati: a webhook that answers 500 for a provider it does not know, whatever the body.
        $report = (new RouteAttacks($this->app(static function (Router $r): void {
            $r->post('/notifications/{provider}', #[PublicRoute(reason: 'Payment notices')] static function (string $provider): string {
                throw new \RuntimeException("The payment provider {$provider} is not configured.");
            });
        })))->run();

        $this->assertCount(1, $report->failures, $report->describe());
        $this->assertSame('an ordinary request', $report->failures[0]['attack']);
        $this->assertStringContainsString('answered 500 to POST /notifications/1, before any hostile input', $report->failures[0]['detail']);
    }

    public function testTheRoutesAreLeftAsTheyWere(): void
    {
        $app = $this->app(static fn (Router $r) => $r->get('/me', #[RequiresAuthentication] static fn (): string => 'private')->middleware(AuthorizationMiddleware::class));
        (new RouteAttacks($app))->run();

        $kernel = $app->container()->get(\Zana\Http\HttpKernel::class);
        assert($kernel instanceof \Zana\Http\HttpKernel);
        $this->assertSame(401, $kernel->handle(new \Nyholm\Psr7\ServerRequest('GET', '/me', ['Accept' => 'application/json']))->getStatusCode(), 'refused as before, not answered by the probe');
    }

    public function testLeavingARouteOutNeedsAReasonAndAnExistingRoute(): void
    {
        $app = $this->app(static fn (Router $r) => $r->post('/webhook', #[PublicRoute(reason: 'Payment notices')] static fn (): string => 'ok'));

        $report = (new RouteAttacks($app))->except(['POST /webhook' => 'Signed by the payment provider'])->run();
        $this->assertSame(['POST /webhook' => 'Signed by the payment provider'], $report->excluded);
        $this->assertSame(0, $report->requests);
        $this->assertStringContainsString('○ POST /webhook: left out by the application: Signed by the payment provider', $report->describe());

        foreach ([['POST /webhook' => ' '], ['POST /typo' => 'A reason']] as $except) {
            try {
                (new RouteAttacks($app))->except($except)->run();
                $this->fail('accepted: ' . json_encode($except));
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheTestCaseAssertionFailsWithTheReport(): void
    {
        $test = new class ('attack') extends ApplicationTestCase {
            public static ?\Closure $routes = null;

            protected function createApplicationBuilder(): ApplicationBuilder
            {
                return RouteAttacksTest::builder(self::$routes ?? static fn () => null, []);
            }

            public function attack(): void
            {
                $this->setUp();
                try {
                    $this->assertRoutesWithstandAttacks();
                } finally {
                    $this->tearDown();
                }
            }
        };
        $test::$routes = static fn (Router $r) => $r->get('/echo', #[PublicRoute(reason: 'Echo')] static fn (ServerRequestInterface $request): ResponseInterface => new Response(200, ['Content-Type' => 'text/html'], self::text($request->getQueryParams()['q'] ?? '')));

        try {
            $test->attack();
            $this->fail('the assertion passed');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('✘ GET /echo — a script in the query', $e->getMessage());
        }
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param \Closure(Router): mixed $routes
     * @param array<string, mixed> $config
     */
    public static function builder(\Closure $routes, array $config, ?CacheInterface $cache = null): ApplicationBuilder
    {
        $cache ??= new ArrayCache();

        return Application::configure(sys_get_temp_dir() . '/zana-testing-attacks')
            ->withEnvironment('testing')
            ->withConfiguration(['security.secret' => str_repeat('s', 40), ...$config])
            ->withProviders(HttpServiceProvider::class, RoutingServiceProvider::class, SecurityServiceProvider::class, new AttackRoutes($routes))
            ->withServices(static fn (ServiceCollectionInterface $s) => $s
                ->instance(CacheInterface::class, $cache)
                ->singleton(SignsInForReal::class)
                ->singleton(SignsInNobody::class));
    }

    /**
     * @param \Closure(Router): mixed $routes
     * @param array<string, mixed> $config
     */
    private function app(\Closure $routes, array $config = []): ApplicationInterface
    {
        return self::builder($routes, $config)->build();
    }
}

/** Defines the test's routes at boot. */
final class AttackRoutes extends \Zana\Core\ServiceProvider
{
    /**
     * @param \Closure(Router): mixed $routes
     */
    public function __construct(private readonly \Closure $routes)
    {
    }

    public function register(ServiceCollectionInterface $services): void
    {
    }

    public function boot(\Zana\Container\Contracts\ContainerInterface $container): void
    {
        $router = $container->get(Router::class);
        assert($router instanceof Router);
        ($this->routes)($router);
    }
}

/** An application's own sign-in middleware that really sends anonymous visitors to sign in. */
final class SignsInForReal implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return new Response(302, ['Location' => '/login']);
    }
}

/** One that claims to, and lets everyone through. */
final class SignsInNobody implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

/** Remembers nothing, so a rate limiter on it never counts. */
final class ForgetfulCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return true;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return [];
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }
}

final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
}
