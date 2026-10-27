<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second axis, next to `status` (PLAN §31.3) — `status` is the admin's own
 * lifecycle (active/hidden/disabled), this is whether the product can be
 * bought right now and whether its stock number even applies. Kept as a plain
 * string rather than an enum/FK on purpose: the registry that gives the key
 * meaning (`Torgash\Catalog\Stock\AvailabilityStatuses`) is open to a module,
 * exactly like `catalog.filters.flags` — a status a module has stopped
 * registering must still be a legible word on an old row, not a broken
 * foreign key.
 *
 * Default `in_stock` for every existing row, including ones already at zero:
 * `AvailabilityStatuses::isOrderable()` still gates `in_stock` by the actual
 * `stock` number, so this is behaviourally identical to today (every product
 * was implicitly "in_stock-like", gated only by its quantity) without a data
 * pass pretending to know which rows were really depleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->string('availability_status', 32)->default('in_stock')->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn('availability_status');
        });
    }
};
