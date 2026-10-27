<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Directional on purpose (A relates to B doesn't imply B relates to
        // A) — a phone case wants to suggest the phone, the phone usually
        // doesn't want every case suggested back at it.
        Schema::create('m_catalog_related_products', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('m_catalog_products')->cascadeOnDelete();
            $table->foreignId('related_product_id')->constrained('m_catalog_products')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->primary(['product_id', 'related_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_catalog_related_products');
    }
};
