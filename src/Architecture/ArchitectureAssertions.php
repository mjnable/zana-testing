<?php

declare(strict_types=1);

namespace Zana\Testing\Architecture;

use PHPUnit\Framework\Assert;

/**
 * For test cases:
 *
 *     $this->assertArchitecture(ArchitectureRule::classesIn(...)->..., __DIR__ . '/../src');
 *     $this->assertModuleArchitecture(ModuleRules::for(__DIR__ . '/../modules'));
 */
trait ArchitectureAssertions
{
    /**
     * Zana's dependency rules for every module (see ModuleRules).
     */
    public static function assertModuleArchitecture(ModuleRules $rules): void
    {
        $violations = $rules->check();

        Assert::assertNotSame([], $rules->modules(), 'No modules were found.');
        Assert::assertSame(
            [],
            array_map('strval', $violations),
            sprintf("%d module architecture violation(s):\n", count($violations)) . implode("\n", $violations),
        );
    }

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
