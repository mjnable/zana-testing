# Zana Testing

Test Zana applications with PHPUnit: boot the application for each test,
send HTTP requests and run commands in memory, record events, and enforce
architecture rules. Requires PHP 8.5 or later.

```
composer require --dev mj-zana/testing
```

## Application tests

```php
final class PatientApiTest extends ApplicationTestCase
{
    protected function createApplication(): ApplicationInterface
    {
        return Application::configure(dirname(__DIR__))
            ->withEnvironment('testing')
            ->withProviders(/* ... */)
            ->build();
    }

    public function testRegistering(): void
    {
        $this->http()
            ->withToken($this->service(TokenSigner::class)->issue('ada', 60))
            ->post('/patients', ['name' => 'Ada', 'height' => 1.7])
            ->assertCreated()
            ->assertJson(['data' => ['name' => 'Ada']])
            ->assertJsonPath('data.measurements.0.bmi', 22.5);

        $this->console()->run('migrate:status')->assertSuccessful()->assertOutputContains('Ran');
    }
}
```

- **Lifecycle:** `setUp()` builds the application and `tearDown()`
  terminates it. Call the parent methods if you override them.
- **Helpers:** `app()`, `service(Class::class)`, `http()` and `console()`.
- **HTTP client:** sends requests straight through `HttpKernel::handle()`.
  - An array body is sent as JSON, and `Accept: application/json` is the
    default.
  - `withHeader()` and `withToken()` return a new client.
  - `submit($uri, $fields)` posts an HTML form: URL-encoded, with
    `Accept: text/html`.
- **Responses:** `TestResponse` offers `status()`, `header()`, `body()`,
  `json()`, `jsonPath('data.items.0.id')` and assertions for the common
  statuses, headers, JSON subsets and paths.

## Websites

```php
$this->browser()->get('/en/about')->assertOk()->assertSee('Tom & Jerry')->assertSelector('h1', 'About us');

$this->browser()->form('/en/contact')         // loads the page, keeps hidden fields such as CSRF tokens
    ->fill(['name' => 'Ada', 'message' => 'Hello'])
    ->submit()
    ->assertRedirect('/en/contact/thanks');

$this->assertMailSent(fn (Message $m): bool => $m->subject === 'Contact from Ada');
```

- **`browser()`:** a client that accepts HTML.
- **HTML assertions:** `assertSee()` and `assertDontSee()` compare
  HTML-escaped text by default. `assertSelector()` uses CSS selectors through
  PHP's `Dom\HTMLDocument`. `assertRedirect()` checks a redirect.
- **Mail:** `assertMailSent()` and `assertNoMailSent()` need
  `mj-zana/communication` with `mail.dsn: memory://`.
- **Configuration per test:** implement `createApplicationBuilder()`
  instead of `createApplication()`, then call `$this->withConfig(['site.url' => '...'])`.
  The application is rebuilt for that test only.
- **Environment:** in the `testing` environment, `.env.testing` replaces
  `.env` when it exists, so a developer's `.env` never leaks into tests.

## Events

Register an `EventRecorder` as the PSR-14 dispatcher of the test
application. It can wrap the real dispatcher, which still receives every
event:

```php
$events = $this->service(EventRecorder::class);
$events->assertDispatched(PatientRegistered::class, fn (PatientRegistered $e) => $e->id === $id, times: 1);
$events->assertNotDispatched(PatientDeleted::class);
```

## Architecture rules

For a modular application, `ModuleRules` applies Zana's dependency rules to
every module in a directory, including modules added later:

```php
use ArchitectureAssertions;

public function testEveryModuleFollowsTheDependencyRules(): void
{
    $this->assertModuleArchitecture(ModuleRules::for(__DIR__ . '/../modules'));
}
```

- **Inside each module:**
  - the domain uses only zana/ddd;
  - the application uses its domain, module contracts, ddd, mediator,
    validation, serialization attributes, `#[AsListener]` and the PSR log
    and event interfaces;
  - infrastructure never uses presentation;
  - presentation never uses the domain or infrastructure;
  - contracts are plain PHP.
- **Between modules:** only `Contracts` may be used.
- **Extending:** `->allow('Domain', 'Brick\Money')` lets a layer use more.

For other rules, write them directly:

```php
use ArchitectureAssertions;

public function testTheDomainStaysPure(): void
{
    $this->assertArchitecture(
        ArchitectureRule::classesIn('App\Bmi\Domain')->mayDependOnlyOn('Zana\Ddd'),
        __DIR__ . '/../src',
    );
    $this->assertArchitecture(ArchitectureRule::classesIn('App\Bmi')->mustNotDependOn('App\Billing'), __DIR__ . '/../src');
}
```

- **What is read:** dependencies come from `use` imports (including group,
  function and const imports) and from fully qualified names.
- **What is always allowed:** global names, such as PHP's own classes, and
  the namespace itself.
- **Failures:** each violation is reported with its file and line.

## License

MIT — see [LICENSE](LICENSE).
