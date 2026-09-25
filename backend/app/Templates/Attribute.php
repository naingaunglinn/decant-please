<?php

namespace App\Templates;

use LogicException;

/**
 * One catalog attribute a template defines (step 37) — "Concentration", "Material".
 * Values live in products.attributes (jsonb), keyed by $key. Code-defined, never
 * DB-editable (P4). Build one with select() / text() / number().
 *
 * - filterable: the storefront filters by it (/meta `filters`, ?{key}= on /products)
 * - searchable: its value goes into products.search_text, so `q` finds it
 * - translatable: a flag for future Burmese values; no translated data exists yet
 * - long: a multi-line field in the admin (Textarea rather than TextInput)
 * - list: a comma-separated text the storefront shows as its own section of pills
 *   ("Scent notes"), not as one pill in the product's pill row
 * - section: a free text the storefront shows as its own titled paragraph, line
 *   breaks kept ("Size guide", step 38b)
 *
 * A key is also a /products query parameter when filterable, so it may not be one
 * of the core parameters (RESERVED_KEYS) — a clash would replace their validation.
 */
final readonly class Attribute
{
    public const SELECT = 'select';

    public const TEXT = 'text';

    public const NUMBER = 'number';

    /** /products' own query parameters (ProductController::index), plus brand_type — the storefront's URL name for `type`. */
    public const RESERVED_KEYS = ['q', 'brand', 'type', 'brand_type', 'size', 'min_price', 'max_price', 'featured', 'sort', 'page', 'per_page'];

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
        public bool $list = false,
        public bool $section = false,
        public ?string $help = null,
    ) {
        if (in_array($key, self::RESERVED_KEYS, true)) {
            throw new LogicException("\"{$key}\" is a /products parameter and can't be an attribute key.");
        }
    }

    /** @param  array<string, string>  $options  value => label */
    public static function select(string $key, string $label, array $options, bool $required = false, bool $filterable = false, ?string $help = null): self
    {
        return new self($key, $label, self::SELECT, $options, required: $required, filterable: $filterable, help: $help);
    }

    public static function text(string $key, string $label, bool $required = false, bool $filterable = false, bool $searchable = false, bool $translatable = false, bool $long = false, bool $list = false, bool $section = false, ?string $help = null): self
    {
        return new self($key, $label, self::TEXT, required: $required, filterable: $filterable, searchable: $searchable, translatable: $translatable, long: $long, list: $list, section: $section, help: $help);
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

    /**
     * How the storefront shows this attribute on a product (step 37b): `headline`
     * beside the product's name (and in its pill row), `list` as its own section of
     * pills, `section` as its own paragraph, otherwise `pill` — one pill in the pill row.
     */
    public function show(Template $template): string
    {
        return match (true) {
            $template->headline() === $this->key => 'headline',
            $this->list => 'list',
            $this->section => 'section',
            default => 'pill',
        };
    }
}
