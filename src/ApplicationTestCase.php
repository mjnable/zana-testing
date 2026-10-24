<?php

declare(strict_types=1);

namespace Zana\Testing;

use PHPUnit\Framework\TestCase;
use Zana\Console\ConsoleKernel;
use Zana\Core\ApplicationInterface;
use Zana\Http\HttpKernel;

/**
 * Builds the application before each test and terminates it after.
 *
 *     final class PatientApiTest extends ApplicationTestCase
 *     {
 *         protected function createApplication(): ApplicationInterface
 *         {
 *             return require __DIR__ . '/../bootstrap/testing.php';
 *         }
 *     }
 *
 * Subclasses that override setUp() or tearDown() must call the parent.
 */
abstract class ApplicationTestCase extends TestCase
{
    private ?ApplicationInterface $application = null;

    /**
     * Build the application, normally with ->withEnvironment('testing').
     */
    abstract protected function createApplication(): ApplicationInterface;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = $this->createApplication();
    }

    protected function tearDown(): void
    {
        $this->application?->terminate();
        $this->application = null;
        parent::tearDown();
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

    protected function console(): ConsoleTester
    {
        return new ConsoleTester($this->service(ConsoleKernel::class));
    }
}
