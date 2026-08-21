<?php

namespace App\Livewire;

use App\Enums\AuditAction;
use App\Support\AuditLogger;
use App\Support\Impersonation;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Step 34 §3 — the persistent impersonation banner shown across the /admin panel
 * while a studio operator is in a shop that isn't theirs, so they are never unsure
 * whose data is on screen. Its "Take control" action is the ONE explicit, audited
 * step that lifts read-only; "Return to read-only" drops it again. It renders nothing
 * for a normal owner (Impersonation is inactive), so it is invisible in ordinary use.
 */
class ImpersonationBanner extends Component
{
    public function takeControl(Impersonation $impersonation, AuditLogger $logger): void
    {
        // The TakeControl permission is a nominal Shield gate (studio_admin, the only
        // impersonator, bypasses it as super_admin) — the real guardrail is the
        // read-only default + this explicit, audited step. Checked anyway so the
        // intent is expressed through Shield, not just code.
        if (! $impersonation->active() || $impersonation->hasControl() || auth()->user()?->can('TakeControl') !== true) {
            return;
        }

        $impersonation->takeControl();
        $logger->log(AuditAction::TakeControl, $impersonation->shop());

        Notification::make()
            ->warning()
            ->title('You have taken control of this shop')
            ->body('Changes you make from here are recorded in the studio audit log.')
            ->send();
    }

    public function release(Impersonation $impersonation): void
    {
        $impersonation->release();

        Notification::make()
            ->success()
            ->title('Back to read-only')
            ->send();
    }

    public function render(Impersonation $impersonation): View
    {
        return view('livewire.impersonation-banner', [
            'active' => $impersonation->active(),
            'inControl' => $impersonation->hasControl(),
            'shopName' => $impersonation->shop()?->name,
        ]);
    }
}
