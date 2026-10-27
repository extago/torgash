<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregates, never one row per request: a busy shop would write its own
 * traffic twice. Pages are keyed by ROUTE NAME and hour (a row per product URL
 * would grow with the catalogue), queries by their fingerprint and day.
 *
 * Latency is a histogram (h0…h7), because a mean hides exactly what matters —
 * "usually 90 ms, one in twenty takes two seconds" — and a percentile cannot
 * be added up across hours, while bucket counts can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_speed_routes', function (Blueprint $table) {
            $table->id();
            $table->timestamp('bucket_at');
            $table->string('zone', 16);
            $table->string('route', 190);
            $table->integer('requests')->default(0);
            $table->integer('errors')->default(0);
            // Milliseconds, summed over the hour; each request's own parts add
            // up to its total, so these do too.
            $table->double('total_ms')->default(0);
            $table->double('max_ms')->default(0);
            $table->double('boot_ms')->default(0);
            $table->double('db_ms')->default(0);
            $table->double('http_ms')->default(0);
            $table->double('hooks_ms')->default(0);
            $table->integer('queries')->default(0);
            $table->integer('http_calls')->default(0);
            $table->bigInteger('memory_max')->default(0);
            foreach (range(0, 7) as $bucket) {
                $table->integer('h'.$bucket)->default(0);
            }

            $table->unique(['bucket_at', 'route']);
            $table->index('bucket_at');
        });

        Schema::create('m_speed_modules', function (Blueprint $table) {
            $table->id();
            $table->timestamp('bucket_at');
            $table->string('route', 190);
            $table->string('module', 64);
            $table->integer('requests')->default(0);
            $table->integer('calls')->default(0);
            // The module's hook listeners' OWN time (hooks they fired in turn
            // are counted to whoever answered those) and, within it, database.
            $table->double('ms')->default(0);
            $table->double('db_ms')->default(0);

            $table->unique(['bucket_at', 'route', 'module']);
            $table->index('bucket_at');
        });

        Schema::create('m_speed_queries', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('fingerprint', 32);
            $table->string('route', 190);
            $table->text('sql');
            $table->integer('requests')->default(0);
            $table->integer('executions')->default(0);
            $table->double('total_ms')->default(0);
            $table->double('max_ms')->default(0);
            // The most times one request asked it — the N+1 signal.
            $table->integer('max_repeat')->default(0);
            // Where it was asked from: file:line, or the template it came from.
            $table->string('caller', 255)->nullable();

            $table->unique(['day', 'fingerprint', 'route']);
            $table->index('day');
        });

        Schema::create('m_speed_traces', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at');
            $table->string('zone', 16);
            $table->string('route', 190);
            // The path only: a query string carries search words and tokens.
            $table->string('path', 255);
            $table->smallInteger('status');
            $table->double('total_ms');
            $table->boolean('requested')->default(false);
            $table->jsonb('summary');
            $table->jsonb('spans');

            $table->index('created_at');
            $table->index(['route', 'total_ms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_speed_traces');
        Schema::dropIfExists('m_speed_queries');
        Schema::dropIfExists('m_speed_modules');
        Schema::dropIfExists('m_speed_routes');
    }
};
