<?php

declare(strict_types=1);

namespace Zana\Testing\Tests;

use PHPUnit\Framework\AssertionFailedError;
use Zana\Core\ApplicationInterface;
use Zana\Http\HttpKernel;
use Zana\Testing\ApplicationTestCase;
use Zana\Testing\EventRecorder;
use Zana\Testing\TestResponse;

final class ApplicationTestCaseTest extends ApplicationTestCase
{
    protected function createApplication(): ApplicationInterface
    {
        return FixtureApp::create();
    }

    public function testTheLifecycle(): void
    {
        $case = new LifecycleCase('lifecycle');
        $case->start();
        $app = $case->application();
        $this->assertSame('testing', $app->environment()->name);
        $this->assertFalse($app->container()->isDisposed());

        $case->stop();
        $this->assertTrue($app->container()->isDisposed(), 'tearDown terminates the application');

        $this->expectException(\LogicException::class);
        $case->application();
    }

    public function testServices(): void
    {
        $this->assertInstanceOf(HttpKernel::class, $this->service(HttpKernel::class));
        $this->assertSame($this->app()->container()->get(EventRecorder::class), $this->service(EventRecorder::class));
    }

    public function testEveryClientMethod(): void
    {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            $response = $this->http()->{$method}("/{$method}?page=2&sort=name");
            $this->assertInstanceOf(TestResponse::class, $response);
            $response->assertOk()
                ->assertJsonPath('method', strtoupper($method))
                ->assertJsonPath('path', "/{$method}")
                ->assertJsonPath('query', ['page' => '2', 'sort' => 'name'])
                ->assertJsonPath('accept', 'application/json');
        }
    }

    public function testFormsAreSubmittedUrlEncoded(): void
    {
        $this->http()->withHeader('X-Tenant', 'north')->submit('/patients?step=2', ['name' => 'Ada & Grace', 'tags' => ['a', 'b']])
            ->assertOk()
            ->assertJsonPath('method', 'POST')
            ->assertJsonPath('contentType', 'application/x-www-form-urlencoded')
            ->assertJsonPath('accept', 'text/html')
            ->assertJsonPath('body', 'name=Ada+%26+Grace&tags%5B0%5D=a&tags%5B1%5D=b')
            ->assertJsonPath('parsed', ['name' => 'Ada & Grace', 'tags' => ['a', 'b']])
            ->assertJsonPath('query', ['step' => '2'])
            ->assertJsonPath('tenant', 'north');

        $this->http()->submit('/x', [], 'PUT', ['Accept' => 'application/json'])
            ->assertJsonPath('method', 'PUT')
            ->assertJsonPath('accept', 'application/json');
    }

    public function testBodiesAndHeaders(): void
    {
        $this->http()->post('/patients', ['name' => 'Ada', 'height' => 1.7])
            ->assertJsonPath('contentType', 'application/json')
            ->assertJsonPath('body', '{"name":"Ada","height":1.7}')
            ->assertJsonPath('parsed', ['name' => 'Ada', 'height' => 1.7]);

        $this->http()->put('/raw', 'a=1', ['Content-Type' => 'text/plain', 'Accept' => 'text/html'])
            ->assertJsonPath('body', 'a=1')
            ->assertJsonPath('contentType', 'text/plain')
            ->assertJsonPath('accept', 'text/html')
            ->assertJsonPath('parsed', null);

        $client = $this->http()->withToken('abc')->withHeader('X-Tenant', 'north');
        $client->get('/')->assertJsonPath('authorization', 'Bearer abc')->assertJsonPath('tenant', 'north');
        $this->http()->get('/')->assertJsonPath('authorization', '')->assertJsonPath('tenant', '');
    }

    public function testReadsAndPassingAssertions(): void
    {
        $response = $this->http()->get('/');

        $this->assertSame(200, $response->status());
        $this->assertSame('echo', $response->header('X-Handled-By'));
        $this->assertStringStartsWith('{', $response->body());
        $this->assertSame(200, $response->response()->getStatusCode());
        $this->assertIsArray($response->json());
        $this->assertSame(8, $response->jsonPath('data.items.1.id'));
        $this->assertNull($response->jsonPath('data.empty'));

        $response->assertHeader('X-Handled-By')
            ->assertHeader('X-Handled-By', 'echo')
            ->assertJson(['data' => ['total' => 2, 'items' => [['id' => 7, 'tags' => ['a']]]]])
            ->assertJson(['data' => ['items' => [1 => ['tags' => []]]]])
            ->assertJsonPath('data.items.0.tags.1', 'b');

        $this->http()->get('/status/201')->assertCreated();
        $this->http()->get('/status/204')->assertNoContent();
        $this->http()->get('/status/404')->assertNotFound();
        $this->http()->get('/status/401')->assertUnauthorized();
        $this->http()->get('/status/403')->assertForbidden();
        $this->http()->get('/status/422')->assertUnprocessable();
        $this->http()->get('/status/418')->assertStatus(418);
    }

    public function testFailingAssertions(): void
    {
        $ok = $this->http()->get('/');
        $text = $this->http()->get('/text');

        $this->assertFails('Expected status 201, got 200', static fn () => $ok->assertCreated());
        foreach (['assertOk', 'assertNoContent', 'assertNotFound', 'assertUnauthorized', 'assertForbidden', 'assertUnprocessable'] as $assertion) {
            $this->assertFails('Expected status', fn () => $this->http()->get('/status/201')->{$assertion}());
        }
        $this->assertFails('has no X-Missing header', static fn () => $ok->assertHeader('X-Missing'));
        $this->assertFails('Unexpected X-Handled-By header', static fn () => $ok->assertHeader('X-Handled-By', 'other'));
        $this->assertFails('"data.total" is 2, expected 3', static fn () => $ok->assertJson(['data' => ['total' => 3]]));
        $this->assertFails('"data.missing" is missing', static fn () => $ok->assertJson(['data' => ['missing' => 1]]));
        $this->assertFails('"data.total" is not an object', static fn () => $ok->assertJson(['data' => ['total' => ['x' => 1]]]));
        $this->assertFails('"data.total" is 2, expected "2"', static fn () => $ok->assertJson(['data' => ['total' => '2']]), 'strict comparison');
        $this->assertFails('Unexpected JSON value at "data.total"', static fn () => $ok->assertJsonPath('data.total', 3));
        $this->assertFails('no value at "data.items.5.id"', static fn () => $ok->jsonPath('data.items.5.id'));
        $this->assertFails('no value at "path.0.x"', static fn () => $ok->jsonPath('path.0.x'));
        $this->assertFails('is not valid JSON', static fn () => $text->json());
    }

    public function testConsoleRuns(): void
    {
        $this->console()->run('greet Ada')->assertSuccessful()->assertOutputContains('Hello, Ada!');
        $this->console()->run(['command' => 'greet'])->assertSuccessful()->assertOutputContains('Hello, world!');

        $failed = $this->console()->run('greet nobody')->assertFailed()->assertOutputContains('Nobody to greet');
        $this->assertSame(1, $failed->exitCode());
        $this->assertStringContainsString('Nobody', $failed->output());

        $this->assertFails('The command failed with exit code 1', static fn () => $failed->assertSuccessful());
        $this->assertFails('The command succeeded', fn () => $this->console()->run('greet')->assertFailed());
        $this->assertFails('contains "Goodbye"', fn () => $this->console()->run('greet')->assertOutputContains('Goodbye'));
        $this->console()->run('missing:command')->assertFailed();
    }

    public function testTheEventRecorder(): void
    {
        $events = $this->service(EventRecorder::class);
        $this->http()->get('/event/p1');
        $this->http()->get('/event/p2');
        $this->http()->get('/event/p2');

        $this->assertCount(3, $events->dispatched(PatientRegistered::class));
        $this->assertCount(3, $events->dispatched());
        $this->assertSame([], $events->dispatched(\stdClass::class));
        $events->assertDispatched(PatientRegistered::class);
        $events->assertDispatched(PatientRegistered::class, times: 3);
        $events->assertDispatched(PatientRegistered::class, static fn (PatientRegistered $e): bool => $e->id === 'p2', 2);
        $events->assertNotDispatched(\stdClass::class);

        $this->assertFails('No matching', static fn () => $events->assertDispatched(PatientRegistered::class, static fn (PatientRegistered $e): bool => $e->id === 'p9'));
        $this->assertFails('Expected 1 matching', static fn () => $events->assertDispatched(PatientRegistered::class, times: 1));
        $this->assertFails('was dispatched 3 time(s)', static fn () => $events->assertNotDispatched(PatientRegistered::class));

        $events->reset();
        $events->assertNotDispatched(PatientRegistered::class);
    }

    public function testTheRecorderForwardsToAnInnerDispatcher(): void
    {
        $inner = new EventRecorder();
        $outer = new EventRecorder($inner);
        $event = new PatientRegistered('p1');

        $this->assertSame($event, $outer->dispatch($event));
        $this->assertSame([$event], $inner->dispatched());
    }

    private function assertFails(string $message, \Closure $assertion, string $why = ''): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString($message, $e->getMessage(), $why);

            return;
        }

        $this->fail("The assertion should have failed with \"{$message}\". {$why}");
    }
}
