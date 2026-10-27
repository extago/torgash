<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shipping zones — a named set of places, and the thing rates are assigned to.
 *
 * Modelled on the usual destinations (see docs/PLAN.md §11) with one change
 * that matters: their rules live in a table
 * but the narrowness that decides which zone wins is a score computed inside a
 * function, so nobody can see why a zone won. Here the narrowness is a column
 * on the rule, and the admin screen shows it.
 *
 * A rule matches one aspect of where the customer is. The zone whose matching
 * rule is the NARROWEST wins — a rule naming this exact town beats one naming
 * its region, which beats one naming the country. Exactly one zone wins, so a
 * method either has a rate there or is not offered.
 *
 * (Was torgash.checkout's m_checkout_zones; moved here when shipping became its
 * own module — docs/PLAN.md §12.0.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');
            $table->boolean('enabled')->default(true);
            $table->smallInteger('position')->default(0);
            $table->timestamps();

            $table->index('enabled');
        });

        Schema::create('m_shipping_zone_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('m_shipping_zones')->cascadeOnDelete();

            // country | state | place | postal | postal_prefix | postal_glob
            $table->string('kind', 16);

            // An ISO code, a region code, a place id, a postal code or its
            // first characters — whatever the kind says. Kept as text so the
            // rules table stays one shape.
            $table->string('value', 190);

            // How narrow this rule is: 1 country, 2 region, 3 town, 4 postal.
            // Written by the model rather than by hand, and stored rather than
            // recomputed so the query can just order by it.
            $table->smallInteger('level');

            $table->timestamps();

            $table->index('zone_id');
            $table->index(['kind', 'value']);
        });

        // A zone that matches everything, so a fresh shop behaves as it did
        // before zones existed: one place to put rates, covering the world.
        $id = DB::table('m_shipping_zones')->insertGetId([
            'name' => json_encode(['en' => 'Everywhere', 'ru' => 'Везде'], JSON_UNESCAPED_UNICODE),
            'enabled' => true,
            'position' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('m_shipping_zone_rules')->insert([
            'zone_id' => $id,
            'kind' => 'country',
            'value' => '*',
            'level' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('m_shipping_zone_rules');
        Schema::dropIfExists('m_shipping_zones');
    }
};
