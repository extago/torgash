<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clicks on banners (PLAN §51, step 5): a count per banner per day — not a
 * row per click, and no impressions at all (the owner's call: a page's views
 * are the site statistics' business, and a write on every page is not worth
 * a number nobody reads).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_banners_clicks', function (Blueprint $table) {
            $table->foreignId('banner_id')->constrained('m_banners_banners')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('clicks')->default(0);
            $table->primary(['banner_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_banners_clicks');
    }
};
