<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A repeating window inside the promotion's date range — "deal of the day".
 *
 * `starts_at`/`ends_at` answer WHEN THE SALE EXISTS; this answers WHEN IT IS
 * OPEN inside that. A happy hour from 18:00 to 22:00 every Friday is one
 * promotion with one window, not 52 promotions or a person switching it on by
 * hand every week.
 *
 * Read this against the usual deal-of-the-day add-on, which is where the feature
 * comes from: there the admin paints an hour-by-day calendar, the save
 * FLATTENS it into concrete from/to timestamps in a second table, and a
 * function then rewrites the promotion's own from_date/to_date to whichever
 * period is current (rebuilt on read).
 * All three exist because their engine understands exactly one window. Ours
 * does not need to: the rule is evaluated against the clock at read time, so
 * there is nothing to materialise, nothing to rebuild, and no way for the
 * materialised copy to disagree with the rule that produced it.
 *
 * Shape: {"windows": [{"days": [1,5], "from": "18:00", "to": "22:00"}]}
 * — ISO weekdays (1 = Monday), an empty/absent `days` meaning every day, and
 * `to` before `from` meaning the window crosses midnight (a night sale is a
 * real thing and the alternative is asking the merchant to enter two rows).
 * NULL, and an empty window list, both mean "open whenever the dates say" —
 * which is every promotion that existed before this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_promotions_promotions', function (Blueprint $table) {
            $table->jsonb('schedule')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_promotions_promotions', function (Blueprint $table) {
            $table->dropColumn('schedule');
        });
    }
};
