<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An option the customer UPLOADS (PLAN §33.7): the layout for a business card,
 * a logo to engrave, a photograph to print on a mug.
 *
 * Like a typed option, what it COSTS is an ordinary choice with a price rule —
 * the first one the group holds — so nothing about pricing is new. What is new
 * is that the answer is bytes, and bytes cannot live in a jsonb bag: the cart
 * line's configuration carries a TOKEN and this table holds what it stands
 * for.
 *
 * A row rather than a path in the configuration, for three reasons that all
 * turn on this being a PUBLIC upload:
 *
 * - **Who may read it** has to be answerable. A path in a bag is a guess about
 *   authorisation; a row can say which cart and which order it belongs to, and
 *   the controller can ask.
 * - **What it was called** belongs here and not in the bag. The cart line's KEY
 *   is a hash of its configuration, and a customer renaming their file before
 *   re-uploading it would otherwise be buying a different thing.
 * - **An abandoned cart leaves a file.** With a row, the sweep is a query; with
 *   a path in somebody's jsonb, it is a scan of every cart and every order in
 *   the shop.
 *
 * `order_id` is claimed at placement and is what makes the difference between
 * "nobody bought this, throw it away in a week" and "this is part of an order
 * and is kept for as long as the order is". No foreign key to the cart: a cart
 * is emptied and deleted in the ordinary course of business, and the file of a
 * line that WAS bought must not go with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_options_files', function (Blueprint $table) {
            $table->id();
            // The public handle, and the only thing that ever reaches a page
            // or a configuration bag: the row's id would let anybody count the
            // shop's uploads and guess the next one.
            $table->string('token', 64)->unique();
            // Nulled rather than cascaded when the question is deleted: the
            // file of a line somebody BOUGHT outlives the question it
            // answered, exactly as a review's scores outlive the criterion.
            $table->foreignId('group_id')->nullable()->constrained('m_options_groups')->nullOnDelete();
            // Where it is on the PRIVATE disk. Our own name, never theirs.
            $table->string('path');
            // Theirs, for the workshop to read — shown, never used to open
            // anything.
            $table->string('original_name');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size');
            // Which basket it was uploaded into, so an abandoned one can be
            // swept; nulled once an order claims the file.
            $table->string('cart_token', 64)->nullable()->index();
            // No foreign key: orders are another module's table, and this one
            // works in a shop that has uninstalled checkout — the same rule
            // `place_id` on an order follows about the locations module.
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });

        Schema::table('m_options_groups', function (Blueprint $table) {
            // Kilobytes, and the shop's own word: a print shop takes a 40 MB
            // layout and a shop asking for a logo does not. Null means the
            // module-wide setting applies.
            $table->unsignedInteger('max_kb')->nullable();
            // A comma-separated list of extensions this question accepts, and
            // null means the module-wide setting again. Per GROUP rather than
            // only per shop because "the logo, as PNG" and "the layout, as
            // PDF" are two different questions in one catalogue.
            $table->string('file_types')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_options_groups', function (Blueprint $table) {
            $table->dropColumn(['max_kb', 'file_types']);
        });

        Schema::dropIfExists('m_options_files');
    }
};
