<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a shipping method costs — and whether it is offered at all — in one zone.
 *
 * A rate belongs to a (method, zone) pair, and a method with no rate in the
 * zone the customer landed in is not offered there. That single rule is the
 * whole mechanism behind "in our own city we also do pickup": pickup gets a
 * rate in the city zone and nowhere else — the way established engines do it.
 *
 * The price itself is base_rate plus, per axis, the one band the cart falls in:
 * order total (C), weight (W), items (I). Older engines keep those bands in a
 * serialize()d blob, which is why nobody there can report on tariffs, import
 * them, or diff what changed. Here they are rows.
 *
 * (Was torgash.checkout's m_checkout_shipping_rates. The flat base_rate/free_over
 * that used to live on the method never existed on m_shipping_methods, so this
 * seeds the three default methods a catch-all rate directly — same end state
 * the old back-migration produced. docs/PLAN.md §12.0.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained('m_shipping_methods')->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained('m_shipping_zones')->cascadeOnDelete();

            $table->bigInteger('base_rate')->default(0);
            $table->bigInteger('free_over')->nullable();

            // "1–3 days" — a promise, per zone, because the same courier is not
            // equally fast to the next street and to the next country.
            $table->jsonb('delivery_time')->nullable();

            $table->boolean('enabled')->default(true);
            $table->timestamps();

            // One rate per pair: two would mean the shop has two answers to
            // "what does this cost here" and no way to say which wins.
            $table->unique(['shipping_method_id', 'zone_id']);
            $table->index('zone_id');
        });

        Schema::create('m_shipping_rate_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_id')->constrained('m_shipping_rates')->cascadeOnDelete();

            // C order total (minor units) | W weight (grams) | I item count
            $table->char('axis', 1);

            $table->bigInteger('range_from')->default(0);
            $table->bigInteger('range_to')->nullable();

            // Same shape as a tax rate (m_taxes_taxes): a fixed amount in minor
            // units, or a percentage — of the order total, on every axis, which
            // is what a percentage can sensibly mean.
            $table->string('value_type', 8)->default('fixed');
            $table->bigInteger('value_amount')->nullable();
            $table->decimal('value_percent', 7, 4)->nullable();

            // "per kilogram" / "per item" rather than a flat band charge.
            $table->boolean('per_unit')->default(false);

            $table->timestamps();

            $table->index(['rate_id', 'axis']);
        });

        // The prices the three default methods shipped with, in the zone that
        // covers the world, so a fresh shop quotes exactly as it did when these
        // numbers lived on the method row.
        $catchAll = DB::table('m_shipping_zones as z')
            ->join('m_shipping_zone_rules as r', 'r.zone_id', '=', 'z.id')
            ->where('r.kind', 'country')->where('r.value', '*')
            ->orderBy('z.position')->orderBy('z.id')
            ->value('z.id')
            ?? DB::table('m_shipping_zones')->orderBy('position')->orderBy('id')->value('id');

        if ($catchAll) {
            $prices = [
                'Courier delivery' => ['base_rate' => 500, 'free_over' => 5000],
                'Pickup' => ['base_rate' => 0, 'free_over' => null],
                'Arrange with manager' => ['base_rate' => 0, 'free_over' => null],
            ];

            $rows = DB::table('m_shipping_methods')->orderBy('id')->get()
                ->map(function ($method) use ($catchAll, $prices) {
                    $name = json_decode($method->name, true)['en'] ?? '';
                    $price = $prices[$name] ?? ['base_rate' => 0, 'free_over' => null];

                    return [
                        'shipping_method_id' => $method->id,
                        'zone_id' => $catchAll,
                        'base_rate' => $price['base_rate'],
                        'free_over' => $price['free_over'],
                        'delivery_time' => null,
                        'enabled' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                })->all();

            if ($rows) {
                DB::table('m_shipping_rates')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('m_shipping_rate_rows');
        Schema::dropIfExists('m_shipping_rates');
    }
};
