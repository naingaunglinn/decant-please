<?php

namespace App\Templates;

/**
 * One catalog attribute a template defines (step 37) — "Concentration", "Material".
 * Values live in products.attributes (jsonb), keyed by $key. Code-defined, never
 * DB-editable (P4). Build one with select() / text() / number().
 *
 * - filterable: the storefront filters by it (/meta `filters`, ?{key}= on /products)
 * - searchable: its value goes into products.search_text, so `q` finds it
 * - translatable: a flag for future Burmese values; no translated data exists yet
 * - long: a multi-line field in the admin (Textarea rather than TextInput)
 */
final readonly class Attribute
{
    public const SELECT = 'select';

    public const TEXT = 'text';

    public const NUMBER = 'number';

    /** @param  array<string, string>  $options  value => label, for select attributes */
    private function __construct(
        public string $key,
        public string $label,
        public string $type,
        public array $options = [],
        public bool $required = false,
        public bool $filterable = false,
        public bool $searchable = false,
        public bool $translatable = false,
        public bool $long = false,
        public ?string $help = null,
    ) {}

    /** @param  array<string, string>  $options  value => label */
    public static function select(string $key, string $label, array $options, bool $required = false, bool $filterable = false, ?string $help = null): self
    {
        return new self($key, $label, self::SELECT, $options, required: $required, filterable: $filterable, help: $help);
    }

    public static function text(string $key, string $label, bool $required = false, bool $filterable = false, bool $searchable = false, bool $translatable = false, bool $long = false, ?string $help = null): self
    {
        return new self($key, $label, self::TEXT, required: $required, filterable: $filterable, searchable: $searchable, translatable: $translatable, long: $long, help: $help);
    }

    public static function number(string $key, string $label, bool $required = false, ?string $help = null): self
    {
        return new self($key, $label, self::NUMBER, required: $required, help: $help);
    }

    /** What a customer reads for a stored value: a select's label, else the value itself. */
    public function display(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->type === self::SELECT ? ($this->options[$value] ?? (string) $value) : (string) $value;
    }
}
