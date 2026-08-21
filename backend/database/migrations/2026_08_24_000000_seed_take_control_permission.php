<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Step 34 §3 — the `TakeControl` marker permission. studio_admin is Shield
 * super_admin and bypasses it through Gate::before, so this is a NOMINAL gate by
 * design (decision (b)): the real guardrail is read-only-by-default at the model
 * layer plus an explicit, audited take-control step. The permission exists so the
 * intent is expressed in Shield and visible in the role UI. Idempotent; guard `web`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('TakeControl', config('auth.defaults.guard', 'web'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'TakeControl')
            ->where('guard_name', config('auth.defaults.guard', 'web'))
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
