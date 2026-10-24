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
- **Responses:** `TestResponse` offers `status()`, `header()`, `body()`,
  `json()`, `jsonPath('data.items.0.id')` and assertions for the common
  statuses, headers, JSON subsets and paths.

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
