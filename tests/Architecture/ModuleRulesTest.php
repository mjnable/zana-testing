<?php

declare(strict_types=1);

namespace Zana\Testing\Tests\Architecture;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Zana\Testing\Architecture\ArchitectureAssertions;
use Zana\Testing\Architecture\ModuleRules;
use Zana\Testing\Architecture\Violation;

final class ModuleRulesTest extends TestCase
{
    use ArchitectureAssertions;

    public function testModulesThatFollowTheRulesPass(): void
    {
        $rules = ModuleRules::for(__DIR__ . '/modules-good');

        $this->assertSame(['Billing', 'Patients'], $rules->modules());
        $this->assertSame([], $rules->check());
        self::assertModuleArchitecture($rules);
    }

    public function testEveryBrokenRuleIsReported(): void
    {
        $this->assertSame([
            ['Measurement.php', 'Modules\Bmi\Domain', 'Zana\Database\ConnectionInterface'],
            ['Handler.php', 'Modules\Bmi\Application', 'Modules\Patients\Domain\Patient'],
            ['Repository.php', 'Modules\Bmi\Infrastructure', 'Modules\Bmi\Presentation\Page'],
            ['Page.php', 'Modules\Bmi\Presentation', 'Modules\Bmi\Domain\Measurement'],
            ['Handler.php', 'Modules\Bmi\Application', 'Modules\Patients\Domain\Patient'],
            ['PatientDirectory.php', 'Modules\Patients\Contracts', 'Modules\Patients\Domain\Patient'],
        ], self::summary(ModuleRules::for(__DIR__ . '/modules-bad')->check()), 'layer rules per module (Bmi, then Patients), then the cross-module rule; the Patients contract use in Handler is allowed');
    }

    public function testTheRulesAreDescribed(): void
    {
        $descriptions = array_map(static fn (array $rule): string => $rule[0]->describe(), ModuleRules::for(__DIR__ . '/modules-good')->rules());

        $this->assertContains('Modules\Billing\Application may depend only on Modules\Billing\Domain, Modules\Billing\Contracts, Modules\Patients\Contracts, Zana\Ddd, Zana\Mediator, Zana\Validation, Zana\Serialization\Attribute, Zana\Events\AsListener, Psr\Log, Psr\EventDispatcher', $descriptions);
        $this->assertContains('Modules\Billing must not depend on Modules\Patients\Domain, Modules\Patients\Application, Modules\Patients\Infrastructure, Modules\Patients\Presentation', $descriptions);
        $this->assertCount(4 + 1 + 1 + 1, $descriptions, 'Patients has four layers, Billing one; plus a cross-module rule each');
    }

    public function testLayersCanBeAllowedMore(): void
    {
        $rules = ModuleRules::for(__DIR__ . '/modules-bad')->allow('Domain', 'Zana\Database');

        $this->assertNotContains('Zana\Database\ConnectionInterface', array_map(static fn (Violation $v): string => $v->dependency, $rules->check()));
        $this->assertCount(6, ModuleRules::for(__DIR__ . '/modules-bad')->check(), 'allow() returns a new set of rules');

        $this->expectException(\InvalidArgumentException::class);
        $rules->allow('Controllers', 'X');
    }

    public function testTheAssertionReportsViolations(): void
    {
        try {
            self::assertModuleArchitecture(ModuleRules::for(__DIR__ . '/modules-bad'));
            $this->fail('The bad modules break the rules.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('6 module architecture violation(s)', $e->getMessage());
            $this->assertStringContainsString('Measurement.php:5: Modules\Bmi\Domain depends on Zana\Database\ConnectionInterface', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        ModuleRules::for(__DIR__ . '/missing');
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<array{string, string, string}>
     */
    private static function summary(array $violations): array
    {
        return array_map(static fn (Violation $v): array => [basename($v->file), $v->namespace, $v->dependency], $violations);
    }
}
