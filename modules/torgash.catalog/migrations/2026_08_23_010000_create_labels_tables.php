<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_catalog_labels', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');
            $table->string('name_for_admin');
            $table->string('style')->default('text'); // text|image
            $table->string('color')->default('primary'); // daisyUI badge color key
            $table->string('position_list')->default('top-left');
            $table->string('position_detail')->default('top-left');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // Flat rule list: {"match": "all"|"any", "rules": [{"field":...,"operator":...,"value":...}, ...]}.
            // Null/empty rules = manual assignment only, see m_catalog_label_product.
            $table->jsonb('conditions')->nullable();
            $table->timestamps();
        });

        Schema::create('m_catalog_label_product', function (Blueprint $table) {
            $table->foreignId('label_id')->constrained('m_catalog_labels')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('m_catalog_products')->cascadeOnDelete();
            $table->primary(['label_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_catalog_label_product');
        Schema::dropIfExists('m_catalog_labels');
    }
};
