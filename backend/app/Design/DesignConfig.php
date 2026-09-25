<?php

namespace App\Design;

use Illuminate\Validation\ValidationException;

/**
 * The one validator for a storefront design config (step 46). Every writer —
 * a preset, the Design page, the 46c form, the row-14 AI editor — goes through
 * Designs::create(), which calls this before insert. It runs on write only: an
 * older row keeps rendering after the library changes, because the storefront
 * skips what it doesn't know.
 *
 * What it guarantees: known keys only; colours readable (WCAG 4.5:1); text is
 * capped plain text; a tile link stays on the shop; a map link goes to a maps
 * host over https; an image is a path under THIS shop's design prefix (never a
 * URL, never another shop's file). Missing props are filled with empty defaults,
 * so the renderer always gets a complete shape.
 */
final class DesignConfig
{
    public const VERSION = 1;

    /** Hosts a location section's "Open in Maps" link may point at. goo.gl is not one: it shortened any URL. */
    private const MAP_HOSTS = ['google.com', 'www.google.com', 'maps.google.com', 'maps.app.goo.gl'];

    /** Image types a design may show — never SVG or HTML, which the public disk would serve as-is. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var array<string, list<string>> */
    private array $errors = [];

    private function __construct(private readonly int $shopId) {}

    /**
     * The normalized config, or a ValidationException naming each bad path
     * ("sections.2.props.title").
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(array $input, int $shopId): array
    {
        $validator = new self($shopId);
        $config = $validator->config($input);

        if ($validator->errors !== []) {
            throw ValidationException::withMessages($validator->errors);
        }

        return $config;
    }

    /** Where this shop's design images live on the media disk. */
    public static function imagePrefix(int $shopId): string
    {
        return "shops/{$shopId}/design/";
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function config(array $input): array
    {
        $this->unknownKeys($input, ['version', 'base', 'preset', 'theme', 'sections'], '');

        if (($input['version'] ?? null) !== self::VERSION) {
            $this->fail('version', 'Must be '.self::VERSION.'.');
        }

        $base = $input['base'] ?? null;
        if (! is_string($base) || ! array_key_exists($base, Theme::bases())) {
            $this->fail('base', 'Must be one of: '.implode(', ', array_keys(Theme::bases())).'.');
        }

        $preset = $input['preset'] ?? null;
        if ($preset !== null && (! is_string($preset) || ! preg_match('/^[a-z_]+\.[a-z_]+$/', $preset))) {
            $this->fail('preset', 'Must be a preset key like "decant.clean".');
            $preset = null;
        }

        return [
            'version' => self::VERSION,
            'base' => $base,
            'preset' => $preset,
            'theme' => $this->theme($input['theme'] ?? null),
            'sections' => $this->sections($input['sections'] ?? null),
        ];
    }

    /** @return array{colors: array<string, string|null>, font: mixed} */
    private function theme(mixed $theme): array
    {
        if (! $this->isMap($theme)) {
            $this->fail('theme', 'Must have colors and a font.');
            $theme = [];
        }

        $this->unknownKeys($theme, ['colors', 'font'], 'theme');

        $colors = $theme['colors'] ?? null;
        if (! $this->isMap($colors)) {
            $this->fail('theme.colors', 'Must list '.implode(', ', Theme::COLORS).'.');
            $colors = [];
        }

        $this->unknownKeys($colors, Theme::COLORS, 'theme.colors');

        $normalized = [];
        foreach (Theme::COLORS as $name) {
            $value = $colors[$name] ?? null;
            if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                $normalized[$name] = strtolower($value);
            } else {
                $this->fail("theme.colors.{$name}", 'Must be a colour like #1a2b3c.');
                $normalized[$name] = null;
            }
        }

        foreach (Theme::CONTRAST_PAIRS as [$foreground, $background]) {
            if ($normalized[$foreground] !== null && $normalized[$background] !== null
                && Theme::contrast($normalized[$foreground], $normalized[$background]) < Theme::MIN_CONTRAST) {
                $this->fail("theme.colors.{$foreground}", "Too close to {$background} — it would be hard to read. Pick a darker or lighter colour.");
            }
        }

        $font = $theme['font'] ?? null;
        if (! is_string($font) || ! array_key_exists($font, Theme::fonts())) {
            $this->fail('theme.font', 'Must be one of: '.implode(', ', array_keys(Theme::fonts())).'.');
        }

        return ['colors' => $normalized, 'font' => $font];
    }

    /** @return list<array{type: string, on: bool, props: array<string, mixed>}> */
    private function sections(mixed $sections): array
    {
        $library = Sections::all();

        if (! is_array($sections) || ! array_is_list($sections)) {
            $this->fail('sections', 'Must be a list of sections.');

            return [];
        }

        if (count($sections) > count($library)) {
            $this->fail('sections', 'At most '.count($library).' sections.');

            return [];
        }

        $seen = [];
        $normalized = [];

        foreach ($sections as $index => $section) {
            $path = "sections.{$index}";

            if (! $this->isMap($section)) {
                $this->fail($path, 'Must be a section.');

                continue;
            }

            $this->unknownKeys($section, ['type', 'on', 'props'], $path);

            $type = $section['type'] ?? null;
            if (! is_string($type) || ! array_key_exists($type, $library)) {
                $this->fail("{$path}.type", 'Unknown section.');

                continue;
            }

            if (isset($seen[$type])) {
                $this->fail("{$path}.type", 'This section is already on the page.');

                continue;
            }
            $seen[$type] = true;

            if (! is_bool($section['on'] ?? null)) {
                $this->fail("{$path}.on", 'Must be true or false.');
            }

            $normalized[] = [
                'type' => $type,
                'on' => ($section['on'] ?? null) === true,
                'props' => $this->props($library[$type]['props'], $section['props'] ?? [], "{$path}.props"),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, array<string, mixed>>  $spec
     * @return array<string, mixed>
     */
    private function props(array $spec, mixed $input, string $path): array
    {
        if ($input !== [] && ! $this->isMap($input)) {
            $this->fail($path, 'Must be a set of fields.');
            $input = [];
        }

        $this->unknownKeys($input, array_keys($spec), $path);

        $props = [];
        foreach ($spec as $key => $prop) {
            $props[$key] = $this->value($prop, $input[$key] ?? null, "{$path}.{$key}");
        }

        return $props;
    }

    /** @param  array<string, mixed>  $prop */
    private function value(array $prop, mixed $value, string $path): mixed
    {
        return match ($prop['kind']) {
            Sections::TEXT => $this->text($value, $prop['max'], $prop['multiline'], $path),
            Sections::IMAGE => $this->image($value, $path),
            Sections::LINK => $this->link($value, $path),
            Sections::MAP_LINK => $this->mapLink($value, $path),
            Sections::ITEMS => $this->items($value, $prop, $path),
        };
    }

    private function text(mixed $value, int $max, bool $multiline, string $path): string
    {
        if ($value === null) {
            return '';
        }

        if (! is_string($value)) {
            $this->fail($path, 'Must be text.');

            return '';
        }

        $value = trim(str_replace("\r\n", "\n", $value));
        // Control characters are refused (a newline only where the field is multi-line),
        // and so are the invisible ones that break a line or reverse text — a spoofed
        // phone number. Not every format character: Burmese uses ZWSP and ZWNJ.
        // preg_match returns false on invalid UTF-8, which is refused too.
        $invisible = '\x{7F}-\x{9F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}';
        $control = $multiline ? "/[\\x{00}-\\x{09}\\x{0B}-\\x{1F}{$invisible}]/u" : "/[\\x{00}-\\x{1F}{$invisible}]/u";

        if (preg_match($control, $value) !== 0) {
            $this->fail($path, $multiline ? 'Must be plain text.' : 'Must be plain text on one line.');

            return '';
        }

        if (mb_strlen($value) > $max) {
            $this->fail($path, "At most {$max} characters.");

            return '';
        }

        return $value;
    }

    private function image(mixed $value, string $path): ?string
    {
        if ($value === null) {
            return null;
        }

        $prefix = self::imagePrefix($this->shopId);

        if (! is_string($value) || ! str_starts_with($value, $prefix) || strlen($value) > 255
            || ! preg_match('#^[A-Za-z0-9/_.\-]+$#', $value) || str_contains($value, '..') || str_contains($value, '//')
            || ! in_array(strtolower(pathinfo($value, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true)) {
            $this->fail($path, 'Must be an image uploaded to this shop.');

            return null;
        }

        return $value;
    }

    private function link(mixed $value, string $path): string
    {
        if ($value === null) {
            return '/';
        }

        // A path on the shop itself: one leading slash, then path and query characters,
        // with no empty or dot segment in the path — "/.//evil.example" resolves to
        // "//evil.example", which a redirect would send off the shop.
        if (! is_string($value) || strlen($value) > 200 || ! preg_match('#^/[A-Za-z0-9\-._~/?=&%+]*$#', $value)
            || self::unsafePath(explode('?', $value, 2)[0])) {
            $this->fail($path, 'Must be a page on your shop, like /shop.');

            return '/';
        }

        return $value;
    }

    private function mapLink(mixed $value, string $path): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parts = is_string($value) && strlen($value) <= 300 && filter_var($value, FILTER_VALIDATE_URL)
            ? parse_url($value)
            : false;

        $host = strtolower($parts['host'] ?? '');
        $valid = $parts !== false
            && ($parts['scheme'] ?? null) === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
            && in_array($host, self::MAP_HOSTS, true)
            && ! self::unsafePath($parts['path'] ?? '')
            // Google's own domains only under /maps (google.com/url is a redirector);
            // maps.google.com also takes its bare ?q= form. maps.app.goo.gl is maps-only.
            && ($host === 'maps.app.goo.gl' || self::mapsPath($parts['path'] ?? '', $host === 'maps.google.com'));

        if (! $valid) {
            $this->fail($path, 'Must be a Google Maps link.');

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $prop
     * @return list<array<string, mixed>>
     */
    private function items(mixed $value, array $prop, string $path): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, 'Must be a list.');

            return [];
        }

        if (count($value) > $prop['max']) {
            $this->fail($path, "At most {$prop['max']} items.");

            return [];
        }

        return array_map(
            fn (mixed $item, int $index): array => $this->props($prop['props'], $item, "{$path}.{$index}"),
            $value,
            array_keys($value),
        );
    }

    /** Whether a URL path has an empty or dot segment, or an encoded slash, backslash or dot. */
    private static function unsafePath(string $path): bool
    {
        return str_contains($path, '//')
            || preg_match('#%(2f|5c|2e)#i', $path) === 1
            || preg_match('#(^|/)\.{1,2}(/|$)#', $path) === 1;
    }

    private static function mapsPath(string $path, bool $bareAllowed): bool
    {
        return $path === '/maps' || str_starts_with($path, '/maps/') || ($bareAllowed && in_array($path, ['', '/'], true));
    }

    /**
     * @param  array<mixed>  $input
     * @param  list<string>  $known
     */
    private function unknownKeys(array $input, array $known, string $path): void
    {
        foreach (array_diff(array_keys($input), $known) as $key) {
            $this->fail(ltrim("{$path}.{$key}", '.'), 'Unknown field.');
        }
    }

    /** A JSON object: an array with string keys (an empty one counts). */
    private function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    private function fail(string $path, string $message): void
    {
        $this->errors[$path][] = $message;
    }
}
