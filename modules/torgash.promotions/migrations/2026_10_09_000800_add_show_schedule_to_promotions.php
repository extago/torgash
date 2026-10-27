<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the customer is TOLD a sale's schedule ("every day", "Mon, Fri
 * 18:00–22:00"). The schedule still decides when the sale runs; a shop that
 * runs "the deal of the day" every day may simply not want to say so — the
 * countdown to tonight's close reads very differently from "every day".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_promotions_promotions', function (Blueprint $table) {
            $table->boolean('show_schedule')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('m_promotions_promotions', function (Blueprint $table) {
            $table->dropColumn('show_schedule');
        });
    }
};
