<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews of the SHOP, not of a product (PLAN §57.5): what a customer thought
 * of buying here — the delivery, the service, the site.
 *
 * Four tables. The criteria are rows for the reason product reviews' are: a
 * name in every language the shop speaks, and a list the shop edits. The
 * other sites (Google, Hotline…) are rows the shop types — their numbers are
 * the shop's own claim, shown with a link to where a reader can check it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_shopreviews_criteria', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->jsonb('name');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('m_shopreviews_reviews', function (Blueprint $table) {
            $table->id();
            // Nullable, unlike a product review: a shop review may be written
            // by a guest (setting `who_writes`) — the email is what answers
            // "who wrote this", and it is never shown.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('author_name');
            $table->string('city', 120)->nullable();
            $table->string('email')->nullable();

            $table->text('body')->nullable();
            $table->text('pros')->nullable();
            $table->text('cons')->nullable();
            // The average of the criteria given, or the one mark asked when
            // the shop rates by none.
            $table->decimal('rating', 2, 1);
            $table->boolean('recommend')->nullable();
            $table->boolean('will_return')->nullable();

            $table->string('status', 16)->default('pending');
            // An account that has an order here, worked out when written and
            // frozen. Never for a guest: anybody can type a customer's email.
            $table->boolean('verified')->default(false);
            // Where a review typed in by the staff was first written ("Google").
            // Null: written on this site.
            $table->string('source', 60)->nullable();

            // The shop's public answer — one, on the row: a shop review is not
            // a conversation, and an answer is read with what it answers.
            $table->text('reply')->nullable();
            $table->string('reply_author', 120)->nullable();
            $table->unsignedBigInteger('replied_by')->nullable();
            $table->timestamp('replied_at')->nullable();

            $table->unsignedBigInteger('storefront_id')->nullable();
            $table->string('locale', 10)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('user_id');
            $table->index('email');
        });

        Schema::create('m_shopreviews_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('m_shopreviews_reviews')->cascadeOnDelete();
            // No FK: deleting a criterion keeps the scores given for it.
            $table->unsignedBigInteger('criterion_id');
            $table->unsignedSmallInteger('value');
            $table->unique(['review_id', 'criterion_id']);
            $table->index('criterion_id');
        });

        Schema::create('m_shopreviews_platforms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('url', 500)->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            $table->unsignedInteger('reviews_count')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        // What MTA and most shops ask, so the form is not one bare star on day
        // one. Names in the three languages the engine ships; the shop renames
        // or switches them off on the criteria screen.
        $now = now();
        foreach ([
            ['reliability', 'Reliability', 'Надёжность', 'Надійність'],
            ['website', 'Website', 'Сайт', 'Сайт'],
            ['delivery', 'Delivery', 'Доставка', 'Доставка'],
            ['products', 'Products', 'Товар', 'Товар'],
            ['service', 'Service', 'Сервис', 'Сервіс'],
        ] as $i => [$code, $en, $ru, $uk]) {
            DB::table('m_shopreviews_criteria')->insert([
                'code' => $code,
                'name' => json_encode(['en' => $en, 'ru' => $ru, 'uk' => $uk], JSON_UNESCAPED_UNICODE),
                'sort' => ($i + 1) * 10,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('m_shopreviews_platforms');
        Schema::dropIfExists('m_shopreviews_ratings');
        Schema::dropIfExists('m_shopreviews_reviews');
        Schema::dropIfExists('m_shopreviews_criteria');
    }
};
