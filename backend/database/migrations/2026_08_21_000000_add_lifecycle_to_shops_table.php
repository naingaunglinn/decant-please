<?php

use App\Enums\ShopStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 34 §1 — shop lifecycle. Replaces the overloaded `is_active` boolean with a
 * `status` enum (onboarding → live → suspended → archived) plus suspension
 * metadata. `is_active` is dropped; the model exposes it as a derived read-only
 * accessor (status === live).
 *
 * Backfill (confirmed against the repository, issue #96): `is_active = true → live`,
 * `is_active = false → onboarding`. The repo does NOT prove that `false` means
 * "suspended" — PRODUCT.md open-question 2 records that the boolean conflates
 * "not launched", "paused", and "suspended" — and `suspended` requires a
 * reason/actor/timestamp a legacy row cannot supply. `onboarding` is the lossless
 * non-serving default; every observed shop is `is_active = true → live` regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('status')->nullable()->after('name');
            $table->string('suspended_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('shops')->where('is_active', true)->update(['status' => ShopStatus::Live->value]);
        DB::table('shops')->where('is_active', false)->update(['status' => ShopStatus::Onboarding->value]);

        Schema::table('shops', function (Blueprint $table) {
            $table->string('status')->nullable(false)->default(ShopStatus::Onboarding->value)->change();
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->index('status');
        });

        // Drop is_active's index before the column — SQLite errors on an orphaned index.
        Schema::table('shops', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->index()->after('name');
        });

        DB::table('shops')->update(['is_active' => false]);
        DB::table('shops')->where('status', ShopStatus::Live->value)->update(['is_active' => true]);

        Schema::table('shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropColumn(['status', 'suspended_reason', 'suspended_at']);
        });
    }
};
