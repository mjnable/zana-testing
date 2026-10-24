<?php

declare(strict_types=1);

namespace Zana\Testing\Architecture;

/**
 * A dependency rule for the classes of one namespace:
 *
 *     ArchitectureRule::classesIn('App\Bmi\Domain')->mayDependOnlyOn('Zana\Ddd');
 *     ArchitectureRule::classesIn('App\Bmi')->mustNotDependOn('App\Billing');
 *
 * Dependencies are read from use imports (including group, function and
 * const imports) and fully qualified names. Global names (PHP built-ins) are
 * always allowed, and so is the namespace itself.
 */
final class ArchitectureRule
{
    /** @var list<string> */
    private array $allowed = [];

    /** @var list<string> */
    private array $forbidden = [];

    private bool $restricted = false;

    private function __construct(private readonly string $namespace)
    {
    }

    public static function classesIn(string $namespace): self
    {
        $namespace = trim($namespace, '\\');
        if ($namespace === '') {
            throw new \InvalidArgumentException('An architecture rule needs a namespace.');
        }

        return new self($namespace);
    }

    public function mayDependOnlyOn(string ...$namespaces): self
    {
        $rule = clone $this;
        $rule->restricted = true;
        array_push($rule->allowed, ...self::normalize($namespaces));

        return $rule;
    }

    public function mustNotDependOn(string ...$namespaces): self
    {
        $rule = clone $this;
        array_push($rule->forbidden, ...self::normalize($namespaces));

        return $rule;
    }

    public function describe(): string
    {
        $parts = [];
        if ($this->restricted) {
            $parts[] = 'may depend only on ' . ($this->allowed === [] ? 'itself' : implode(', ', $this->allowed));
        }
        if ($this->forbidden !== []) {
            $parts[] = 'must not depend on ' . implode(', ', $this->forbidden);
        }

        return $this->namespace . ' ' . ($parts === [] ? 'has no constraints' : implode(' and ', $parts));
    }

    /**
     * @return list<Violation> Ordered by file and line
     */
    public function check(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("{$directory} is not a directory.");
        }

        $violations = [];
        foreach (self::files($directory) as $file) {
            foreach (DependencyReader::read($file) as [$namespace, $dependency, $line]) {
                if (!self::within($namespace, $this->namespace) || !$this->breaks($dependency)) {
                    continue;
                }
                $violations[] = new Violation($file, $line, $namespace, $dependency, $this->describe());
            }
        }

        return $violations;
    }

    private function breaks(string $dependency): bool
    {
        if (!str_contains($dependency, '\\') || self::within($dependency, $this->namespace)) {
            return false;
        }

        foreach ($this->forbidden as $forbidden) {
            if (self::within($dependency, $forbidden)) {
                return true;
            }
        }

        if (!$this->restricted) {
            return false;
        }
        foreach ($this->allowed as $allowed) {
            if (self::within($dependency, $allowed)) {
                return false;
            }
        }

        return true;
    }

    private static function within(string $name, string $namespace): bool
    {
        return $name === $namespace || str_starts_with($name, $namespace . '\\');
    }

    /**
     * @param array<string> $namespaces
     *
     * @return list<string>
     */
    private static function normalize(array $namespaces): array
    {
        return array_values(array_filter(array_map(static fn (string $ns): string => trim($ns, '\\'), $namespaces), static fn (string $ns): bool => $ns !== ''));
    }

    /**
     * @return list<string>
     */
    private static function files(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }
}
