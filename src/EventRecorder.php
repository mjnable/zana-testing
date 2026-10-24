<?php

declare(strict_types=1);

namespace Zana\Testing;

use PHPUnit\Framework\Assert;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A PSR-14 dispatcher that records events and forwards them to an optional
 * inner dispatcher. Register it as EventDispatcherInterface in a test
 * application, or decorate the real dispatcher with it.
 */
final class EventRecorder implements EventDispatcherInterface
{
    /** @var list<object> */
    private array $events = [];

    public function __construct(private readonly ?EventDispatcherInterface $inner = null)
    {
    }

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $this->inner?->dispatch($event) ?? $event;
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|null $class
     *
     * @return ($class is null ? list<object> : list<T>)
     */
    public function dispatched(?string $class = null): array
    {
        return $class === null ? $this->events : array_values(array_filter($this->events, static fn (object $event): bool => $event instanceof $class));
    }

    /**
     * At least one matching event was dispatched, or exactly $times when given.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     * @param (callable(T): bool)|null $filter
     */
    public function assertDispatched(string $class, ?callable $filter = null, ?int $times = null): void
    {
        $matching = $this->dispatched($class);
        if ($filter !== null) {
            $matching = array_filter($matching, $filter);
        }

        if ($times === null) {
            Assert::assertNotEmpty($matching, "No matching {$class} event was dispatched.");
        } else {
            Assert::assertCount($times, $matching, "Expected {$times} matching {$class} event(s), got " . count($matching) . '.');
        }
    }

    /**
     * @param class-string $class
     */
    public function assertNotDispatched(string $class): void
    {
        $count = count($this->dispatched($class));
        Assert::assertSame(0, $count, "{$class} was dispatched {$count} time(s).");
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
