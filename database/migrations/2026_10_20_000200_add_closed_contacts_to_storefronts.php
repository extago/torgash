<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a closed storefront's notice shows the shop's contacts. Off by
 * default: a shop that is not open yet usually has nobody to answer them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->boolean('closed_contacts')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->dropColumn('closed_contacts');
        });
    }
};
