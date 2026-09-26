<?php

namespace App\Design;

use App\Enums\DesignSource;
use App\Exceptions\TenantNotSetException;
use App\Models\ShopDesign;
use App\Models\ShopSetting;
use App\Models\User;
use App\Support\TenantContext;
use App\Templates\Templates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * The one writer of a shop's storefront designs (step 46; AGENTS.md P4). The
 * Design page, the 46c form and the row-14 AI editor all call it.
 *
 * Rows are append-only: create() inserts, nothing updates. publish() points
 * shop_settings.published_design_id at a row, so undo is publishing an older one.
 * Scoping: the current tenant's rows only — every lookup goes through the
 * BelongsToShop scope, so another shop's id is a not-found, never a pointer.
 */
final class Designs
{
    /** Validate $config and insert it as a new row. Does not publish. */
    public static function create(
        array $config,
        DesignSource $source,
        ?User $by = null,
        ?string $prompt = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
    ): ShopDesign {
        $shopId = app(TenantContext::class)->id() ?? throw new TenantNotSetException(ShopDesign::class);

        return ShopDesign::create([
            'config' => DesignConfig::validate($config, $shopId),
            'source' => $source,
            'prompt' => $prompt,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'created_by' => $by?->id,
        ]);
    }

    /** Make one of this shop's designs live. Another shop's id throws ModelNotFoundException. */
    public static function publish(int $designId): ShopDesign
    {
        $design = ShopDesign::query()->findOrFail($designId);

        // Saved through the model, so ShopSetting::booted busts this shop's cached /meta.
        $settings = ShopSetting::current();
        $settings->published_design_id = $design->id;
        $settings->save();

        return $design;
    }

    /** Start from one of the shop template's presets: a new row, live at once. */
    public static function usePreset(string $key, ?User $by = null): ShopDesign
    {
        $presets = Presets::for(Templates::shopDefaultKey());

        if (! array_key_exists($key, $presets)) {
            throw new InvalidArgumentException("\"{$key}\" is not one of this shop's presets.");
        }

        return DB::transaction(fn (): ShopDesign => self::publish(
            self::create($presets[$key], DesignSource::Preset, $by)->id,
        ));
    }

    public static function published(): ?ShopDesign
    {
        // currentOrNull, never current(): a read must not create the settings row.
        $id = ShopSetting::currentOrNull()?->published_design_id;

        return $id !== null ? ShopDesign::query()->find($id) : null;
    }

    /** @return array<string, mixed> the config the storefront renders: the published row, else the template's Clean preset */
    public static function live(): array
    {
        return self::published()?->config ?? Presets::default(Templates::shopDefaultKey());
    }

    /**
     * The live design as the storefront gets it in /meta (46b): each section's
     * `image` path becomes its URL on the media disk, like the payment QR. The
     * stored config never holds a URL; only this response does.
     *
     * Null when the design can't be built (no readable Clean preset, a disk
     * that can't make URLs): the design can't take /meta (and so checkout)
     * down — the storefront renders its plain fallback, and the error is
     * reported. A missing tenant still throws: that is a wiring bug.
     *
     * @return array<string, mixed>|null
     */
    public static function forStorefront(): ?array
    {
        try {
            $config = self::live();
            $disk = Storage::disk(config('filesystems.media_disk'));

            foreach ($config['sections'] ?? [] as $i => $section) {
                if (is_string($section['props']['image'] ?? null)) {
                    $config['sections'][$i]['props']['image'] = $disk->url($section['props']['image']);
                }
            }

            return $config;
        } catch (TenantNotSetException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** What the admin calls a design: "Clean — default", "Preset: Bold", "Edited". */
    public static function describe(?ShopDesign $design): string
    {
        if ($design === null) {
            return self::baseLabel(Presets::DEFAULT_BASE).' — default · မူလ';
        }

        return $design->source === DesignSource::Preset
            ? 'Preset: '.self::baseLabel($design->config['base'] ?? '')
            : $design->source->label();
    }

    private static function baseLabel(string $base): string
    {
        return Theme::bases()[$base]['label'] ?? $base;
    }
}
