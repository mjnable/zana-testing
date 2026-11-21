<?php

declare(strict_types=1);

namespace Zana\Testing\Tests\Architecture;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Zana\Core\Application;
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

    // ── 2.30, ADR-034: a declared shared kernel ─────────────────────────────

    public function testWithoutAKernelNoModuleMayShareItsValues(): void
    {
        $this->assertSame([
            ['Leaky.php', 'Modules\Shared\Contracts', 'Modules\Sales\Contracts\OrderPlaced'],
            ['Leaky.php', 'Modules\Shared\Contracts', 'Psr\Log\LoggerInterface'],
            ['Money.php', 'Modules\Shared\Contracts', 'Zana\Ddd\ValueObject'],
            ['Order.php', 'Modules\Sales\Domain', 'Modules\Billing\Contracts\InvoiceNumber'],
            ['Order.php', 'Modules\Sales\Domain', 'Modules\Shared\Contracts\Money'],
            ['OrderPlaced.php', 'Modules\Sales\Contracts', 'Modules\Shared\Contracts\Money'],
        ], self::sorted(ModuleRules::for(__DIR__ . '/modules-kernel')->check()), 'as before 2.30');
    }

    public function testEveryLayerMayUseTheKernelAndTheKernelOnlyValues(): void
    {
        $rules = ModuleRules::for(__DIR__ . '/modules-kernel')->sharedKernel('Modules\Shared\Contracts');

        $this->assertSame([
            ['Leaky.php', 'Modules\Shared\Contracts', 'Modules\Sales\Contracts\OrderPlaced'],    // the kernel uses no module
            ['Leaky.php', 'Modules\Shared\Contracts', 'Psr\Log\LoggerInterface'],                // nor a service
            ['Order.php', 'Modules\Sales\Domain', 'Modules\Billing\Contracts\InvoiceNumber'],   // another module's Contracts: still not
        ], self::sorted($rules->check()), 'Money in a Domain and in another module\'s Contracts, and Zana\Ddd in the kernel, pass');
        $this->assertSame(['Modules\Shared\Contracts'], $rules->kernel());
        $this->assertContains('Modules\Shared\Contracts may depend only on Zana\Ddd, Modules\Shared\Contracts', array_map(static fn (array $r): string => $r[0]->describe(), $rules->rules()));
    }

    public function testOnlyAModulesContractsCanBeTheKernel(): void
    {
        $rules = ModuleRules::for(__DIR__ . '/modules-kernel');
        foreach (['Modules\Shared\Domain', 'Modules\Shared\Contracts\Money', 'Modules\Shared', 'Modules\Nowhere\Contracts', 'App\Kernel', 'Modules\Sales\Infrastructure'] as $namespace) {
            try {
                $rules->sharedKernel($namespace);
                $this->fail("Accepted as a kernel: {$namespace}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("The shared kernel {$namespace} must be a module's Contracts", $e->getMessage());
            }
        }
        $this->assertSame(['Modules\Billing\Contracts', 'Modules\Shared\Contracts'], $rules->sharedKernel('\Modules\Billing\Contracts\\', 'Modules\Shared\Contracts', 'Modules\Billing\Contracts')->kernel(), 'trimmed, and once each');
        $this->assertSame([], $rules->kernel(), 'sharedKernel() returns a new set of rules');
    }

    public function testAnApplicationDeclaresItsKernelInConfiguration(): void
    {
        $base = sys_get_temp_dir() . '/zana-kernel-' . bin2hex(random_bytes(4));
        self::copy(__DIR__ . '/modules-kernel', "{$base}/modules");
        try {
            $declared = ModuleRules::forApplication(Application::configure($base)->withConfiguration(['architecture' => ['shared_kernel' => ['Modules\Shared\Contracts']]])->build());
            $this->assertSame(['Modules\Shared\Contracts'], $declared->kernel());
            $this->assertCount(3, self::sorted($declared->check()));

            $this->assertSame([], ModuleRules::forApplication(Application::configure($base)->build())->kernel(), 'none declared: the rules of before 2.30');

            foreach ([['Modules\Shared\Domain'], [42]] as $setting) {
                try {
                    ModuleRules::forApplication(Application::configure($base)->withConfiguration(['architecture' => ['shared_kernel' => $setting]])->build());
                    $this->fail('Accepted: ' . var_export($setting, true));
                } catch (\InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            self::remove($base);
        }
    }

    public function testAKernelWithoutContractsSharesNothingAndSaysSo(): void
    {
        $rules = ModuleRules::for(__DIR__ . '/modules-good')->sharedKernel('Modules\Billing\Contracts', 'Modules\Patients\Contracts');

        $this->assertSame(['Modules\Billing\Contracts'], $rules->emptyKernel(), 'Billing has no Contracts directory');
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

    private static function copy(string $from, string $to): void
    {
        @mkdir($to, 0777, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            /** @var \SplFileInfo $item */
            $target = $to . substr($item->getPathname(), strlen($from));
            $item->isDir() ? @mkdir($target, 0777, true) : copy($item->getPathname(), $target);
        }
    }

    private static function remove(string $directory): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

    /**
     * Unique and sorted: the layer and cross-module rules can both report one dependency, in an order that depends on
     * the directory listing.
     *
     * @param list<Violation> $violations
     *
     * @return list<array{string, string, string}>
     */
    private static function sorted(array $violations): array
    {
        $rows = array_values(array_unique(array_map(static fn (array $r): string => implode('|', $r), self::summary($violations))));
        sort($rows);

        return array_map(static function (string $r): array {
            [$file, $namespace, $dependency] = explode('|', $r, 3) + ['', '', ''];

            return [$file, $namespace, $dependency];
        }, $rows);
    }
}
