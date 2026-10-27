<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the SHOP says about an availability status (PLAN §31.6).
 *
 * The registry stays open to code (`catalog.availability_statuses`), and this
 * table is the shop's half of it — one row per status the shop has said
 * anything about, which is two different things depending on whose status it
 * is:
 *
 * - a status registered in CODE (the five base ones, a module's own) keeps its
 *   meaning — `orderable`, `tracks_stock`, `allow_negative` are what that code
 *   relies on — and takes from here only its NAME, when the shop renamed it,
 *   and its WEIGHT in the listing;
 * - a key nobody registers is the shop's OWN status, and every column applies.
 *
 * Code wins on a collision, so a module later registering a key the shop had
 * already invented quietly takes the meaning over and keeps the shop's name.
 *
 * `sort_weight` NULL means "not stated": the weight is then derived from
 * whether the status can be bought (10 or 0), which is exactly the two groups
 * the "out of stock last" tumbler used to draw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_catalog_availability_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->jsonb('label')->nullable();
            $table->boolean('orderable')->default(false);
            $table->boolean('tracks_stock')->default(true);
            $table->boolean('allow_negative')->default(false);
            $table->string('schema', 100)->nullable();
            $table->integer('sort_weight')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_catalog_availability_statuses');
    }
};
