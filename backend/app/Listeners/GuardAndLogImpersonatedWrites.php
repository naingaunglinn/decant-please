<?php

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Exceptions\ReadOnlyImpersonationException;
use App\Models\Concerns\BelongsToShop;
use App\Models\StudioAuditEvent;
use App\Support\AuditLogger;
use App\Support\Impersonation;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Step 34 §3 — the SINGLE enforcement point for read-only impersonation, chosen at
 * the model layer on purpose: custom Filament actions (order accept/reject, mark
 * paid, ManagePayment save, bulk fee/cost) mutate without going through a CRUD
 * policy ability, so a Gate/policy hook would miss them. Every persisted write —
 * whatever the path — passes through Eloquent's saving/deleting/created/updated/
 * deleted events.
 *
 * - saving / deleting: while impersonating read-only, notify + throw
 *   ReadOnlyImpersonationException (a Halt subclass Filament catches → the write is
 *   cancelled and the operator sees a notification, never a 500).
 * - created / updated / deleted: while impersonating with control, write exactly one
 *   audit event per persisted row (bulk of N → N events), each naming its subject.
 *
 * Scope: only tenant-owned models (those using BelongsToShop). StudioAuditEvent is
 * excluded (it is platform-owned and logging must never re-trigger the guard). For
 * a normal owner, a guest API request, or the /studio panel, Impersonation is
 * inactive and both methods early-return — no behaviour change, no audit rows.
 *
 * This is a guardrail + audit trail, NOT a tenant boundary: a write allowed after
 * take-control still lands in the current tenant, stamped shop_id by BelongsToShop.
 *
 * Known boundary — this catches per-model writes only. A raw query-builder / mass
 * write (Model::query()->update(...), ->whereKey(...)->delete(), DB::table(...), or a
 * bulk action with ->fetchSelectedRecords(false)) fires NO Eloquent events and would
 * bypass both the block and the audit. Today every write path is per-model: all ten
 * tenant-owned models (roots and sub-models — OrderItem/DecantPrice/
 * DeliveryTownshipCourier included) use BelongsToShop, Filament's DeleteBulkAction
 * fetches records by default (per-record delete), and every custom bulk action
 * iterates (->each->update / foreach ->save). Any future mass write on a tenant-owned
 * model must route through Eloquent instances (or add its own guard) to stay covered.
 */
class GuardAndLogImpersonatedWrites
{
    /** @var array<class-string, bool> */
    private static array $appliesCache = [];

    public function __construct(
        private Impersonation $impersonation,
        private AuditLogger $logger,
    ) {}

    /** eloquent.saving:* + eloquent.deleting:* — block writes during read-only impersonation. */
    public function guard(string $event, array $payload): void
    {
        if (! $this->applies($payload[0] ?? null)) {
            return;
        }

        if ($this->impersonation->isReadOnly()) {
            Notification::make()
                ->danger()
                ->title('Read-only — you are viewing this shop as Studio')
                ->body('Take control of this shop before making changes.')
                ->send();

            throw new ReadOnlyImpersonationException;
        }
    }

    /** eloquent.created:* + updated:* + deleted:* — log each impersonated write. */
    public function log(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;

        if (! $this->applies($model) || ! $this->impersonation->hasControl()) {
            return;
        }

        $verb = Str::of($event)->after('eloquent.')->before(': ')->toString();

        $this->logger->log(
            AuditAction::forWrite($verb),
            $this->impersonation->shop(),
            $model,
        );
    }

    /** A tenant-owned model the guard applies to (never StudioAuditEvent itself). */
    private function applies(mixed $model): bool
    {
        if (! $model instanceof Model || $model instanceof StudioAuditEvent) {
            return false;
        }

        return self::$appliesCache[$model::class]
            ??= in_array(BelongsToShop::class, class_uses_recursive($model), true);
    }
}
