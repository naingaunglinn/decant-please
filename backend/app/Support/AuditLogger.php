<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Models\Shop;
use App\Models\StudioAuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Step 34 §3 — the one place a `studio_audit_events` row is written. Centralised so
 * actor / IP / user-agent capture is identical across the three trigger points
 * (panel entry, take-control, and each impersonated write) and never duplicated.
 */
class AuditLogger
{
    public function log(AuditAction $action, ?Shop $shop, ?Model $subject = null, array $metadata = []): StudioAuditEvent
    {
        $request = request();

        return StudioAuditEvent::create([
            'actor_id' => Auth::id(),
            'shop_id' => $shop?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => Str::limit((string) $request?->userAgent(), 480, ''),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
