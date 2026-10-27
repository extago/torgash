<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The blog (PLAN §47): rubrics — coloured plates, a flat list, no tags and no
 * archive (the owner's decision) — posts, the products a post is about, and a
 * like per browser.
 *
 * A post's address does not depend on its rubric: moving it between rubrics
 * must not break the links already out there. `published_at` in the future is
 * a post scheduled — it appears on its own when the moment comes. Meta columns
 * live on the rows, as on every entity with a page (App\Core\Seo\PageSubject).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_blog_rubrics', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');
            $table->string('slug', 190)->unique();
            $table->jsonb('description')->nullable();
            // A Lucide name and a #hex — the plate's own look.
            $table->string('icon', 64)->nullable();
            $table->string('color', 7)->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->jsonb('meta_title')->nullable();
            $table->jsonb('meta_description')->nullable();
            $table->jsonb('h1')->nullable();
            $table->boolean('noindex')->default(false);
            $table->string('canonical_url', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('m_blog_posts', function (Blueprint $table) {
            $table->id();
            $table->jsonb('title');
            $table->string('slug', 190)->unique();
            $table->jsonb('lead')->nullable();
            $table->jsonb('body')->nullable();
            // draft | published | hidden (published, reachable by its address,
            // in no feed — for a post a campaign links to)
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_name', 190)->nullable();
            $table->unsignedSmallInteger('reading_minutes')->default(1);
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('views_count')->default(0);
            $table->boolean('comments_enabled')->default(true);
            $table->jsonb('meta_title')->nullable();
            $table->jsonb('meta_description')->nullable();
            $table->jsonb('h1')->nullable();
            $table->boolean('noindex')->default(false);
            $table->string('canonical_url', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });

        Schema::create('m_blog_post_rubric', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained('m_blog_posts')->cascadeOnDelete();
            $table->foreignId('rubric_id')->constrained('m_blog_rubrics')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['post_id', 'rubric_id']);
            $table->index('rubric_id');
        });

        Schema::create('m_blog_post_products', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained('m_blog_posts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('m_catalog_products')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['post_id', 'product_id']);
        });

        // One like per browser: a key the browser keeps, hashed. Unique, so a
        // double click is one like and "unlike" is a delete.
        Schema::create('m_blog_likes', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained('m_blog_posts')->cascadeOnDelete();
            $table->string('visitor', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['post_id', 'visitor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_blog_likes');
        Schema::dropIfExists('m_blog_post_products');
        Schema::dropIfExists('m_blog_post_rubric');
        Schema::dropIfExists('m_blog_posts');
        Schema::dropIfExists('m_blog_rubrics');
    }
};
