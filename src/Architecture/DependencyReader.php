<?php

declare(strict_types=1);

namespace Zana\Testing\Architecture;

/**
 * Reads the dependencies of a PHP file with token_get_all(): use imports and
 * fully qualified names, each with the namespace it appears in and its line.
 *
 * @internal
 */
final class DependencyReader
{
    /** @var list<array{int|string, string, int}> */
    private array $tokens;

    private int $position = 0;

    private function __construct(string $code)
    {
        $this->tokens = array_values(array_map(
            static fn (array|string $token): array => is_array($token) ? [$token[0], $token[1], $token[2]] : [$token, $token, 0],
            array_filter(token_get_all($code), static fn (array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)),
        ));
    }

    /**
     * @return list<array{string, string, int}> [namespace, dependency, line]
     */
    public static function read(string $file): array
    {
        $code = file_get_contents($file);
        if ($code === false) {
            throw new \RuntimeException("Cannot read {$file}.");
        }

        return new self($code)->dependencies();
    }

    /**
     * @return list<array{string, string, int}>
     */
    private function dependencies(): array
    {
        $found = [];
        $namespace = '';
        $namespaceDepth = 0;
        $depth = 0;

        for ($count = count($this->tokens); $this->position < $count; $this->position++) {
            [$id, $text, $line] = $this->tokens[$this->position];

            switch ($id) {
                case '{':
                case T_CURLY_OPEN:
                case T_DOLLAR_OPEN_CURLY_BRACES:
                    $depth++;
                    break;
                case '}':
                    $depth--;
                    break;
                case T_NAMESPACE:
                    $next = $this->tokens[$this->position + 1] ?? null;
                    if ($next !== null && in_array($next[0], [T_STRING, T_NAME_QUALIFIED, '{'], true)) {
                        $namespace = $next[0] === '{' ? '' : $next[1];
                        $namespaceDepth = $this->braced() ? $depth + 1 : $depth;
                    }
                    break;
                case T_USE:
                    if ($depth === $namespaceDepth && ($this->tokens[$this->position + 1][0] ?? null) !== '(') {
                        foreach ($this->imports() as $import) {
                            $found[] = [$namespace, $import, $line];
                        }
                    }
                    break;
                case T_NAME_FULLY_QUALIFIED:
                    $found[] = [$namespace, ltrim($text, '\\'), $line];
                    break;
            }
        }

        return $found;
    }

    /**
     * Whether the namespace declaration at the current position opens a block.
     */
    private function braced(): bool
    {
        for ($i = $this->position + 1, $count = count($this->tokens); $i < $count; $i++) {
            if ($this->tokens[$i][0] === ';') {
                return false;
            }
            if ($this->tokens[$i][0] === '{') {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse the import statement after "use", leaving the position on its ";".
     *
     * @return list<string>
     */
    private function imports(): array
    {
        $imports = [];
        $prefix = '';
        $name = '';
        $inGroup = false;
        $aliasing = false;

        for ($this->position++, $count = count($this->tokens); $this->position < $count; $this->position++) {
            [$id, $text] = $this->tokens[$this->position];

            if ($id === ';') {
                break;
            }

            switch ($id) {
                case T_FUNCTION:
                case T_CONST:
                    break;
                case T_AS:
                    $aliasing = true;
                    break;
                case T_STRING:
                case T_NAME_QUALIFIED:
                case T_NAME_FULLY_QUALIFIED:
                    if (!$aliasing) {
                        $name = ltrim($text, '\\');
                    }
                    break;
                case T_NS_SEPARATOR:
                    break;
                case '{':
                    $prefix = $name;
                    $name = '';
                    $inGroup = true;
                    break;
                case ',':
                case '}':
                    if ($name !== '') {
                        $imports[] = $inGroup ? "{$prefix}\\{$name}" : $name;
                    }
                    $name = '';
                    $aliasing = false;
                    if ($id === '}') {
                        $inGroup = false;
                        $prefix = '';
                    }
                    break;
            }
        }

        if ($name !== '') {
            $imports[] = $inGroup ? "{$prefix}\\{$name}" : $name;
        }

        return $imports;
    }
}
