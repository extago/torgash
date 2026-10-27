<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a characteristic's values LOOK — two answers, because the product page
 * and the filter panel are two places with two jobs.
 *
 *  - `product_style`: the variation picker on the product page — `labels`
 *    (a row of chips, what there always was), `images` (each member's own
 *    picture, its word under it), `swatches` (the colour alone), `dropdown`.
 *  - `filter_style`: the filter panel — `list` (ticks, what there always
 *    was), `chips` (the words as buttons in rows), `swatches` (colours).
 *
 * Both only DRAW: the control underneath stays what it is (a link to the
 * member, a checkbox in the GET form), so an address, a filter page and a
 * browser without JavaScript see no difference. `is_color` stays the fact
 * "values carry a swatch"; a style that needs one falls back where a value
 * has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->string('product_style', 16)->default('labels');
            $table->string('filter_style', 16)->default('list');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->dropColumn(['product_style', 'filter_style']);
        });
    }
};
