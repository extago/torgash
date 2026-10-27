<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A label carries two different kinds of text and they were being conflated:
 *
 *   name          — the BADGE, per product, may contain [discount]/[sales]
 *                   placeholders resolved against that product
 *   section_title — the HEADING of the storefront section this label produces
 *                   (the homepage tabs), where there is no product to resolve
 *                   a placeholder against
 *
 * Deriving the second from the first put "Sale -[discount]%" on the homepage
 * as a tab title. Label::plainName() strips the tokens as a fallback, but a
 * stripped badge text ("Sale") is a guess at a heading, not a heading — this
 * column lets the merchant just say what the section is called.
 *
 * Nullable on purpose: empty falls back to plainName(), so existing labels
 * keep working with nothing to fill in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_labels', function (Blueprint $table) {
            $table->jsonb('section_title')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_labels', function (Blueprint $table) {
            $table->dropColumn('section_title');
        });
    }
};
