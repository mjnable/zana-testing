<?php

declare(strict_types=1);

namespace Zana\Testing;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Zana\Communication\MailRecorder;
use Zana\Communication\Message;
use Zana\Console\ConsoleKernel;
use Zana\Core\ApplicationBuilder;
use Zana\Core\ApplicationInterface;
use Zana\Http\HttpKernel;

/**
 * Builds the application before each test and terminates it after.
 *
 *     final class ContactPageTest extends ApplicationTestCase
 *     {
 *         protected function createApplicationBuilder(): ApplicationBuilder
 *         {
 *             return Bootstrap::builder(dirname(__DIR__))->withEnvironment('testing');
 *         }
 *     }
 *
 * Implement createApplicationBuilder() to use withConfig(); implementing
 * createApplication() still works. Subclasses that override setUp() or
 * tearDown() must call the parent.
 */
abstract class ApplicationTestCase extends TestCase
{
    private ?ApplicationInterface $application = null;

    /** @var array<array-key, mixed> */
    private array $configuration = [];

    /**
     * Build the application, normally with ->withEnvironment('testing'). By
     * default, builds createApplicationBuilder() with the withConfig() overrides.
     */
    protected function createApplication(): ApplicationInterface
    {
        $builder = $this->createApplicationBuilder()
            ?? throw new \LogicException(static::class . ' must implement createApplicationBuilder() or createApplication().');

        return $builder->withConfiguration($this->configuration)->build();
    }

    /**
     * The builder of the application under test, or null when the subclass
     * implements createApplication() instead.
     */
    protected function createApplicationBuilder(): ?ApplicationBuilder
    {
        return null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = $this->createApplication();
    }

    protected function tearDown(): void
    {
        $this->application?->terminate();
        $this->application = null;
        $this->configuration = [];
        parent::tearDown();
    }

    /**
     * Rebuild the application with configuration overrides, for this test
     * only. Keys may be dotted: $this->withConfig(['site.url' => 'https://example.com']).
     *
     * @param array<array-key, mixed> $overrides
     */
    protected function withConfig(array $overrides): void
    {
        if ($this->createApplicationBuilder() === null) {
            throw new \LogicException('withConfig() needs ' . static::class . ' to implement createApplicationBuilder().');
        }

        $this->configuration = [...$this->configuration, ...$overrides];
        $this->application?->terminate();
        $this->application = $this->createApplication();
    }

    protected function app(): ApplicationInterface
    {
        return $this->application ?? throw new \LogicException('The application exists between setUp() and tearDown(); did a subclass skip parent::setUp()?');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $class): object
    {
        $service = $this->app()->container()->get($class);
        assert($service instanceof $class);

        return $service;
    }

    protected function http(): HttpTestClient
    {
        return new HttpTestClient($this->service(HttpKernel::class));
    }

    /**
     * A client that asks for HTML, as a browser does.
     */
    protected function browser(): HttpTestClient
    {
        return $this->http()->withHeader('Accept', 'text/html,application/xhtml+xml');
    }

    protected function console(): ConsoleTester
    {
        return new ConsoleTester($this->service(ConsoleKernel::class));
    }

    /**
     * $times messages matching $filter were sent (with mj-zana/communication
     * and mail.dsn "memory://").
     *
     * @param (callable(Message): bool)|null $filter
     * @return list<Message> The matching messages
     */
    protected function assertMailSent(?callable $filter = null, int $times = 1): array
    {
        $sent = $this->mail()->sent($filter);
        Assert::assertCount($times, $sent, sprintf('Expected %d matching message(s), %d were sent: %s', $times, count($sent), self::subjects($this->mail()->sent())));

        return $sent;
    }

    protected function assertNoMailSent(): void
    {
        Assert::assertSame(0, $this->mail()->count(), 'Expected no message, sent: ' . self::subjects($this->mail()->sent()));
    }

    private function mail(): MailRecorder
    {
        if (!class_exists(MailRecorder::class)) {
            Assert::fail('Mail assertions need mj-zana/communication.');
        }
        $mailer = $this->app()->container()->get(MailRecorder::class);
        assert($mailer instanceof MailRecorder);

        return $mailer;
    }

    /**
     * @param list<Message> $messages
     */
    private static function subjects(array $messages): string
    {
        return $messages === [] ? 'none' : implode(', ', array_map(static fn (Message $m): string => "\"{$m->subject}\"", $messages));
    }
}
