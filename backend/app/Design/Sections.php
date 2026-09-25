<?php

namespace App\Design;

/**
 * The section library (step 46; ADR-0005): every home-page section a design
 * config may list, with the props each carries. Defined in code (AGENTS.md P4).
 * A type key is stored in shop_designs.config — never rename one; a retired type
 * stays renderable-as-skipped, because an old row may still name it.
 *
 * The header and footer are the fixed frame, not sections. Group-specific
 * sections (menu board, booking widget…) arrive with the rows whose data they
 * show (prompts/46-design-system.md, "Deliberately not built").
 */
final class Sections
{
    /** Prop kinds DesignConfig knows how to check. */
    public const TEXT = 'text';

    public const IMAGE = 'image';

    public const LINK = 'link';

    public const MAP_LINK = 'map_link';

    public const ITEMS = 'items';

    /**
     * type => label and props. A prop is ['kind' => …] plus `max` (text length or
     * item count), `multiline` (text) and `props` (each item's, for items).
     *
     * @return array<string, array{label: string, props: array<string, array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            'announcement' => ['label' => 'Announcement bar', 'props' => [
                'text' => self::text(120),
            ]],
            'hero' => ['label' => 'Hero', 'props' => [
                'title' => self::text(80),
                'subtitle' => self::text(240),
                'button' => self::text(30),
                // A second, quieter button to order tracking; empty hides it.
                'track_button' => self::text(30),
                'image' => ['kind' => self::IMAGE],
            ]],
            'featured' => ['label' => 'Featured products', 'props' => [
                'title' => self::text(40),
            ]],
            'product_grid' => ['label' => 'Newest products', 'props' => [
                'title' => self::text(40),
            ]],
            'category_nav' => ['label' => 'Categories', 'props' => [
                'title' => self::text(40),
            ]],
            'steps' => ['label' => 'How it works', 'props' => [
                'title' => self::text(40),
                'items' => ['kind' => self::ITEMS, 'max' => 4, 'props' => [
                    'title' => self::text(30),
                    'text' => self::text(160),
                ]],
            ]],
            'tiles' => ['label' => 'Link tiles', 'props' => [
                'items' => ['kind' => self::ITEMS, 'max' => 3, 'props' => [
                    'label' => self::text(30),
                    'text' => self::text(80),
                    'link' => ['kind' => self::LINK],
                ]],
            ]],
            'about' => ['label' => 'About the shop', 'props' => [
                'title' => self::text(60),
                'text' => self::text(1000, multiline: true),
                'image' => ['kind' => self::IMAGE],
            ]],
            'contact' => ['label' => 'Contact & social', 'props' => [
                'title' => self::text(40),
                'text' => self::text(300, multiline: true),
            ]],
            'location' => ['label' => 'Location', 'props' => [
                'title' => self::text(40),
                'address' => self::text(300, multiline: true),
                'map_link' => ['kind' => self::MAP_LINK],
            ]],
            'delivery_fees' => ['label' => 'Delivery fees', 'props' => [
                'title' => self::text(40),
            ]],
            'order_tracking' => ['label' => 'Order tracking', 'props' => [
                'title' => self::text(40),
                'text' => self::text(200),
            ]],
            'recently_viewed' => ['label' => 'Recently viewed', 'props' => []],
        ];
    }

    /** @return array{kind: string, max: int, multiline: bool} */
    private static function text(int $max, bool $multiline = false): array
    {
        return ['kind' => self::TEXT, 'max' => $max, 'multiline' => $multiline];
    }
}
