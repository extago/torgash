<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two tables, and the interesting one is `links`.
 *
 * Everything an exchange moves carries `external_id` — that is the shop's own
 * convention, and products, categories, warehouses, orders and customers all
 * have the column. So why a link table at all?
 *
 * Because three of the things 1C sends are NOT ours to add a column to.
 * Characteristics and their values belong to torgash.catalog and price types to
 * torgash.catalog too; a module does not reach into another module's schema, and
 * `torgash.commerceml` is optional in a way `external_id` is not. Their link
 * lives here instead, keyed by what 1C calls them.
 *
 * It is also the only place that can hold "seen, and not decided about yet" —
 * which is what makes the matching screen a QUEUE rather than a settings form.
 * A characteristic 1C sent last night is a row here with no local id and a
 * decision of `ask`, and the shop reads it in the morning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_commerceml_links', function (Blueprint $table) {
            $table->id();

            // What 1C calls this kind of thing: price_type, feature,
            // feature_value, order_status, payment_method, shipping_method.
            $table->string('kind', 40);
            // Its identifier over there. A characteristic's value carries its
            // parent's too — `<property guid>#<value guid>` — because 1C's own
            // value ids are only unique within their property.
            $table->string('guid', 255);
            // What it was called when we last saw it. For the human reading the
            // screen ONLY: two price types called "Оферта" and "оферта" are a
            // real thing 1C does, so a name is a hint and never a key.
            $table->string('name')->nullable();
            // The parent row, for a nested vocabulary (a value under its
            // property). Kept as a guid rather than an id so a value can arrive
            // before its property has been decided about.
            $table->string('parent_guid', 255)->nullable();

            // ask   — seen, waiting for a person (the queue)
            // link  — use local_id
            // create— make one at the next exchange
            // skip  — never import anything that carries this
            $table->string('decision', 20)->default('ask');
            $table->unsignedBigInteger('local_id')->nullable();
            // For price types, the local side is not always a row: it can be
            // "the base price" or "the old price", which are columns.
            $table->string('local_key', 60)->nullable();

            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'guid']);
            $table->index(['kind', 'decision']);
        });

        Schema::create('m_commerceml_exchanges', function (Blueprint $table) {
            $table->id();

            // The cookie value handed to 1C at checkauth. It addresses the
            // working directory for the rest of the conversation, so it is a
            // secret and is generated the way a cart token is.
            $table->string('token', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('catalog');

            // Where the pieces 1C is sending land. One directory per exchange,
            // so a second run cannot read the first one's half-written files.
            $table->string('path');

            // Which file is being imported and how far in we are. The protocol
            // is designed to be sliced — the shop answers "progress" and 1C asks
            // again — and this is the slice marker.
            $table->string('file')->nullable();
            $table->unsignedInteger('offset')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->text('message')->nullable();
            $table->string('state', 20)->default('open');

            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_commerceml_exchanges');
        Schema::dropIfExists('m_commerceml_links');
    }
};
