<?php

declare(strict_types=1);

namespace Zana\Testing\Architecture;

use PHPUnit\Framework\Assert;

/**
 * For test cases: $this->assertArchitecture(ArchitectureRule::classesIn(...)->..., __DIR__ . '/../src').
 */
trait ArchitectureAssertions
{
    public static function assertArchitecture(ArchitectureRule $rule, string $directory): void
    {
        $violations = $rule->check($directory);

        Assert::assertSame(
            [],
            array_map('strval', $violations),
            sprintf("%d architecture violation(s): %s\n", count($violations), $rule->describe()) . implode("\n", $violations),
        );
    }
}
