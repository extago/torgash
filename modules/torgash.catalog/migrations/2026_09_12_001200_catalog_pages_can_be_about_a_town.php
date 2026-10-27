<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A city page — "Ноутбуки в Киеве" — is a filter page with a town on it.
 *
 * Deliberately the same table (PLAN §30.9). Both are the same sentence about a
 * published address: this category, narrowed or renamed, at an address of its
 * own with its own words. Everything they need is already here — the slug, the
 * unique fingerprint, the meta columns, the sitemap flag, the 301 on deletion,
 * the route, the admin screen — and a second table would have been a copy of
 * all of it plus a second reason for the canonical rules to disagree.
 *
 * The difference between the two is what `place_id` turns out to be:
 *
 *  - values, no place — "Apple laptops": FEWER products than the category.
 *  - a place, no values — "Laptops in Kyiv": the SAME products, different
 *    words. Which is why no filtered listing ever canonicalises to one — there
 *    is no query string that means "in Kyiv", so nothing can be duplicating it.
 *  - both — "Apple laptops in Kyiv".
 *
 * No foreign key: `m_locations_places` belongs to another module, and the
 * catalogue may not be uninstalled-or-not with it. A place that disappears
 * leaves the page answering under its own stored name, which is the same
 * answer a warehouse's `place_id` already gives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_filter_pages', function (Blueprint $table) {
            $table->unsignedBigInteger('place_id')->nullable();
            $table->index('place_id');
        });

        Schema::table('m_catalog_filter_rules', function (Blueprint $table) {
            // A rule has ONE axis: a characteristic's values, or a list of
            // towns. Never both — two axes multiplied publish the square of the
            // catalogue, which is the same reason one rule already means one
            // characteristic.
            $table->jsonb('place_ids')->nullable();
        });

        // ...which is why the characteristic became optional. Done in raw SQL
        // because changing a column's nullability through the schema builder
        // needs doctrine/dbal, which this project does not carry.
        Schema::getConnection()->statement(
            'ALTER TABLE m_catalog_filter_rules ALTER COLUMN feature_id DROP NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::getConnection()->statement(
            'DELETE FROM m_catalog_filter_rules WHERE feature_id IS NULL'
        );
        Schema::getConnection()->statement(
            'ALTER TABLE m_catalog_filter_rules ALTER COLUMN feature_id SET NOT NULL'
        );

        Schema::table('m_catalog_filter_rules', function (Blueprint $table) {
            $table->dropColumn('place_ids');
        });

        Schema::table('m_catalog_filter_pages', function (Blueprint $table) {
            $table->dropIndex(['place_id']);
            $table->dropColumn('place_id');
        });
    }
};
