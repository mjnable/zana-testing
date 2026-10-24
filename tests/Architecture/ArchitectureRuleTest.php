<?php

declare(strict_types=1);

namespace Zana\Testing\Tests\Architecture;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Zana\Testing\Architecture\ArchitectureAssertions;
use Zana\Testing\Architecture\ArchitectureRule;
use Zana\Testing\Architecture\Violation;

final class ArchitectureRuleTest extends TestCase
{
    use ArchitectureAssertions;

    private const string SAMPLES = __DIR__ . '/samples';

    public function testMayDependOnlyOn(): void
    {
        $violations = ArchitectureRule::classesIn('App\Bmi\Domain')->mayDependOnlyOn('Zana\Ddd')->check(self::SAMPLES);

        $this->assertSame([
            ['Braced.php', 4, 'App\Billing\Tariff'],
            ['Patient.php', 8, 'App\Bmi\Infrastructure\PatientTable'],
            ['Patient.php', 8, 'App\Bmi\Infrastructure\Mapper'],
            ['Patient.php', 11, 'App\Shared\format_bmi'],
            ['Patient.php', 25, 'App\Bmi\Infrastructure\Database'],
        ], self::summary($violations), 'imports (group, aliased, function), fully qualified names and braced namespaces; own namespace, globals, closures, comments and strings are ignored');

        $this->assertSame('App\Bmi\Domain', $violations[0]->namespace);
        $this->assertStringEndsWith('/samples/Domain/Braced.php', $violations[0]->file);
        $this->assertSame('App\Bmi\Domain may depend only on Zana\Ddd', $violations[0]->rule);
        $this->assertStringEndsWith('Braced.php:4: App\Bmi\Domain depends on App\Billing\Tariff (App\Bmi\Domain may depend only on Zana\Ddd)', (string) $violations[0]);
    }

    public function testMustNotDependOn(): void
    {
        $violations = ArchitectureRule::classesIn('\App\Bmi\\')->mustNotDependOn('App\Billing')->check(self::SAMPLES);

        $this->assertSame([
            ['Braced.php', 4, 'App\Billing\Tariff'],
            ['PatientTable.php', 7, 'App\Billing\Invoice'],
        ], self::summary($violations), 'files outside the namespace (NoNamespace.php) are not checked');
    }

    public function testBothFormsCombine(): void
    {
        $rule = ArchitectureRule::classesIn('App\Bmi\Infrastructure')->mayDependOnlyOn('App\Bmi\Domain', 'Zana', 'App\Billing')->mustNotDependOn('App\Billing');

        $this->assertSame([['PatientTable.php', 7, 'App\Billing\Invoice']], self::summary($rule->check(self::SAMPLES)), 'forbidden wins over allowed');
        $this->assertSame('App\Bmi\Infrastructure may depend only on App\Bmi\Domain, Zana, App\Billing and must not depend on App\Billing', $rule->describe());
        $this->assertSame('App\Bmi\Infrastructure may depend only on itself', ArchitectureRule::classesIn('App\Bmi\Infrastructure')->mayDependOnlyOn()->describe());
        $this->assertCount(3, ArchitectureRule::classesIn('App\Bmi\Infrastructure')->mayDependOnlyOn()->check(self::SAMPLES));
    }

    public function testPrefixesMatchWholeSegments(): void
    {
        $violations = ArchitectureRule::classesIn('App\Bmi\Infra')->mayDependOnlyOn()->check(self::SAMPLES);

        $this->assertSame([], $violations, 'App\Bmi\Infra does not contain App\Bmi\Infrastructure');
    }

    public function testTheAssertionsTrait(): void
    {
        self::assertArchitecture(ArchitectureRule::classesIn('App\Bmi\Domain')->mustNotDependOn('Symfony'), self::SAMPLES);

        try {
            self::assertArchitecture(ArchitectureRule::classesIn('App\Bmi')->mustNotDependOn('App\Billing'), self::SAMPLES);
            $this->fail('The rule is broken by the samples.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('2 architecture violation(s): App\Bmi must not depend on App\Billing', $e->getMessage());
            $this->assertStringContainsString('PatientTable.php:7: App\Bmi\Infrastructure depends on App\Billing\Invoice', $e->getMessage());
        }
    }

    public function testInvalidInput(): void
    {
        foreach ([static fn () => ArchitectureRule::classesIn('\\'), static fn () => ArchitectureRule::classesIn('App')->check(__DIR__ . '/missing')] as $invalid) {
            try {
                $invalid();
                $this->fail('Invalid input must be rejected.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<array{string, int, string}>
     */
    private static function summary(array $violations): array
    {
        return array_map(static fn (Violation $v): array => [basename($v->file), $v->line, $v->dependency], $violations);
    }
}
