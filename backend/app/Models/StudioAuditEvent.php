<?php

namespace App\Models;

use App\Enums\AuditAction;
use Database\Factories\StudioAuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Step 34 §3 — an append-only impersonation-audit record. PLATFORM-OWNED: it carries
 * NO BelongsToShop (it spans every shop, and the /studio audit page reads it
 * cross-shop with no `withoutTenancy()`). `shop_id` names the impersonated shop as a
 * plain FK — it is never a tenant scope; record isolation stays BelongsToShop's job.
 * Only `created_at`; rows are never updated (the write guard skips this model, so
 * logging a write never re-triggers the guard).
 */
#[Fillable(['actor_id', 'shop_id', 'action', 'subject_type', 'subject_id', 'ip_address', 'user_agent', 'metadata'])]
class StudioAuditEvent extends Model
{
    /** @use HasFactory<StudioAuditEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'metadata' => 'array',
        ];
    }

    /** The studio operator who acted. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** The impersonated shop (plain FK, not a tenant scope). */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** The written record, when the event is a write. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
