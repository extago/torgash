<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A category's own SKU template (inherited by its subcategories), and the
 * running numbers: one per TEMPLATE, so two categories that share a template
 * share its sequence and can never hand out the same SKU twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_sku_category_templates', function (Blueprint $table) {
            $table->foreignId('category_id')->primary()->constrained('m_catalog_categories')->cascadeOnDelete();
            $table->string('template', 190);
            $table->timestamps();
        });

        Schema::create('m_sku_counters', function (Blueprint $table) {
            $table->string('template', 190)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_sku_counters');
        Schema::dropIfExists('m_sku_category_templates');
    }
};
