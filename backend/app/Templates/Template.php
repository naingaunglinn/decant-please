<?php

namespace App\Templates;

use App\Enums\OrderStatus;

/**
 * A shop category, defined in code (step 37; AGENTS.md P4 — never DB-editable).
 * A template says what a product of its kind carries: its attributes, the names of
 * its variant options, its order-status labels (step 39) and its default modules
 * (step 41). Products store their template key (products.template); a shop's
 * default is shop_settings.template. Registered in Templates.
 */
abstract class Template
{
    /** The stored key — products.template, shop_settings.template. Never rename one. */
    abstract public function key(): string;

    /** What the studio calls this category when registering a shop ("Clothing"). */
    public function name(): string
    {
        return ucfirst(str_replace('_', ' ', $this->key()));
    }

    /** The roadmap group (prompts/43-cornerarea-roadmap.md). A shop mixes templates only within one group. */
    abstract public function group(): int;

    /** @return list<Attribute> in admin and storefront display order */
    abstract public function attributes(): array;

    /**
     * The attribute a storefront shows beside a product's name ("Aventus EDP"), or
     * null for none. Name one the template marks required, so every product has it.
     */
    public function headline(): ?string
    {
        return null;
    }

    /** @return list<string> variant option names, e.g. ["Size"] or ["Size", "Color"] */
    abstract public function variantOptions(): array;

    /**
     * The unit a variant's size is measured in, or null when its options are free
     * text (step 38). Decant is "ml": a variant is a size_ml, and the ml stock and
     * cost screens apply. With null, a variant is its option values ("M / Blue")
     * and has no size_ml — the admin asks for the options instead.
     */
    public function measure(): ?string
    {
        return null;
    }

    /**
     * Whether every product needs a brand (step 38b). A perfume always has a house;
     * most clothes a social seller sells have none, so clothing leaves it optional
     * and the API sends `brand: null`.
     */
    public function brandRequired(): bool
    {
        return true;
    }

    /**
     * Whether a brand's type (designer / niche) means something to this category's
     * buyers (step 38b). Only perfume: /meta sends no `brand_types` otherwise, so
     * the storefront shows no brand-type filter or pill.
     */
    public function brandTypes(): bool
    {
        return false;
    }

    /** Whether each variant may carry its own photo (a photo per colour). */
    public function variantPhotos(): bool
    {
        return false;
    }

    /** The admin heading over a product's variants. */
    public function variantsHeading(): string
    {
        return 'Options & prices';
    }

    /** Admin words for a product of this template: [singular, plural]. */
    abstract public function productNouns(): array;

    /**
     * Order status value => the word this category's seller and buyer use (step 39).
     * The states are fixed (AGENTS.md P4); only their labels vary. Override
     * preparedLabel() for the usual case — the rest are category-free.
     *
     * @return array<string, string>
     */
    public function statusLabels(): array
    {
        $labels = [];

        foreach (OrderStatus::cases() as $status) {
            $labels[$status->value] = $status->defaultLabel();
        }

        $labels[OrderStatus::Prepared->value] = $this->preparedLabel();

        return $labels;
    }

    /** What "prepared" means here: Decanted, Packed, Baked. */
    public function preparedLabel(): string
    {
        return OrderStatus::Prepared->defaultLabel();
    }

    /** The admin's name for orders.prep_date — the day the order is made ready. */
    public function prepDateLabel(): string
    {
        return 'Prep date';
    }

    /** The hint under that date on the order form. */
    public function prepDateHelp(): string
    {
        return 'The day you get this order ready.';
    }

    /** @return list<string> modules on by default (read by step 41) */
    abstract public function defaultModules(): array;

    public function attribute(string $key): ?Attribute
    {
        foreach ($this->attributes() as $attribute) {
            if ($attribute->key === $key) {
                return $attribute;
            }
        }

        return null;
    }

    /** @return list<Attribute> */
    public function filterable(): array
    {
        return array_values(array_filter($this->attributes(), fn (Attribute $a): bool => $a->filterable));
    }

    /**
     * The lowercase text `q` searches (products.search_text): brand, name, and every
     * searchable attribute. Pure — the product saving hook and the step-37 backfill
     * migration both call it, so the two can't drift. Lowercased here in PHP
     * (mb_strtolower) because SQLite's LOWER() folds ASCII only; the search lowercases
     * its needle the same way and never wraps the column in LOWER().
     *
     * @param  array<string, mixed>  $values  products.attributes
     */
    public function searchText(?string $brandName, string $name, array $values): string
    {
        $parts = [$brandName, $name];

        foreach ($this->attributes() as $attribute) {
            if ($attribute->searchable) {
                $parts[] = $attribute->display($values[$attribute->key] ?? null);
            }
        }

        return mb_strtolower(implode(' ', array_filter($parts, fn ($part): bool => filled($part))));
    }
}
