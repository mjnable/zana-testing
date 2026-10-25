<?php

declare(strict_types=1);

namespace Zana\Testing;

use PHPUnit\Framework\Assert;

/**
 * A form read from a page, with its current field values: hidden fields
 * (such as CSRF tokens) included, unchecked boxes and buttons left out.
 *
 *     $this->browser()->form('/en/contact')->fill(['name' => 'Ada'])->submit()->assertRedirect();
 */
final readonly class TestForm
{
    /**
     * @param array<string, string|list<string>> $fields
     */
    public function __construct(
        private HttpTestClient $client,
        public string $action,
        public string $method,
        public array $fields,
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
        foreach ($form->querySelectorAll('input[name], textarea[name], select[name]') as $field) {
            $name = (string) $field->getAttribute('name');
            $value = match (strtolower($field->tagName)) {
                'textarea' => (string) $field->textContent,
                'select' => self::selected($field),
                default => self::inputValue($field),
            };
            if ($value === null) {
                continue;
            }
            if (str_ends_with($name, '[]')) {
                $list = $fields[substr($name, 0, -2)] ?? [];
                $fields[substr($name, 0, -2)] = [...(is_array($list) ? $list : [$list]), $value];
            } else {
                $fields[$name] = $value;
            }
        }

        $action = (string) $form->getAttribute('action');
        $method = strtoupper((string) ($form->getAttribute('method') ?: 'GET'));

        return new self($client, $action === '' ? $uri : $action, $method, $fields);
    }

    /**
     * Change field values; the other fields keep theirs.
     *
     * @param array<string, string|list<string>> $fields
     */
    public function fill(array $fields): self
    {
        foreach (array_keys($fields) as $name) {
            if (!array_key_exists($name, $this->fields)) {
                Assert::fail("The form has no field \"{$name}\"; it has: " . implode(', ', array_keys($this->fields)) . '.');
            }
        }

        return new self($this->client, $this->action, $this->method, [...$this->fields, ...$fields]);
    }

    public function submit(): TestResponse
    {
        if ($this->method === 'GET') {
            $separator = str_contains($this->action, '?') ? '&' : '?';

            return $this->client->get($this->action . $separator . http_build_query($this->fields), headers: ['Accept' => 'text/html']);
        }

        return $this->client->submit($this->action, $this->fields, $this->method);
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
