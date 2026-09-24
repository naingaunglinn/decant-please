<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the transitional `users.is_studio` column. Platform-admin access has read
 * the `studio_admin` role since Step 34 (canAccessPanel / getTenants / canAccessTenant
 * and, in this PR, the last two UI readers) — two answers to "is this a platform
 * admin?" is one too many, so the role becomes the only source of truth (ADR-0003
 * step 8; issue #110).
 *
 * Deploy safety (checked in the PR): no runtime query filters on `is_studio`, and
 * `Model::preventAccessingMissingAttributes()` is off in production — so during the
 * release-phase window every remaining old-code read is `(bool) null` → access
 * denied (fail-closed), never a throw.
 *
 * down() re-derives the flag FROM the role, never the reverse: a user whose
 * `studio_admin` was removed at go-live (design decision, issue #110) must not have
 * platform access handed back by a rollback. So the restore is exactly "has the role
 * → is_studio = true", matching live access rather than resurrecting a stale flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the index before the column — SQLite (the test engine) errors on an
        // orphaned index, the same two-step the lifecycle migration used for
        // is_active. Postgres would drop it with the column, but the split is portable.
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_studio']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_studio');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_studio')->default(false)->index()->after('password');
        });

        // Restore the flag from live access (the studio_admin role), NOT from any
        // remembered column value — the column is gone, and the role is the truth we
        // rolled back to. Non-team guard, FQCN morph type (no morph map on this app).
        $studioUserIds = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'studio_admin')
            ->where('roles.guard_name', config('auth.defaults.guard', 'web'))
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->pluck('model_has_roles.model_id');

        if ($studioUserIds->isNotEmpty()) {
            DB::table('users')->whereIn('id', $studioUserIds)->update(['is_studio' => true]);
        }
    }
};
