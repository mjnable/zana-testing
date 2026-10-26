<?php

declare(strict_types=1);

namespace Zana\Testing;

use PHPUnit\Framework\Assert;

/**
 * A form read from a page, with its current field values: hidden fields
 * (such as CSRF tokens) included, unchecked boxes and buttons left out.
 *
 *     $this->browser()->form('/en/contact')
 *         ->fill(['name' => 'Ada'])
 *         ->check('consent')
 *         ->check('needs[]', 'website')
 *         ->choose('budget', 'medium')
 *         ->submit()
 *         ->assertRedirect();
 *
 * Checkboxes and radio buttons are known even when nothing is ticked, with
 * the values the page offers; ticking a box or a value the page does not
 * have fails, as a visitor could not do it either.
 */
final readonly class TestForm
{
    /**
     * @param array<string, string|list<string>> $fields The values to send; a "name[]" list under "name"
     * @param array<string, array{type: 'checkbox'|'radio', values: list<string>, list: bool}> $choices
     *        Every checkbox and radio button on the page, ticked or not, by field name
     */
    public function __construct(
        private HttpTestClient $client,
        public string $action,
        public string $method,
        public array $fields,
        public array $choices = [],
    ) {
    }

    /**
     * Read the first form matching $selector ("form" by default) in an HTML page.
     */
    public static function fromResponse(HttpTestClient $client, TestResponse $page, string $uri, ?string $selector = null): self
    {
        $form = $page->document()->querySelector($selector ?? 'form');
        if ($form === null) {
            Assert::fail('No form matches "' . ($selector ?? 'form') . "\" on {$uri}.");
        }

        $fields = [];
        $choices = [];
        foreach ($form->querySelectorAll('input[name], textarea[name], select[name]') as $field) {
            $name = (string) $field->getAttribute('name');
            $list = str_ends_with($name, '[]');
            $key = $list ? substr($name, 0, -2) : $name;
            $type = strtolower((string) ($field->getAttribute('type') ?: 'text'));

            if (strtolower($field->tagName) === 'input' && ($type === 'checkbox' || $type === 'radio')) {
                $choices[$key] = [
                    'type' => $type,
                    'values' => [...($choices[$key]['values'] ?? []), self::choiceValue($field, $type)],
                    'list' => $list,
                ];
            }

            $value = match (strtolower($field->tagName)) {
                'textarea' => (string) $field->textContent,
                'select' => self::selected($field),
                default => self::inputValue($field),
            };
            if ($value === null) {
                continue;
            }
            if ($list) {
                $existing = $fields[$key] ?? [];
                $fields[$key] = [...(is_array($existing) ? $existing : [$existing]), $value];
            } else {
                $fields[$key] = $value;
            }
        }

        $action = (string) $form->getAttribute('action');
        $method = strtoupper((string) ($form->getAttribute('method') ?: 'GET'));

        return new self($client, $action === '' ? $uri : $action, $method, $fields, $choices);
    }

    /**
     * Change field values; the other fields keep theirs. Only fields the page
     * has are accepted. For checkboxes and radio buttons, the values must be
     * ones the page offers: a list for a "name[]" group (it replaces the
     * ticked boxes), one value otherwise.
     *
     * @param array<string, string|list<string>> $fields
     */
    public function fill(array $fields): self
    {
        $values = $this->fields;
        foreach ($fields as $name => $value) {
            $key = self::key($name);
            if (isset($this->choices[$key])) {
                $choice = $this->choices[$key];
                $wanted = is_array($value) ? $value : [$value];
                if (!$choice['list'] && count($wanted) !== 1) {
                    Assert::fail("The field \"{$key}\" takes one value, not a list.");
                }
                foreach ($wanted as $item) {
                    $this->assertOffered($key, $item);
                }
                if ($wanted === []) {
                    unset($values[$key]);
                } else {
                    $values[$key] = $choice['list'] ? array_values(array_unique($wanted)) : $wanted[0];
                }
                continue;
            }
            if (!array_key_exists($key, $this->fields)) {
                Assert::fail("The form has no field \"{$name}\"; it has: " . implode(', ', $this->names()) . '.');
            }
            $values[$key] = $value;
        }

        return $this->with($values);
    }

    /**
     * Tick a checkbox. For a "name[]" group or several boxes of one name, $value
     * says which; without it, the field must have exactly one box.
     */
    public function check(string $name, ?string $value = null): self
    {
        $key = self::key($name);
        $value = $this->boxValue($key, $value, 'checkbox');
        $values = $this->fields;

        if ($this->choices[$key]['list']) {
            $ticked = $values[$key] ?? [];
            $values[$key] = array_values(array_unique([...(is_array($ticked) ? $ticked : [$ticked]), $value]));
        } else {
            $values[$key] = $value;
        }

        return $this->with($values);
    }

    /**
     * Untick a checkbox, chosen as for check(). Unticking a box that is not
     * ticked changes nothing.
     */
    public function uncheck(string $name, ?string $value = null): self
    {
        $key = self::key($name);
        $value = $this->boxValue($key, $value, 'checkbox');
        $values = $this->fields;

        if ($this->choices[$key]['list']) {
            $ticked = $values[$key] ?? [];
            $left = array_values(array_filter(is_array($ticked) ? $ticked : [$ticked], static fn (string $item): bool => $item !== $value));
            if ($left === []) {
                unset($values[$key]);
            } else {
                $values[$key] = $left;
            }
        } elseif (($values[$key] ?? null) === $value) {
            unset($values[$key]);
        }

        return $this->with($values);
    }

    /**
     * Select a radio button; the others of its group are unselected.
     */
    public function choose(string $name, string $value): self
    {
        $key = self::key($name);
        $values = $this->fields;
        $values[$key] = $this->boxValue($key, $value, 'radio');

        return $this->with($values);
    }

    public function submit(): TestResponse
    {
        if ($this->method === 'GET') {
            $separator = str_contains($this->action, '?') ? '&' : '?';

            return $this->client->get($this->action . $separator . http_build_query($this->fields), headers: ['Accept' => 'text/html']);
        }

        return $this->client->submit($this->action, $this->fields, $this->method);
    }

    /**
     * @param array<string, string|list<string>> $fields
     */
    private function with(array $fields): self
    {
        return new self($this->client, $this->action, $this->method, $fields, $this->choices);
    }

    /**
     * The value of the box or button to tick, after checking the page has it.
     *
     * @param 'checkbox'|'radio' $type
     */
    private function boxValue(string $key, ?string $value, string $type): string
    {
        $choice = $this->choices[$key] ?? null;
        if ($choice === null || $choice['type'] !== $type) {
            $kind = $type === 'checkbox' ? 'checkbox' : 'radio button';
            Assert::fail("The form has no {$kind} \"{$key}\"" . ($choice === null ? '' : "; it is a {$choice['type']}") . '.');
        }
        if ($value === null) {
            if (count($choice['values']) !== 1) {
                Assert::fail("The form has " . count($choice['values']) . " \"{$key}\" boxes; say which: " . implode(', ', $choice['values']) . '.');
            }

            return $choice['values'][0];
        }
        $this->assertOffered($key, $value);

        return $value;
    }

    private function assertOffered(string $key, string $value): void
    {
        $offered = $this->choices[$key]['values'] ?? [];
        if (!in_array($value, $offered, true)) {
            Assert::fail("The form has no \"{$key}\" option with the value \"{$value}\"; it has: " . implode(', ', $offered) . '.');
        }
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_values(array_unique([...array_keys($this->fields), ...array_keys($this->choices)]));
    }

    private static function key(string $name): string
    {
        return str_ends_with($name, '[]') ? substr($name, 0, -2) : $name;
    }

    private static function choiceValue(\Dom\Element $input, string $type): string
    {
        return (string) ($input->getAttribute('value') ?? ($type === 'checkbox' ? 'on' : ''));
    }

    private static function inputValue(\Dom\Element $input): ?string
    {
        $type = strtolower((string) ($input->getAttribute('type') ?: 'text'));
        if (in_array($type, ['submit', 'button', 'image', 'reset', 'file'], true)) {
            return null;
        }
        if (($type === 'checkbox' || $type === 'radio') && !$input->hasAttribute('checked')) {
            return null;
        }

        return (string) ($input->getAttribute('value') ?? ($type === 'checkbox' ? 'on' : ''));
    }

    private static function selected(\Dom\Element $select): ?string
    {
        $option = $select->querySelector('option[selected]') ?? $select->querySelector('option');

        return $option === null ? null : (string) ($option->getAttribute('value') ?? $option->textContent);
    }
}
