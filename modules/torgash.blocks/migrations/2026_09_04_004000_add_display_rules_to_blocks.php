<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a block is shown, beyond "always, wherever its zone is".
 *
 * `enabled` answers "is this block in use at all", which is a different
 * question and stays what it is. This is the one a builder needs: the same zone
 * on the same page shows different things to different visitors, on different
 * products, in different languages.
 *
 * The rules are a list of `{kind, operator, value}` — the shape both existing
 * condition engines use (shipping's and automations'), and the shape core's
 * `App\Core\Display\RuleKinds` registry now makes extensible. A jsonb array
 * because order is meaningless here but the list is: rules are read together
 * or not at all, never queried by SQL.
 *
 * `rules_match` is ALL or ANY, and it is a real question rather than a
 * preference: "wholesale customers on furniture" and "wholesale customers OR on
 * furniture" are both things merchants ask for, and choosing one for them means
 * the other has to be built out of duplicated blocks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_blocks', function (Blueprint $table) {
            $table->jsonb('rules')->nullable()->after('settings');
            $table->string('rules_match', 3)->default('all')->after('rules');
        });
    }

    public function down(): void
    {
        Schema::table('m_blocks', function (Blueprint $table) {
            $table->dropColumn(['rules', 'rules_match']);
        });
    }
};
