<?php

declare(strict_types=1);

namespace Zana\Testing\Architecture;

use Zana\Configuration\ConfigurationInterface;
use Zana\Core\ApplicationInterface;

/**
 * Zana's dependency rules (docs: architecture/dependency-rules.md) for every
 * module in a directory, ready to assert:
 *
 *     $this->assertModuleArchitecture(ModuleRules::for(__DIR__ . '/../modules'));
 *
 * Inside each module:
 * - Domain depends only on zana/ddd;
 * - Application depends on its Domain, module contracts, zana/ddd, mediator,
 *   validation, serialization attributes, #[AsListener] and the PSR log and
 *   event interfaces;
 * - Infrastructure never depends on Presentation;
 * - Presentation never depends on its Domain or Infrastructure;
 * - Contracts depend on nothing but plain PHP.
 *
 * Between modules, only Contracts may be referenced. Modules are the
 * directories of the modules directory; each must use the namespace
 * <root>\<Directory>. allow() adds namespaces a layer may use.
 *
 * A shared kernel (2.30, ADR-034) is one module's Contracts that every layer
 * of every module may use, Domain and Contracts included, for value types
 * such as money or a clock: sharedKernel('Modules\Shared\Contracts'), or
 * architecture.shared_kernel with forApplication(). The kernel itself may
 * depend only on plain PHP, Zana\Ddd and the other kernels, so it stays a set
 * of values; its implementations live in its module's Infrastructure.
 */
final class ModuleRules
{
    public const array LAYERS = ['Domain', 'Application', 'Infrastructure', 'Presentation', 'Contracts'];

    private const array APPLICATION_ALLOWED = [
        'Zana\Ddd',
        'Zana\Mediator',
        'Zana\Validation',
        'Zana\Serialization\Attribute',
        'Zana\Events\AsListener',
        'Psr\Log',
        'Psr\EventDispatcher',
    ];

    /** @var array<string, list<string>> */
    private array $extra = [];

    /** @var list<string> The shared kernel's namespaces: modules' Contracts (2.30) */
    private array $kernel = [];

    /**
     * @param list<string> $modules
     */
    private function __construct(
        private readonly string $directory,
        private readonly string $root,
        private readonly array $modules,
    ) {
    }

    public static function for(string $modulesDirectory, string $rootNamespace = 'Modules'): self
    {
        if (!is_dir($modulesDirectory)) {
            throw new \InvalidArgumentException("{$modulesDirectory} is not a directory.");
        }

        $modules = [];
        foreach (scandir($modulesDirectory) ?: [] as $entry) {
            if ($entry[0] !== '.' && is_dir("{$modulesDirectory}/{$entry}")) {
                $modules[] = $entry;
            }
        }

        return new self(rtrim(str_replace('\\', '/', $modulesDirectory), '/'), trim($rootNamespace, '\\'), $modules);
    }

    /**
     * The rules of an application (2.30): its modules directory, and the
     * shared kernel that architecture.shared_kernel declares, as `check`
     * reads them, so an application's own test and `check` agree:
     *
     *     $this->assertModuleArchitecture(ModuleRules::forApplication($this->app()));
     *
     * @throws \InvalidArgumentException When the setting names something that is not a module's Contracts
     */
    public static function forApplication(ApplicationInterface $application, string $rootNamespace = 'Modules'): self
    {
        $rules = self::for($application->path('modules'), $rootNamespace);
        $config = $application->container()->get(ConfigurationInterface::class);
        assert($config instanceof ConfigurationInterface);
        $kernel = $config->array('architecture.shared_kernel', []);
        foreach ($kernel as $namespace) {
            if (!is_string($namespace)) {
                throw new \InvalidArgumentException('architecture.shared_kernel lists namespaces, such as "Modules\Shared\Contracts": ' . get_debug_type($namespace) . ' found.');
            }
        }

        /** @var array<array-key, string> $kernel (keys dropped below: spread with string keys would name arguments) */
        return $kernel === [] ? $rules : $rules->sharedKernel(...array_values($kernel));
    }

    /**
     * Declare the shared kernel (2.30, ADR-034): modules' Contracts that every
     * layer of every module may use. Each must be exactly a module's
     * Contracts namespace, never its other layers nor a part of it.
     *
     * @throws \InvalidArgumentException For a namespace that is not a module's Contracts
     */
    public function sharedKernel(string ...$namespaces): self
    {
        $rules = clone $this;
        foreach ($namespaces as $namespace) {
            $namespace = trim($namespace, '\\');
            $parts = explode('\\', $namespace);
            $module = count($parts) === 3 && $parts[0] === $this->root && $parts[2] === 'Contracts' ? $parts[1] : null;
            if ($module === null || !in_array($module, $this->modules, true)) {
                throw new \InvalidArgumentException(
                    "The shared kernel {$namespace} must be a module's Contracts, such as {$this->root}\\Shared\\Contracts"
                    . ($module === null ? '' : ", and {$this->root}\\{$module} is not a module in {$this->directory}")
                    . ': only its values are shared, never a module\'s Domain, Application, Infrastructure or Presentation.',
                );
            }
            if (!in_array($namespace, $rules->kernel, true)) {
                $rules->kernel[] = $namespace;
            }
        }

        return $rules;
    }

    /**
     * @return list<string> The shared kernel's namespaces
     */
    public function kernel(): array
    {
        return $this->kernel;
    }

    /**
     * @return list<string> The kernel's namespaces whose module has no Contracts directory: they share nothing
     */
    public function emptyKernel(): array
    {
        return array_values(array_filter($this->kernel, fn (string $namespace): bool => !is_dir("{$this->directory}/" . explode('\\', $namespace)[1] . '/Contracts')));
    }

    /**
     * Let a layer use more namespaces, e.g. allow('Domain', 'Brick\Money') or
     * allow('Application', 'Psr\Clock'). For Infrastructure and Presentation,
     * which only have forbidden namespaces, this has no effect.
     */
    public function allow(string $layer, string ...$namespaces): self
    {
        if (!in_array($layer, self::LAYERS, true)) {
            throw new \InvalidArgumentException("Unknown layer {$layer}; use one of " . implode(', ', self::LAYERS) . '.');
        }

        $rules = clone $this;
        $rules->extra[$layer] = [...$rules->extra[$layer] ?? [], ...array_values($namespaces)];

        return $rules;
    }

    /**
     * @return list<string> The module names found
     */
    public function modules(): array
    {
        return $this->modules;
    }

    /**
     * Every rule with the directory it applies to; layers a module does not
     * have are skipped.
     *
     * @return list<array{ArchitectureRule, string}>
     */
    public function rules(): array
    {
        $rules = [];
        foreach ($this->modules as $module) {
            $ns = "{$this->root}\\{$module}";
            $dir = "{$this->directory}/{$module}";
            $otherContracts = array_map(fn (string $other): string => "{$this->root}\\{$other}\\Contracts", array_values(array_diff($this->modules, [$module])));

            $layers = [
                'Domain' => ArchitectureRule::classesIn("{$ns}\\Domain")->mayDependOnlyOn('Zana\Ddd', ...$this->kernel, ...$this->extra['Domain'] ?? []),
                'Application' => ArchitectureRule::classesIn("{$ns}\\Application")->mayDependOnlyOn(
                    "{$ns}\\Domain",
                    "{$ns}\\Contracts",
                    ...$otherContracts,
                    ...self::APPLICATION_ALLOWED,
                    ...$this->extra['Application'] ?? [],
                ),
                'Infrastructure' => ArchitectureRule::classesIn("{$ns}\\Infrastructure")->mustNotDependOn("{$ns}\\Presentation"),
                'Presentation' => ArchitectureRule::classesIn("{$ns}\\Presentation")->mustNotDependOn("{$ns}\\Domain", "{$ns}\\Infrastructure"),
                // A shared kernel's own rule (2.30): values on plain PHP and Zana\Ddd, and on the other kernels; never a module.
                'Contracts' => in_array("{$ns}\\Contracts", $this->kernel, true)
                    ? ArchitectureRule::classesIn("{$ns}\\Contracts")->mayDependOnlyOn('Zana\Ddd', ...$this->kernel)
                    : ArchitectureRule::classesIn("{$ns}\\Contracts")->mayDependOnlyOn(...$this->kernel, ...$this->extra['Contracts'] ?? []),
            ];
            foreach ($layers as $layer => $rule) {
                if (is_dir("{$dir}/{$layer}")) {
                    $rules[] = [$rule, "{$dir}/{$layer}"];
                }
            }

            $internals = [];
            foreach (array_diff($this->modules, [$module]) as $other) {
                foreach (['Domain', 'Application', 'Infrastructure', 'Presentation'] as $layer) {
                    $internals[] = "{$this->root}\\{$other}\\{$layer}";
                }
            }
            if ($internals !== []) {
                $rules[] = [ArchitectureRule::classesIn($ns)->mustNotDependOn(...$internals), $dir];
            }
        }

        return $rules;
    }

    /**
     * @return list<Violation>
     */
    public function check(): array
    {
        $violations = [];
        foreach ($this->rules() as [$rule, $directory]) {
            array_push($violations, ...$rule->check($directory));
        }

        return $violations;
    }
}
