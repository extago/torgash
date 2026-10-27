<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed what, and to what.
 *
 * The premise of everything else in this module (PLAN §25.2/2): an anomaly
 * cannot be noticed in actions nobody writes down. But it earns its place on
 * its own long before that — today the question "who put the price at 1 ₽" has
 * no answer anywhere in the engine, and neither has "who turned stock control
 * off in March".
 *
 * One row per CHANGE, which is the opposite decision from the errors table next
 * to it, and deliberately: an error repeated a thousand times is one fact about
 * the code, while a price edited a thousand times is a thousand facts about a
 * shop. That is also the whole cost of this part, so the retention setting ships
 * with the feature rather than after it (§25.3).
 *
 * The actor is kept BOTH ways — `user_id` and a snapshot of the name — for the
 * same reason an order carries `payment_method_name` beside `payment_method_id`:
 * the link answers "who is this person now", the snapshot survives them being
 * renamed or deleted. There is no foreign key for exactly that reason: deleting
 * a manager must not delete the record of what they did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_guard_audit', function (Blueprint $table) {
            $table->id();

            // created | updated | deleted | restored, plus the named acts that
            // are not model writes at all (a setting, a module) and `truncated`,
            // which is this table admitting it stopped writing.
            $table->string('action', 16);

            // The model's class where there was one, a bare word ('setting',
            // 'module') where the thing changed is not a row anybody could load.
            $table->string('entity_type')->nullable();

            // A string, because not every key here is an integer: a setting is
            // addressed by module and name.
            $table->string('entity_id', 190)->nullable();

            // What the row was CALLED at the time. The whole point of a trail is
            // that it still reads after the thing is gone, and "Product #4192"
            // tells nobody what was deleted.
            $table->string('entity_title')->nullable();

            // { field: { from: …, to: … } } — one shape for all three actions,
            // with the side that does not exist simply absent. Values are
            // truncated and anything whose name says "credential" is stored as
            // a mask: an audit trail must never become the second place the
            // gateway's secret is written down.
            $table->jsonb('changes')->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();

            $table->string('route')->nullable();
            $table->text('url')->nullable();
            $table->string('method', 10)->nullable();
            $table->string('ip', 45)->nullable();

            // No `updated_at`: an audit row is written once and never edited,
            // and a column suggesting otherwise would be a lie in the schema.
            $table->timestamp('created_at')->index();

            // "Everything this person did", "everything that happened to this
            // order", and the prune.
            $table->index(['user_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_guard_audit');
    }
};
