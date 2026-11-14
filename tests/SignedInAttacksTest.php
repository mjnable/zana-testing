<?php

declare(strict_types=1);

namespace Zana\Testing\Tests;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\ApplicationInterface;
use Zana\Routing\Attribute\PublicRoute;
use Zana\Routing\Router;
use Zana\Security\Attribute\RequiresAuthentication;
use Zana\Security\Attribute\RequiresPermission;
use Zana\Security\Middleware\AuthenticationMiddleware;
use Zana\Security\Middleware\AuthorizationMiddleware;
use Zana\Security\Middleware\CsrfMiddleware;
use Zana\Security\TokenSigner;
use Zana\Security\UserInterface;
use Zana\Security\UserProviderInterface;
use Zana\Testing\Security\RouteAttacks;

/**
 * 2.22, FE-68 layer 2: the attacks as a signed-in user, without roles. The
 * application says how to sign in; each attack is shown to catch a route that
 * fails it, and to pass one that withstands it.
 */
final class SignedInAttacksTest extends TestCase
{
    public function testAUserWithoutThePermissionIsRefused(): void
    {
        $app = $this->app(static function (Router $r): void {
            $r->get('/me', #[RequiresAuthentication] static fn (): string => 'me')->middleware(AuthorizationMiddleware::class);
            $r->get('/reports', #[RequiresPermission('reports.view')] static fn (): string => 'reports')->middleware(AuthorizationMiddleware::class);
        });

        $report = (new RouteAttacks($app))->signIn(self::bearer($app, 'ada'))->run();

        $this->assertTrue($report->passed(), $report->describe());
    }

    public function testASignedInUserWhoReachesAPermissionRouteFails(): void
    {
        // Signed in as someone who holds every permission: the attack says so, and how to fix the test.
        $app = $this->app(static function (Router $r): void {
            $r->get('/me', #[RequiresAuthentication] static fn (): string => 'me')->middleware(AuthorizationMiddleware::class);
            $r->get('/reports', #[RequiresPermission('reports.view')] static fn (): string => 'reports')->middleware(AuthorizationMiddleware::class);
        });

        $report = (new RouteAttacks($app))->signIn(self::bearer($app, 'boss'))->run();

        $this->assertSame([[
            'route' => 'GET /reports',
            'attack' => 'a signed-in user without the permission',
            'detail' => 'reached the handler, although the route needs reports.view, which the signed-in user does not hold. If it does hold it, name it in holding.',
        ]], $report->failures);
    }

    public function testHoldingSaysWhichPermissionsTheUserHas(): void
    {
        // As in MMC, where the least-privileged staff role still holds some permissions.
        $routes = static function (Router $r): void {
            $r->get('/reports', #[RequiresPermission('reports.view')] static fn (): string => 'reports')->middleware(AuthorizationMiddleware::class);
            $r->get('/users', #[RequiresPermission('users.manage')] static fn (): string => 'users')->middleware(AuthorizationMiddleware::class);
        };
        $app = $this->app($routes, ['security.roles' => ['admin' => ['*'], 'analyst' => ['reports.view']]]);

        $honest = (new RouteAttacks($app))->signIn(self::bearer($app, 'ann'), holding: ['reports.view'])->run();
        $this->assertTrue($honest->passed(), $honest->describe());

        $app = $this->app($routes, ['security.roles' => ['admin' => ['*'], 'analyst' => ['reports.view']]]);
        $false = (new RouteAttacks($app))->signIn(self::bearer($app, 'ada'), holding: ['reports.view'])->run();
        $this->assertSame('the permissions in holding', $false->failures[0]['attack']);
        $this->assertStringContainsString('holding names a permission the user does not hold', $false->failures[0]['detail']);
    }

    public function testHostileInputFromASignedInUserIsTried(): void
    {
        $app = $this->app(static fn (Router $r) => $r->get('/profile', #[RequiresAuthentication] static fn (ServerRequestInterface $request): ResponseInterface => new Response(200, ['Content-Type' => 'text/html'], '<h1>' . strval(is_string($q = $request->getQueryParams()['q'] ?? '') ? $q : '') . '</h1>'))->middleware(AuthorizationMiddleware::class));

        $anonymous = (new RouteAttacks($app))->run();
        $this->assertTrue($anonymous->passed(), 'anonymous requests never reach the page');

        $signedIn = (new RouteAttacks($this->app(static fn (Router $r) => $r->get('/profile', #[RequiresAuthentication] static fn (ServerRequestInterface $request): ResponseInterface => new Response(200, ['Content-Type' => 'text/html'], '<h1>' . strval(is_string($q = $request->getQueryParams()['q'] ?? '') ? $q : '') . '</h1>'))->middleware(AuthorizationMiddleware::class))))
            ->signIn(self::bearer($app, 'ada'))
            ->run();
        $this->assertContains(['route' => 'GET /profile', 'attack' => 'a script in the query, signed in', 'detail' => 'the page prints the script as it was sent: a visitor could be made to run it.'], $signedIn->failures);
    }

    public function testCsrfIsAttackedForASessionAndSkippedForABearerToken(): void
    {
        $routes = static fn (Router $r) => $r->post('/settings', static fn (): string => 'saved')->middleware(CookieSignIn::class, CsrfMiddleware::class);
        $config = ['security.access_middleware' => [CookieSignIn::class => 'Sends visitors without the sign-in cookie to sign in']];

        $session = (new RouteAttacks($this->app($routes, $config)))
            ->signIn(static fn (ServerRequestInterface $request): ServerRequestInterface => $request->withCookieParams(['signed_in' => 'yes']))
            ->run();
        $this->assertTrue($session->passed(), $session->describe());
        $this->assertGreaterThanOrEqual(5, $session->requests, 'anonymous, expired session, signed in, and three CSRF attacks');

        $app = $this->app($routes, $config);
        $bearer = (new RouteAttacks($app))
            ->signIn(static fn (ServerRequestInterface $request): ServerRequestInterface => $request->withCookieParams(['signed_in' => 'yes'])->withHeader('Authorization', 'Bearer x'))
            ->run();
        $this->assertContains(['route' => '/settings', 'reason' => 'not attacked for CSRF as a signed-in user: the signIn function uses a bearer token, which CSRF cannot reach'], $bearer->skipped);
    }

    public function testASignInFunctionThatSignsNobodyInFails(): void
    {
        $app = $this->app(static fn (Router $r) => $r->get('/me', #[RequiresAuthentication] static fn (): string => 'me')->middleware(AuthorizationMiddleware::class));

        $report = (new RouteAttacks($app))->signIn(static fn (ServerRequestInterface $request): ServerRequestInterface => $request)->run();

        $this->assertSame('sign-in', $report->failures[0]['route']);
        $this->assertStringContainsString('so it does not sign a user in', $report->failures[0]['detail']);
        $this->assertStringContainsString('the signed-in attacks proved nothing', $report->failures[0]['detail']);
    }

    public function testWithoutSignInNothingChanges(): void
    {
        $report = (new RouteAttacks($this->app(static fn (Router $r) => $r->get('/about', #[PublicRoute(reason: 'About')] static fn (): string => 'about'))))->run();

        $this->assertTrue($report->passed(), $report->describe());
    }

    /**
     * A bearer token for one of the test users, as an application's test would get one.
     *
     * @return \Closure(ServerRequestInterface): ServerRequestInterface
     */
    private static function bearer(ApplicationInterface $app, string $user): \Closure
    {
        $signer = $app->container()->get(TokenSigner::class);
        assert($signer instanceof TokenSigner);
        $token = $signer->issue($user, 3600);

        return static fn (ServerRequestInterface $request): ServerRequestInterface => $request->withHeader('Authorization', "Bearer {$token}");
    }

    /**
     * @param \Closure(Router): mixed $routes
     * @param array<string, mixed> $config
     */
    private function app(\Closure $routes, array $config = []): ApplicationInterface
    {
        return RouteAttacksTest::builder($routes, ['http.middleware' => [AuthenticationMiddleware::class], 'security.roles' => ['admin' => ['*']], ...$config])   // a later security.roles replaces this one
            ->withServices(static fn (ServiceCollectionInterface $s) => $s->instance(UserProviderInterface::class, new TestUsers())->singleton(CookieSignIn::class))
            ->build();
    }
}

final readonly class TestUser implements UserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(private string $id, private array $roles)
    {
    }

    public function identifier(): string
    {
        return $this->id;
    }

    public function roles(): array
    {
        return $this->roles;
    }
}

/** ada has no role; boss is an admin, who holds every permission. */
final class TestUsers implements UserProviderInterface
{
    public function findByIdentifier(string $identifier): ?UserInterface
    {
        return match ($identifier) {
            'ada' => new TestUser('ada', []),
            'ann' => new TestUser('ann', ['analyst']),
            'boss' => new TestUser('boss', ['admin']),
            default => null,
        };
    }
}

/** An application's own sign-in: a cookie, or a redirect to the sign-in page. */
final class CookieSignIn implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return ($request->getCookieParams()['signed_in'] ?? null) === 'yes' ? $handler->handle($request) : new Response(302, ['Location' => '/login']);
    }
}
