<?php

declare(strict_types=1);

namespace Zana\Testing\Tests;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\ApplicationInterface;
use Zana\Routing\Attribute\PublicRoute;
use Zana\Routing\Router;
use Zana\Security\Attribute\RequiresAuthentication;
use Zana\Security\Middleware\AuthenticationMiddleware;
use Zana\Security\Middleware\AuthorizationMiddleware;
use Zana\Security\Middleware\CsrfMiddleware;
use Zana\Security\SecurityContext;
use Zana\Security\TokenSigner;
use Zana\Security\UserInterface;
use Zana\Security\UserProviderInterface;
use Zana\Testing\Security\AttackReport;
use Zana\Testing\Security\RecordAttacks;

/**
 * 2.27: one user must not reach another's records. Each attack is shown to
 * catch a route that fails it, and to pass one that withstands it.
 */
final class RecordAttacksTest extends TestCase
{
    public function testRoutesThatCheckTheOwnerPass(): void
    {
        $report = $this->attack(static function (Router $r): void {
            $r->get('/orders/{id}', [Orders::class, 'show'])->middleware(AuthorizationMiddleware::class);
            $r->post('/orders/{id}/cancel', [Orders::class, 'cancel'])->middleware(AuthorizationMiddleware::class);
            $r->delete('/orders/{id}', [Orders::class, 'delete'])->middleware(AuthorizationMiddleware::class);
            $r->get('/me', [Orders::class, 'me'])->middleware(AuthorizationMiddleware::class);   // no record parameter: not tried
        }, writes: true);

        $this->assertTrue($report->passed(), $report->describe());
        $this->assertSame(3, $report->routes);
        $this->assertSame(6, $report->requests, 'the other user, then the owner, for each');
    }

    public function testARouteThatShowsAnyonesRecordFails(): void
    {
        $report = $this->attack(static fn (Router $r) => $r->get('/orders/{id}', [Orders::class, 'showToAnyone'])->middleware(AuthorizationMiddleware::class));

        $this->assertSame([[
            'route' => 'GET /orders/{id}',
            'attack' => "another user's record",
            'detail' => 'answered 200 to a user who does not own it: refuse it with 403, or 404 so its existence is not told.',
        ]], $report->failures);
    }

    public function testWritesAreTriedOnlyWhenAskedAndEachKindOfLeakFails(): void
    {
        $routes = static function (Router $r): void {
            $r->post('/orders/{id}/cancel', [Orders::class, 'cancelForAnyone'])->middleware(AuthorizationMiddleware::class);   // a redirect: taken
            $r->patch('/orders/{id}', [Orders::class, 'ownerOnlyPasses'])->middleware(AuthorizationMiddleware::class);         // 422 to the other only
            $r->delete('/orders/{id}', [Orders::class, 'delete'])->middleware(AuthorizationMiddleware::class);                 // fine
        };

        $this->assertSame([], $this->attack($routes)->failures, 'reads only, by default');

        $failures = $this->attack($routes, writes: true)->failures;
        $this->assertSame(['POST /orders/{id}/cancel', 'PATCH /orders/{id}'], array_column($failures, 'route'));
        $this->assertStringContainsString('answered 303 to a user who does not own it (a redirect, so the request was taken)', $failures[0]['detail']);
        $this->assertStringContainsString('answered 422 to a user who does not own it', $failures[1]['detail'], 'the answer depended on who asked');
    }

    /**
     * Found on Siyati: an application that checks the input before the owner answers 422 to both users for an empty
     * body. That proves nothing either way, so it is reported as not proven, until a valid body is given.
     */
    public function testAWriteRefusedForItsInputIsNotProvenUntilABodyIsGiven(): void
    {
        $routes = static fn (Router $r) => $r->put('/orders/{id}', [Orders::class, 'validatesFirst'])->middleware(AuthorizationMiddleware::class);

        $empty = $this->attack($routes, writes: true);
        $this->assertSame(['the owner function'], array_column($empty->failures, 'attack'), 'not a leak, but a run that proved nothing does not pass');
        $this->assertSame([['route' => 'PUT /orders/{id}', 'reason' => 'both users got 422, an answer about the input, before any about the owner: give a body the owner\'s request succeeds with, in bodies']], $empty->skipped);

        $valid = $this->attack($routes, writes: true, bodies: ['PUT /orders/{id}' => ['note' => 'Leave at the door']]);
        $this->assertTrue($valid->passed(), $valid->describe());
        $this->assertSame([], $valid->skipped, 'with the body, the owner got through and the other was refused');
    }

    /** Found on Siyati: an API reads JSON, so a form body, however valid, is a 400 to both users. */
    public function testAnApiIsSentJson(): void
    {
        $routes = static fn (Router $r) => $r->put('/orders/{id}', [Orders::class, 'updateFromJson'])->middleware(AuthorizationMiddleware::class);
        $body = ['PUT /orders/{id}' => ['note' => 'Leave at the door']];

        $form = $this->attack($routes, writes: true, bodies: $body);
        $this->assertStringStartsWith('both users got 400', $form->skipped[0]['reason'] ?? '');

        $json = $this->attack($routes, writes: true, bodies: $body, json: true);
        $this->assertTrue($json->passed(), $json->describe());
        $this->assertSame([], $json->skipped);
    }

    public function testAnApiSignedInByCookieGetsItsCsrfTokenInTheHeader(): void
    {
        $routes = static fn (Router $r) => $r->put('/orders/{id}', [Orders::class, 'updateFromJson'])->middleware(CookieUser::class, CsrfMiddleware::class, AuthorizationMiddleware::class);
        $app = $this->app($routes);
        $book = $app->container()->get(Book::class);
        assert($book instanceof Book);
        $as = static fn (string $user): \Closure => static fn (ServerRequestInterface $r): ServerRequestInterface => $r->withCookieParams(['user' => $user]);

        $report = (new RecordAttacks($app, $as('ada'), $as('grace'), ['id' => static fn (): string => $book->create('ada')]))
            ->writes()->json()->bodies(['PUT /orders/{id}' => ['note' => 'Leave at the door']])->run();

        $this->assertTrue($report->passed(), $report->describe() . ' (without the header, CSRF refuses both)');
        $this->assertSame([], $report->skipped);
    }

    public function testTheOtherUserAsksFirstSoTheOwnersWriteCannotHideALeak(): void
    {
        $report = $this->attack(static fn (Router $r) => $r->delete('/orders/{id}', [Orders::class, 'deleteForAnyone'])->middleware(AuthorizationMiddleware::class), writes: true);

        $this->assertSame(['DELETE /orders/{id}'], array_column($report->failures, 'route'), 'had the owner deleted it first, the other user would have got 404');
        $this->assertStringContainsString('answered 204 to a user who does not own it', $report->failures[0]['detail']);
    }

    public function testAPublicRouteThatLeaksARecordFails(): void
    {
        $report = $this->attack(static fn (Router $r) => $r->get('/receipts/{id}', [PublicReceipts::class, 'show']));

        $this->assertSame(['GET /receipts/{id}'], array_column($report->failures, 'route'), 'public routes are tried too');
    }

    public function testAWriteBehindCsrfGetsAValidToken(): void
    {
        // Signed in as a website is, not with a bearer token, which CsrfMiddleware does not check.
        $routes = static fn (Router $r) => $r->post('/orders/{id}/cancel', [Orders::class, 'cancel'])->middleware(CookieUser::class, CsrfMiddleware::class, AuthorizationMiddleware::class);
        $app = $this->app($routes);
        $book = $app->container()->get(Book::class);
        assert($book instanceof Book);
        $as = static fn (string $user): \Closure => static fn (ServerRequestInterface $r): ServerRequestInterface => $r->withCookieParams(['user' => $user]);

        $report = (new RecordAttacks($app, $as('ada'), $as('grace'), ['id' => static fn (): string => $book->create('ada')]))->writes()->run();

        $this->assertTrue($report->passed(), $report->describe() . ' (without a token, CSRF refuses both, and nothing is proven)');
        $this->assertSame([], $report->skipped);
    }

    public function testRecordsOfSeveralParameters(): void
    {
        $routes = static fn (Router $r) => $r->get('/projects/{project}/tasks/{id}', [Orders::class, 'task'])->middleware(AuthorizationMiddleware::class);

        $missing = $this->attack($routes);
        $this->assertSame([['route' => 'GET /projects/{project}/tasks/{id}', 'reason' => 'no record for {project}: name it in records']], $missing->skipped);

        $both = $this->attack($routes, records: static fn (Book $book): array => ['id' => static fn (): array => ['project' => 'p7', 'id' => $book->create('ada')]]);
        $this->assertTrue($both->passed(), $both->describe());
        $this->assertSame([], $both->skipped);
    }

    public function testAnOwnerWhoReachesNothingProvesNothing(): void
    {
        $report = $this->attack(static fn (Router $r) => $r->get('/orders/{id}', [Orders::class, 'show'])->middleware(AuthorizationMiddleware::class), owner: 'nobody');

        $this->assertSame([['route' => 'GET /orders/{id}', 'reason' => 'the owner was refused too (401), so the other\'s refusal proves nothing: check the owner\'s sign-in, and that the record exists']], $report->skipped);
        $this->assertSame('records', $report->failures[0]['route']);
        $this->assertStringContainsString('none of the 1 attempt(s) proved anything', $report->failures[0]['detail']);
    }

    public function testARecordThatCannotBeMadeOrAServerErrorFails(): void
    {
        $broken = $this->attack(static fn (Router $r) => $r->get('/orders/{id}', [Orders::class, 'show'])->middleware(AuthorizationMiddleware::class), records: static fn (): array => ['id' => static fn (): never => throw new \RuntimeException('no database')]);
        $this->assertSame(['making a record'], array_column($broken->failures, 'attack'));
        $this->assertStringContainsString("records['id'] threw RuntimeException: no database", $broken->failures[0]['detail']);

        $error = $this->attack(static fn (Router $r) => $r->get('/orders/{id}', [Orders::class, 'fails'])->middleware(AuthorizationMiddleware::class));
        $this->assertSame("the owner's own record", $error->failures[0]['attack']);
    }

    public function testRoutesLeftOutNeedAReason(): void
    {
        $routes = static fn (Router $r) => $r->get('/orders/{id}', [Orders::class, 'showToAnyone'])->middleware(AuthorizationMiddleware::class);

        $left = $this->attack($routes, except: ['GET /orders/{id}' => 'Shared by link with the accountant']);
        $this->assertTrue($left->passed());
        $this->assertSame(['GET /orders/{id}' => 'Shared by link with the accountant'], $left->excluded);

        try {
            $this->attack($routes, except: ['GET /orders/{id}' => '  ']);
            $this->fail('A route left out without a reason was accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Leaving GET /orders/{id} out of the attacks needs a reason.', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->attack($routes, except: ['GET /nope/{id}' => 'x']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param \Closure(Router): mixed $routes
     * @param (\Closure(Book): array<string, \Closure>)|null $records
     * @param array<string, string> $except
     * @param array<string, array<string, mixed>> $bodies
     */
    private function attack(\Closure $routes, bool $writes = false, ?\Closure $records = null, string $owner = 'ada', array $except = [], array $bodies = [], bool $json = false): AttackReport
    {
        $app = $this->app($routes);
        $book = $app->container()->get(Book::class);
        assert($book instanceof Book);

        return (new RecordAttacks(
            $app,
            self::bearer($app, $owner),
            self::bearer($app, 'grace'),
            $records !== null ? $records($book) : ['id' => static fn (): string => $book->create('ada')],
        ))->writes($writes)->except($except)->bodies($bodies)->json($json)->run();
    }

    /**
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
     */
    private function app(\Closure $routes): ApplicationInterface
    {
        return RouteAttacksTest::builder($routes, ['http.middleware' => [AuthenticationMiddleware::class]])
            ->withServices(static fn (ServiceCollectionInterface $s) => $s
                ->instance(UserProviderInterface::class, new RecordOwners())
                ->instance(Book::class, new Book())
                ->transient(Orders::class)
                ->transient(PublicReceipts::class)
                ->scoped(CookieUser::class))
            ->build();
    }
}

/** Orders in memory, by id, with their owner. */
final class Book
{
    /** @var array<string, string> id => owner */
    public array $owners = [];

    public function create(string $owner): string
    {
        $id = 'o' . (count($this->owners) + 1);
        $this->owners[$id] = $owner;

        return $id;
    }
}

/** A controller that checks the owner, and others that forget to in each way. */
#[RequiresAuthentication]
final readonly class Orders
{
    public function __construct(private Book $book, private SecurityContext $security)
    {
    }

    public function show(string $id): ResponseInterface
    {
        return $this->mine($id) ? new Response(200, [], "order {$id}") : new Response(404);
    }

    public function showToAnyone(string $id): ResponseInterface
    {
        return isset($this->book->owners[$id]) ? new Response(200, [], "order {$id}") : new Response(404);
    }

    public function cancel(string $id): ResponseInterface
    {
        return $this->mine($id) ? new Response(303, ['Location' => '/orders']) : new Response(404);
    }

    public function cancelForAnyone(string $id): ResponseInterface
    {
        return new Response(303, ['Location' => '/orders']);
    }

    /** Checks the input before the owner, then refuses another user: no leak, but an empty body proves nothing. */
    public function validatesFirst(string $id, ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !isset($body['note'])) {
            return new Response(422);
        }

        return $this->mine($id) ? new Response(204) : new Response(404);
    }

    /** An API's write: reads the raw body as JSON, as Siyati's does, then checks the owner. */
    public function updateFromJson(string $id, ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if ($request->getHeaderLine('Content-Type') !== 'application/json' || !is_array($body) || !isset($body['note'])) {
            return new Response(400);
        }

        return $this->mine($id) ? new Response(204) : new Response(404);
    }

    /** Valid for the owner, "invalid" for anyone else: the answer depends on who asks. */
    public function ownerOnlyPasses(string $id): ResponseInterface
    {
        return $this->mine($id) ? new Response(204) : new Response(422);
    }

    public function delete(string $id): ResponseInterface
    {
        if (!$this->mine($id)) {
            return new Response(404);
        }
        unset($this->book->owners[$id]);

        return new Response(204);
    }

    /** Deletes anyone's order: the leak shows only when the other user asks while the order still exists. */
    public function deleteForAnyone(string $id): ResponseInterface
    {
        if (!isset($this->book->owners[$id])) {
            return new Response(404);
        }
        unset($this->book->owners[$id]);

        return new Response(204);
    }

    public function task(string $project, string $id): ResponseInterface
    {
        return $project === 'p7' && $this->mine($id) ? new Response(200) : new Response(404);
    }

    public function fails(string $id): ResponseInterface
    {
        return $this->mine($id) ? new Response(500) : new Response(404);
    }

    public function me(): ResponseInterface
    {
        return new Response(200);
    }

    private function mine(string $id): bool
    {
        return ($this->book->owners[$id] ?? null) === $this->security->user()?->identifier();
    }
}

#[PublicRoute(reason: 'Receipts are shown by their link')]
final readonly class PublicReceipts
{
    public function __construct(private Book $book)
    {
    }

    public function show(string $id): ResponseInterface
    {
        return isset($this->book->owners[$id]) ? new Response(200, [], "receipt {$id}") : new Response(404);
    }
}

/** ada owns the records; grace owns none. */
final class RecordOwners implements UserProviderInterface
{
    public function findByIdentifier(string $identifier): ?UserInterface
    {
        return in_array($identifier, ['ada', 'grace'], true) ? new TestUser($identifier, []) : null;
    }
}

/** A website's sign-in, in short: the user named by a cookie. */
final readonly class CookieUser implements \Psr\Http\Server\MiddlewareInterface
{
    public function __construct(private SecurityContext $security)
    {
    }

    public function process(ServerRequestInterface $request, \Psr\Http\Server\RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getCookieParams()['user'] ?? null;
        if (is_string($user)) {
            $this->security->setUser((new RecordOwners())->findByIdentifier($user));
        }

        return $handler->handle($request);
    }
}
