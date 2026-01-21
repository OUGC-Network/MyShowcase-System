<?php

declare(strict_types=1);

namespace MyShowcase\ValueObjects;

use InvalidArgumentException;

use function MyShowcase\Plugin\Functions\postParser;

final readonly class EntryCustomField
{
    private array $parser_options;

    public function __construct(
        private string $field_key,
        private int|string $field_value = ''
    ) {
    }

    public function getKey(): string
    {
        return $this->field_key;
    }

    public function getValue(): int|string
    {
        return $this->field_value;
    }

    public function getParsedValue(): string
    {
        return postParser()->parse_message($this->field_value, $this->parser_options);
    }

    public function setParserOptions(array $options): void
    {
        $this->parser_options = $options;
    }

    private function validate(array $field_definition): void
    {
        if (empty($this->name)) {
            throw new InvalidArgumentException('Field name cannot be empty');
        }

        if ($field_definition['is_required'] && empty($this->field_value)) {
            throw new InvalidArgumentException("Field '{$this->field_key}' is required");
        }
    }
}