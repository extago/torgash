<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Countries, so the three levels a shop actually thinks about — country,
 * region, town — can each be switched off, the way established engines let you.
 *
 * No foreign key from places or regions to here on purpose: a pack for one
 * country is imported on its own, and a missing row in this table must not stop
 * that. The link is the ISO code, and a place whose country is not listed
 * simply behaves as if the country were disabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_locations_countries', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->unique();
            $table->char('code3', 3)->nullable();
            $table->string('numeric_code', 3)->nullable();
            $table->char('continent', 2)->nullable();
            $table->char('currency', 3)->nullable();

            // For the address form: a phone field that knows its prefix, and a
            // postal code that can be checked before the order is taken.
            $table->string('phone', 16)->nullable();
            $table->string('postal_format', 64)->nullable();
            $table->string('postal_regex', 190)->nullable();

            $table->jsonb('name');
            $table->bigInteger('geonameid')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index('enabled');
            $table->index('continent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_locations_countries');
    }
};
