<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a sale looks where it is announced — the panel under a product's price
 * and the "Current promotions" cards: what stands on its tile (`badge`: worked
 * out from the sale, its own picture, its own words, an icon, or nothing) and
 * its colour (`tint`, one of the named hues; empty means "by id", as before).
 * The picture is a media collection of its own (`badge`), not the banner: a
 * 52-pixel square is a different drawing from a 1200-pixel strip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_promotions_promotions', function (Blueprint $table) {
            $table->string('badge', 16)->default('auto');
            $table->jsonb('badge_text')->nullable();
            $table->string('badge_icon', 64)->nullable();
            $table->string('tint', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_promotions_promotions', function (Blueprint $table) {
            $table->dropColumn(['badge', 'badge_text', 'badge_icon', 'tint']);
        });
    }
};
