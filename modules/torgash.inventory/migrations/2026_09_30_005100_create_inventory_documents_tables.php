<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stock DOCUMENT (PLAN §42): what a person posts with one button — a
 * receipt (a delivery arrived), a write-off (broken, lost, used by the shop),
 * a transfer (from one warehouse to another), a count (there were N, there
 * are M). A draft is edited freely; a POSTED document never changes again: a
 * mistake is corrected by the next document, as in any book.
 *
 * A line's `qty` is what moves — for a count, what was COUNTED, and
 * `expected` is what the warehouse held at the moment of posting, so the
 * difference is on the paper as well as in the journal.
 *
 * A receipt's price is money and follows the money rule as far as it can:
 * what was typed (`unit_cost_original` + `currency`) and the books' number
 * (`unit_cost`). Unlike a product's price it is FROZEN at posting with the
 * rate of that day (`rate`) — a delivery was paid for once, and a later
 * exchange rate must not rewrite what the goods cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_inventory_documents', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->string('type', 16);
            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('to_warehouse_id')->nullable();
            $table->date('document_date');
            $table->string('counterparty', 190)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 190)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->string('posted_by_name', 190)->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'id']);
            $table->index('document_date');
        });

        Schema::create('m_inventory_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('m_inventory_documents')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->string('sku', 190)->nullable();
            $table->string('name', 500)->nullable();
            $table->integer('qty')->default(0);
            $table->integer('expected')->nullable();
            $table->bigInteger('unit_cost')->nullable();
            $table->bigInteger('unit_cost_original')->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('rate', 18, 8)->nullable();
            $table->integer('position')->default(0);

            $table->index(['document_id', 'position']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_inventory_document_lines');
        Schema::dropIfExists('m_inventory_documents');
    }
};
