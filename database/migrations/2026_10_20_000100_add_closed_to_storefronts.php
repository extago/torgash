<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A storefront closed to visitors — while it is being built, or for an hour
 * of repairs — that staff still see whole (App\Core\Storefronts\ClosedStorefront).
 *
 * `closed` is a different answer from `enabled`: a switched-off storefront
 * does not exist (its addresses fall through to the default one), a closed one
 * answers every address with its own notice. `closed_message` is the notice,
 * per language; `closed_token` the secret of the preview link a client is
 * sent, regenerated whenever the shop wants the old link dead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->boolean('closed')->default(false);
            $table->jsonb('closed_message')->nullable();
            $table->string('closed_token', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->dropColumn(['closed', 'closed_message', 'closed_token']);
        });
    }
};
