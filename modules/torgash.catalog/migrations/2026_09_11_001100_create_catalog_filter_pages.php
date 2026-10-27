<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A category plus a set of characteristic values, at an address of its own.
 *
 * Filters are query parameters (`?feature[12][]=34`), and a query parameter is
 * not a page: core's canonical strips every one of them, so "Apple laptops" is
 * today an address no search engine will ever hold. This table is the other
 * half of that decision — the combinations a shop has decided ARE pages get a
 * slug, a name, their own metadata, and a canonical pointing at them from the
 * filtered listing they duplicate.
 *
 * In the CATALOGUE and not in `torgash.seo`, for the reason §30.3 gave about
 * meta columns: these are published addresses. A shop that uninstalls its SEO
 * module must not take five thousand live URLs down with it — what leaves with
 * that module is the writing of titles, never the pages themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_catalog_filter_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('m_catalog_categories')->cascadeOnDelete();
            // Null = written by hand. A generated page keeps the link so a
            // later run recognises its own work — and survives the rule being
            // deleted, because the address is already published by then.
            $table->foreignId('rule_id')->nullable()
                ->constrained('m_catalog_filter_rules')->nullOnDelete();
            $table->string('slug');
            // `{"12": [34, 56]}` — feature id => value ids, both sides sorted.
            $table->jsonb('values');
            // The same thing as one string, so "does this combination already
            // have a page" is a unique index rather than a scan of every row's
            // jsonb. Postgres does not keep jsonb key order, so the column the
            // uniqueness is enforced on cannot be the jsonb one.
            $table->string('fingerprint')->unique();
            // What it is called: the link in the category, the last crumb.
            $table->jsonb('name')->nullable();
            $table->jsonb('description')->nullable();
            $table->jsonb('meta_title')->nullable();
            $table->jsonb('meta_description')->nullable();
            $table->jsonb('h1')->nullable();
            $table->boolean('noindex')->default(false);
            $table->string('canonical_url')->nullable();
            // Refreshed by the generator. Kept so the admin screen can show a
            // thin page without counting the catalogue once per row.
            $table->unsignedInteger('products_count')->default(0);
            $table->boolean('in_sitemap')->default(true);
            $table->boolean('enabled')->default(true);
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'slug']);
            $table->index(['category_id', 'enabled', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_catalog_filter_pages');
    }
};
