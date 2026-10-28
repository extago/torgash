<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pages kept ready for guests (PLAN §61, App\Core\PageCache).
 *
 * One row per page and per variant of it: `url_hash` is the address,
 * `vary` what else the page turned out to depend on (the currency, how a
 * listing is laid out), matched by containment — a visitor's values hold
 * the row's. The body is deflated HTML with the personal parts cut out.
 *
 * Two sequences rather than a column anywhere: `generation` is bumped to
 * throw every page away at once (a row of another generation is simply never
 * served, and pruned later), `changes` on every invalidation of any kind — a
 * copy whose drawing began before the last change is not kept. Sequences
 * because a bump is immediate and takes no lock: an order's transaction
 * must not queue behind a counter row. Each is moved once here, so that
 * `last_value` is a number that the next bump changes (a fresh sequence's
 * first nextval() returns the value it already shows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_cache', function (Blueprint $table) {
            $table->id();
            $table->char('url_hash', 40);
            $table->char('vary_hash', 40);
            $table->text('url');
            $table->string('kind', 40);
            $table->jsonb('vary')->default('{}');
            $table->bigInteger('generation');
            $table->binary('body');
            $table->jsonb('meta');
            $table->integer('size');
            $table->timestamp('created_at');
            $table->timestamp('expires_at')->index();

            $table->unique(['url_hash', 'vary_hash']);
        });

        DB::statement("ALTER TABLE page_cache ADD COLUMN tags text[] NOT NULL DEFAULT '{}'");
        DB::statement('CREATE INDEX page_cache_tags_index ON page_cache USING gin (tags)');
        // Deflated already: TOAST trying to compress it again only costs time.
        DB::statement('ALTER TABLE page_cache ALTER COLUMN body SET STORAGE EXTERNAL');

        DB::statement('CREATE SEQUENCE IF NOT EXISTS page_cache_generation');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS page_cache_changes');
        DB::statement("SELECT nextval('page_cache_generation'), nextval('page_cache_changes')");
    }

    public function down(): void
    {
        Schema::dropIfExists('page_cache');
        DB::statement('DROP SEQUENCE IF EXISTS page_cache_generation');
        DB::statement('DROP SEQUENCE IF EXISTS page_cache_changes');
    }
};
