<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 33 — per-shop configuration. shop_settings already carries shop_id (the
 * seam migration) and is scoped by BelongsToShop; this adds the last two config
 * groups that were still process-global env:
 *
 * - Telegram bot_token + admin_chat_id — TEXT because the model encrypts them
 *   (Laravel's `encrypted` cast stores a base64 payload far longer than the
 *   plaintext), nullable so a shop that shares the platform bot leaves them blank.
 * - Social tiktok_url + facebook_url — plain nullable strings.
 *
 * No backfill: the env values stay the platform default (ShopConfig resolves
 * shop row → env → off), so an existing deployment keeps working with these blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->text('bot_token')->nullable();
            $table->text('admin_chat_id')->nullable();
            $table->string('tiktok_url')->nullable();
            $table->string('facebook_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn(['bot_token', 'admin_chat_id', 'tiktok_url', 'facebook_url']);
        });
    }
};
