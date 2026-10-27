<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name');
            $table->string('native_name');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('symbol', 8);
            $table->string('name');
            // Exchange rate relative to the base currency of a storefront.
            $table->decimal('rate', 16, 8)->default(1);
            $table->unsignedSmallInteger('decimals')->default(2);
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('storefronts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->jsonb('domains')->default('[]');
            $table->string('theme')->default('default');
            $table->string('default_locale', 5)->default('en');
            $table->jsonb('locales')->default('["en"]');
            $table->string('default_currency', 3)->default('USD');
            $table->jsonb('currencies')->default('["USD"]');
            $table->boolean('is_default')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('module_id')->unique();
            $table->string('version');
            $table->boolean('enabled')->default(true);
            $table->timestamp('installed_at');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('module');
            $table->string('key');
            $table->foreignId('storefront_id')->nullable()->constrained()->cascadeOnDelete();
            $table->jsonb('value')->nullable();
        });

        // NULLS NOT DISTINCT: one global row per module+key even with NULL storefront.
        DB::statement('CREATE UNIQUE INDEX settings_module_key_storefront_unique ON settings (module, key, storefront_id) NULLS NOT DISTINCT');
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('storefronts');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('languages');
    }
};
