<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A landing: its words per language (`html`, a jsonb map), one CSS, where on
 * the product page it stands, when, and for which products — core's display
 * rules plus products picked by hand (`excluded` = left out).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_landings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            // before | instead | after (the description) | tab
            $table->string('placement', 16)->default('after');
            $table->jsonb('tab_title')->nullable();
            $table->integer('sort')->default(0);
            // none | small | large
            $table->string('space_top', 8)->default('none');
            $table->string('space_bottom', 8)->default('none');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->jsonb('html')->nullable();
            $table->text('css')->nullable();
            $table->jsonb('rules')->nullable();
            $table->string('rules_match', 8)->default('all');
            $table->timestamps();

            $table->index(['enabled', 'placement']);
        });

        Schema::create('m_landing_products', function (Blueprint $table) {
            $table->foreignId('landing_id')->constrained('m_landings')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('m_catalog_products')->cascadeOnDelete();
            $table->boolean('excluded')->default(false);
            $table->primary(['landing_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_landing_products');
        Schema::dropIfExists('m_landings');
    }
};
