<?php

declare(strict_types=1);

namespace Zana\Testing\Architecture;

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
                'Domain' => ArchitectureRule::classesIn("{$ns}\\Domain")->mayDependOnlyOn('Zana\Ddd', ...$this->extra['Domain'] ?? []),
                'Application' => ArchitectureRule::classesIn("{$ns}\\Application")->mayDependOnlyOn(
                    "{$ns}\\Domain",
                    "{$ns}\\Contracts",
                    ...$otherContracts,
                    ...self::APPLICATION_ALLOWED,
                    ...$this->extra['Application'] ?? [],
                ),
                'Infrastructure' => ArchitectureRule::classesIn("{$ns}\\Infrastructure")->mustNotDependOn("{$ns}\\Presentation"),
                'Presentation' => ArchitectureRule::classesIn("{$ns}\\Presentation")->mustNotDependOn("{$ns}\\Domain", "{$ns}\\Infrastructure"),
                'Contracts' => ArchitectureRule::classesIn("{$ns}\\Contracts")->mayDependOnlyOn(...$this->extra['Contracts'] ?? []),
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
