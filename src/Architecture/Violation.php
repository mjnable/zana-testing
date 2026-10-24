<?php

declare(strict_types=1);

namespace Zana\Testing\Architecture;

/**
 * A dependency that breaks an architecture rule.
 */
final readonly class Violation implements \Stringable
{
    public function __construct(
        public string $file,
        public int $line,
        public string $namespace,
        public string $dependency,
        public string $rule,
    ) {
    }

    public function __toString(): string
    {
        return "{$this->file}:{$this->line}: {$this->namespace} depends on {$this->dependency} ({$this->rule})";
    }
}
