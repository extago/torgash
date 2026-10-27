<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One table for every kind of comment, addressed by a REGISTERED target type
 * plus an id — the same shape MediaOwners and the enquiry Kinds use.
 *
 * Not a comments table per entity, and not a column per entity: pages needed
 * comments first, product reviews will need them next, and the difference
 * between the two is a rating and a heading, not a storage engine. What a
 * target type owns is how to find the thing and how to link to it, which is
 * what the registry holds.
 *
 * `target_type` is a key from that registry, never a class name: a class name
 * in a database row is a rename away from an unreadable table, and it would
 * also let a POST address a row this module knows nothing about.
 *
 * The author is stored as it was GIVEN — a name and an optional email — beside
 * `user_id` when there is an account. Both, not one: an account can be deleted
 * (the id goes null) and the comment must still read as somebody's, while a
 * signed-in author must not be able to sign a comment with another name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_comments_comments', function (Blueprint $table) {
            $table->id();

            $table->string('target_type', 64);
            $table->unsignedBigInteger('target_id');

            // One level of replies. A thread deeper than that is a forum, and
            // a forum in a shop's page footer is a thing nobody reads.
            $table->foreignId('parent_id')->nullable()->constrained('m_comments_comments')->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->string('author_email')->nullable();

            $table->text('body');

            // pending | approved | rejected. A fixed vocabulary, like an
            // enquiry's: "have we let this through" is the same question in
            // every shop, unlike an order's own workflow.
            $table->string('status', 16)->default('pending');

            // An answer from the shop, marked as such. The alternative — a
            // separate table for staff replies — would make "show me this
            // conversation" a union query for ever.
            $table->boolean('is_staff')->default(false);

            // Where it was written. No FK: a storefront may be deleted and the
            // comment is still a fact about the past.
            $table->unsignedBigInteger('storefront_id')->nullable();

            $table->timestamps();

            $table->index(['target_type', 'target_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_comments_comments');
    }
};
