<?php

declare(strict_types=1);

namespace Zana\Testing;

use PHPUnit\Framework\Assert;

/**
 * The exit code and output of a command run by ConsoleTester.
 */
final readonly class CommandResult
{
    public function __construct(
        private int $exitCode,
        private string $output,
    ) {
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function output(): string
    {
        return $this->output;
    }

    public function assertSuccessful(): self
    {
        Assert::assertSame(0, $this->exitCode, "The command failed with exit code {$this->exitCode}:\n{$this->output}");

        return $this;
    }

    public function assertFailed(): self
    {
        Assert::assertNotSame(0, $this->exitCode, "The command succeeded:\n{$this->output}");

        return $this;
    }

    public function assertOutputContains(string $text): self
    {
        Assert::assertStringContainsString($text, $this->output);

        return $this;
    }
}
