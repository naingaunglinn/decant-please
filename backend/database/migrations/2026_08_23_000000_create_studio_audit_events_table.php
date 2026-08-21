<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 34 §3 — studio_audit_events. A PLATFORM-OWNED, append-only record of a studio
 * operator entering and operating a shop they don't belong to (impersonation).
 *
 * Deliberately NOT tenant-owned: it carries no `shop_id` scope via BelongsToShop
 * (like `shops`/`users`/`permissions` — AGENTS.md §8). It spans every shop and is
 * read cross-shop on the /studio panel, so a global tenant scope would be wrong here
 * and reading it needs no `withoutTenancy()`. `shop_id` names the impersonated shop
 * as a plain FK. Only `created_at` — rows are immutable once written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->string('action');
            $table->nullableMorphs('subject');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['shop_id', 'created_at']);
            $table->index('actor_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_audit_events');
    }
};
