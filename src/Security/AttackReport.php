<?php

declare(strict_types=1);

namespace Zana\Testing\Security;

/**
 * What RouteAttacks did (2.22, FE-68): the attacks a route did not withstand,
 * and how many requests were sent, the routes left out, and why.
 */
final class AttackReport
{
    /** @var list<array{route: string, attack: string, detail: string}> */
    public array $failures = [];

    /** @var list<array{route: string, reason: string}> */
    public array $skipped = [];

    /** @var array<string, string> route => the reason the application gave */
    public array $excluded = [];

    /** @var int<0, max> */
    public int $routes = 0;

    /** @var int<0, max> */
    public int $requests = 0;

    public function fail(string $route, string $attack, string $detail): void
    {
        $this->failures[] = ['route' => $route, 'attack' => $attack, 'detail' => $detail];
    }

    public function skip(string $route, string $reason): void
    {
        $this->skipped[] = ['route' => $route, 'reason' => $reason];
    }

    public function passed(): bool
    {
        return $this->failures === [];
    }

    /**
     * Readable by people and agents, as a test failure message.
     */
    public function describe(): string
    {
        $lines = [sprintf('%d route(s) attacked with %d request(s): %d failure(s).', $this->routes, $this->requests, count($this->failures))];
        foreach ($this->failures as $failure) {
            $lines[] = "  ✘ {$failure['route']} — {$failure['attack']}: {$failure['detail']}";
        }
        foreach ($this->skipped as $skip) {
            $lines[] = "  ↷ {$skip['route']}: not attacked, {$skip['reason']}";
        }
        foreach ($this->excluded as $route => $reason) {
            $lines[] = "  ○ {$route}: left out by the application: {$reason}";
        }

        return implode("\n", $lines);
    }
}
