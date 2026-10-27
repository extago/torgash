<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "…and only what fits" is more than one characteristic (PLAN §33.5).
 *
 * One was enough for the case the feature was written for — a glass for THIS
 * phone — but not for an outfit: a jacket takes a shirt and trousers of the
 * same COLOUR and the same FABRIC, and a single column can only ask one of
 * those two questions. Two rules with one condition each would not do it
 * either: they would each offer everything matching their own half.
 *
 * A jsonb ARRAY rather than a pivot table, and rather than a comma-separated
 * string: nothing joins on it, the order of the conditions means nothing (they
 * are ANDed), and a list of a handful of ids is exactly what jsonb is for. The
 * warning about jsonb not keeping key order (docs/LESSONS.md) is about MAPS;
 * a list keeps its order and is not asked to.
 *
 * ALL of them must match, never any: "the same colour or the same fabric"
 * describes a wider set than a person picking an outfit means, and the narrow
 * reading is the one that can be widened by deleting a condition.
 *
 * `match_feature_id` is replaced rather than kept beside it, because two
 * columns saying the same thing is the drift this engine spends its comments
 * avoiding. Its value is carried over as a one-element list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_kits_slots', function (Blueprint $table) {
            $table->jsonb('match_features')->nullable();
        });

        DB::table('m_kits_slots')
            ->whereNotNull('match_feature_id')
            ->orderBy('id')
            ->chunkById(200, function ($slots) {
                foreach ($slots as $slot) {
                    DB::table('m_kits_slots')
                        ->where('id', $slot->id)
                        ->update(['match_features' => json_encode([(int) $slot->match_feature_id])]);
                }
            });

        Schema::table('m_kits_slots', function (Blueprint $table) {
            $table->dropColumn('match_feature_id');
        });
    }

    public function down(): void
    {
        Schema::table('m_kits_slots', function (Blueprint $table) {
            $table->unsignedBigInteger('match_feature_id')->nullable();
        });

        DB::table('m_kits_slots')
            ->whereNotNull('match_features')
            ->orderBy('id')
            ->chunkById(200, function ($slots) {
                foreach ($slots as $slot) {
                    $ids = (array) json_decode((string) $slot->match_features, true);

                    DB::table('m_kits_slots')
                        ->where('id', $slot->id)
                        ->update(['match_feature_id' => ((int) reset($ids)) ?: null]);
                }
            });

        Schema::table('m_kits_slots', fn (Blueprint $table) => $table->dropColumn('match_features'));
    }
};
