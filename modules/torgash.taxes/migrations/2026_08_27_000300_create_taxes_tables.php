<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taxes, modelled after the usual design (see docs/PLAN.md) with the parts we
 * do not need yet left out.
 *
 * The one decision worth reading: whether a tax is INCLUDED in the price or
 * ADDED at checkout is a property of the tax, not of the shop. That is the
 * whole difference between "20% VAT, price on the shelf is final" and "sales
 * tax added at the till", and a shop selling into both worlds needs both at
 * once. Everything else follows from it.
 *
 * Rates per destination came later, as their own step, once orders stored a
 * country — see ..._create_taxes_destination_rates.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_taxes_taxes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->jsonb('name');

            // 'percent' uses rate_percent, 'fixed' uses rate_amount (minor
            // units, per item) — a flat duty per unit rather than a share.
            $table->string('rate_type', 8)->default('percent');
            $table->decimal('rate_percent', 7, 4)->nullable();
            $table->bigInteger('rate_amount')->nullable();

            $table->boolean('price_includes_tax')->default(true);

            // Taxes sharing a priority are all charged on the ORIGINAL price;
            // a higher priority is charged on the price the lower ones already
            // raised (tax on tax). That is how GST+PST vs compounding
            // provincial taxes are told apart.
            $table->smallInteger('priority')->default(0);

            // Applied to products that carry no taxes of their own.
            $table->boolean('applies_by_default')->default(true);
            $table->boolean('applies_to_shipping')->default(false);

            // Printed on the invoice next to the tax ("VAT reg. no. ...").
            $table->string('reg_number')->nullable();

            $table->boolean('enabled')->default(true);
            $table->smallInteger('position')->default(0);
            $table->timestamps();

            $table->index('enabled');
        });

        /**
         * Per-product override. Any row here means "these taxes, instead of the
         * defaults"; no rows means the defaults apply.
         *
         * There is no "exempt" flag because there is a better answer, and it is
         * the one established shops use: a 0% tax. An exempt product is not
         * untaxed — its invoice line still has to say 0%, and the shop still
         * has to be able to find those products later.
         */
        Schema::create('m_taxes_product_tax', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('m_catalog_products')->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained('m_taxes_taxes')->cascadeOnDelete();

            $table->unique(['product_id', 'tax_id']);
            // Postgres indexes neither side of a foreign key on its own; the
            // unique above covers product_id, this covers the other direction.
            $table->index('tax_id');
        });

        /**
         * What an order was actually charged, frozen at the moment it was
         * placed. Rates change; a placed order must not.
         *
         * tax_id is nullable on purpose: deleting a tax from the catalogue must
         * not rewrite history, so the row keeps the name and rate it charged.
         */
        Schema::create('m_taxes_order_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('m_checkout_orders')->cascadeOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained('m_taxes_taxes')->nullOnDelete();
            $table->string('name');
            $table->string('rate_type', 8);
            $table->decimal('rate_percent', 7, 4)->nullable();
            $table->bigInteger('rate_amount')->nullable();
            $table->boolean('price_includes_tax');
            $table->string('reg_number')->nullable();
            $table->bigInteger('amount');

            $table->index('order_id');
            $table->index('tax_id');
        });

        // A shop that installs this module almost certainly wants one VAT, and
        // the numbers here are the common CIS pair. Disabled rows cost nothing
        // and save the merchant from typing the reduced rate by hand.
        $now = now();
        DB::table('m_taxes_taxes')->insert([
            [
                'code' => 'vat20',
                'name' => json_encode(['en' => 'VAT 20%', 'ru' => 'НДС 20%'], JSON_UNESCAPED_UNICODE),
                'rate_type' => 'percent',
                'rate_percent' => 20,
                'price_includes_tax' => true,
                'applies_by_default' => false,
                'applies_to_shipping' => false,
                'enabled' => false,
                'position' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'vat10',
                'name' => json_encode(['en' => 'VAT 10%', 'ru' => 'НДС 10%'], JSON_UNESCAPED_UNICODE),
                'rate_type' => 'percent',
                'rate_percent' => 10,
                'price_includes_tax' => true,
                'applies_by_default' => false,
                'applies_to_shipping' => false,
                'enabled' => false,
                'position' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'vat0',
                'name' => json_encode(['en' => 'VAT 0%', 'ru' => 'НДС 0%'], JSON_UNESCAPED_UNICODE),
                'rate_type' => 'percent',
                'rate_percent' => 0,
                'price_includes_tax' => true,
                'applies_by_default' => false,
                'applies_to_shipping' => false,
                'enabled' => false,
                'position' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('m_taxes_order_taxes');
        Schema::dropIfExists('m_taxes_product_tax');
        Schema::dropIfExists('m_taxes_taxes');
    }
};
