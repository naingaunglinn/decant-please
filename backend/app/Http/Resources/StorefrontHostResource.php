<?php

namespace App\Http\Resources;

use App\Models\ShopDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The storefront deployment's tenant handshake (ADR-0004): enough to key the API
 * path (slug), brand the page (name), and consolidate SEO (primary_host — the
 * shop's verified primary if one exists, else the requested host, so a redirect
 * target is never a dead domain). Mirrored by StorefrontHost in
 * frontend/src/lib/types.ts — change one, change the other in the same PR.
 *
 * @mixin ShopDomain
 */
class StorefrontHostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $primary = $this->shop->domains
            ->first(fn (ShopDomain $domain): bool => $domain->is_primary && $domain->verified_at !== null);

        return [
            'slug' => $this->shop->slug,
            'name' => $this->shop->name,
            'host' => $this->host,
            'is_primary' => $this->is_primary,
            'primary_host' => $primary?->host ?? $this->host,
        ];
    }
}
