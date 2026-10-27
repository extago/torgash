<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a rate feed needs to know about a currency it is about to update.
 *
 * `auto_rate` — whether this currency's rate is fetched at all. On by default,
 * because a shop that turns a feed on means all of them; off is for the one
 * rate a shop keeps by hand (a fixed internal rate, a currency the feed does
 * not carry).
 *
 * `rate_key` — what this currency is CALLED in the feed, when that is not its
 * ISO code. The case this exists for is crypto: CoinGecko wants `bitcoin`, not
 * `BTC`. Without it a generic JSON feed only works for sources that happen to
 * key by ISO code, which is most banks and almost no exchange.
 *
 * `rate_updated_at` — when it was last fetched, shown beside the rate. A rate
 * with no date next to it is a number nobody can judge, and a feed that quietly
 * stopped working looks exactly like one that is working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->boolean('auto_rate')->default(true)->after('rate');
            $table->string('rate_key')->nullable()->after('auto_rate');
            $table->timestamp('rate_updated_at')->nullable()->after('rate_key');
        });
    }

    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn(['auto_rate', 'rate_key', 'rate_updated_at']);
        });
    }
};
