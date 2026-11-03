<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backups as plans (PLAN §67): WHERE (destinations), WHAT / WHEN / HOW / WHERE
 * TO (plans), and the journal (runs, and one copy row per destination a run
 * was stored in).
 *
 * The shop's settings of §45 become the plan "Main" on the destination they
 * named, with the old naming — so the copies already made are recognised and
 * nothing a shop had changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // disk (one of the shop's own disks) | folder | s3 | …
            $table->string('driver', 32);
            $table->jsonb('settings')->default('{}');
            // Passwords and keys, encrypted with APP_KEY.
            $table->text('secrets')->nullable();
            // A folder inside the destination, with placeholders ({plan}, {Y}…).
            $table->string('path_template')->default('');
            // The server's own folder: updates keep their backup there; it
            // cannot be deleted.
            $table->boolean('system')->default(false);
            $table->timestamps();
        });

        Schema::create('backup_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 64)->unique();
            $table->boolean('enabled')->default(true);
            // ["database", "files", "code"]
            $table->jsonb('contents')->default('["database"]');
            // [{"kind": "daily", "at": "03:40"}, …]
            $table->jsonb('schedule')->default('[]');
            $table->string('format', 16)->default('zip');
            $table->string('name_template')->default('{shop}-{plan}-{date}-{time}');
            $table->timestamps();
        });

        Schema::create('backup_plan_destination', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('backup_plans')->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('backup_destinations')->restrictOnDelete();
            // {"last": 7, "daily": 0, "weekly": 0, "monthly": 0, "yearly": 0, "days": 0, "gigabytes": 0}
            $table->jsonb('keep')->default('{}');
            $table->unique(['plan_id', 'destination_id']);
            $table->index('destination_id');
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->nullable()->constrained('backup_plans')->nullOnDelete();
            // schedule | manual | update | console | found
            $table->string('reason', 16);
            // queued | running | done | partial | failed
            $table->string('status', 16);
            $table->string('name')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->jsonb('contents')->nullable();
            $table->text('error')->nullable();
            $table->boolean('pinned')->default(false);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // The background task that made it — a run still "running" whose
            // task is no longer is written off as failed.
            $table->unsignedBigInteger('task_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['plan_id', 'status']);
            $table->index('name');
        });

        Schema::create('backup_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('backup_runs')->cascadeOnDelete();
            $table->foreignId('destination_id')->nullable()->constrained('backup_destinations')->nullOnDelete();
            $table->string('path')->nullable();
            // stored | failed | deleted
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['destination_id', 'status']);
        });

        $this->carryOverSettings();
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_copies');
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('backup_plan_destination');
        Schema::dropIfExists('backup_plans');
        Schema::dropIfExists('backup_destinations');
    }

    /** §45's settings → the system destination, maybe the private disk, and the plan "Main". */
    protected function carryOverSettings(): void
    {
        $old = fn (string $key, mixed $default) => ($row = DB::table('settings')->where(['module' => 'core', 'key' => 'backup_'.$key])->whereNull('storefront_id')->value('value')) === null
            ? $default : json_decode($row, true);

        $now = now();
        // The names are the shop's data from now on — in the shop's language.
        $locale = function_exists('content_base_locale') ? content_base_locale() : 'en';
        $word = fn (string $english) => (string) __($english, [], $locale);

        $server = DB::table('backup_destinations')->insertGetId([
            'name' => $word('This server'), 'driver' => 'disk', 'settings' => json_encode(['disk' => 'local', 'root' => 'backups']),
            'path_template' => '', 'system' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $target = $server;

        if ($old('disk', 'local') === 'private') {
            $target = DB::table('backup_destinations')->insertGetId([
                'name' => $word('Private files'), 'driver' => 'disk', 'settings' => json_encode(['disk' => 'private', 'root' => 'backups']),
                'path_template' => '', 'system' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $contents = ['database'];
        if ($old('files', true)) {
            $contents[] = 'files';
        }
        if ($old('code', false)) {
            $contents[] = 'code';
        }

        $schedule = match ($old('schedule', 'off')) {
            'daily' => [['kind' => 'daily', 'at' => '03:40']],
            'weekly' => [['kind' => 'weekly', 'days' => [0], 'at' => '03:40']],
            default => [],
        };

        $plan = DB::table('backup_plans')->insertGetId([
            'name' => $word('Main backup plan'), 'code' => 'main', 'enabled' => true,
            'contents' => json_encode($contents), 'schedule' => json_encode($schedule),
            'format' => 'zip',
            // The old names, so the copies already on the disk are this plan's.
            'name_template' => 'torgash-{date}-{time}',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('backup_plan_destination')->insert([
            'plan_id' => $plan, 'destination_id' => $target,
            'keep' => json_encode(['last' => (int) $old('keep', 7)]),
        ]);

        DB::table('settings')->where('module', 'core')->whereIn('key', ['backup_disk', 'backup_schedule', 'backup_keep', 'backup_files', 'backup_code'])->delete();

        $this->adopt($target === $server ? 'local' : 'private', $target, $plan);
        if ($target !== $server) {
            // An update's backup was made on the same disk as the others.
            $this->adopt('local', $server, null);
        }
    }

    /**
     * The copies §45 made are written into the journal, so the list shows
     * them, the restore finds them and keeping N goes on counting them.
     */
    protected function adopt(string $disk, int $destination, ?int $plan): void
    {
        try {
            $files = \Illuminate\Support\Facades\Storage::disk($disk)->files('backups');
        } catch (\Throwable) {
            return;
        }

        foreach ($files as $path) {
            $name = basename($path);

            if (! preg_match('/^torgash-(\d{4})-(\d{2})-(\d{2})-(\d{2})(\d{2})(\d{2})\.zip$/', $name, $m)) {
                continue;
            }

            try {
                $bytes = (int) \Illuminate\Support\Facades\Storage::disk($disk)->size($path);
            } catch (\Throwable) {
                $bytes = null;
            }

            $at = \Illuminate\Support\Carbon::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6]);
            $run = DB::table('backup_runs')->insertGetId([
                'plan_id' => $plan, 'reason' => 'found', 'status' => 'done', 'name' => $name, 'bytes' => $bytes,
                'started_at' => $at, 'finished_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ]);
            DB::table('backup_copies')->insert([
                'run_id' => $run, 'destination_id' => $destination, 'path' => $name, 'status' => 'stored',
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }
};
