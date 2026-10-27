<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whose brick is this — the theme's, or the shop owner's?
 *
 * Nothing needed the answer while blocks only ever went one way: the theme's
 * defaults were laid out once by a migration and everything afterwards was the
 * owner's business. Restoring a zone from the theme needs it, and needs it
 * badly: "replace this zone with what the theme ships" has to be able to say
 * out loud what it is about to throw away, and a list that cannot tell the
 * theme's own header logo from a banner the owner spent an afternoon on is not
 * a warning, it is a shrug.
 *
 * `theme` or null, and null means the owner's. Not a foreign key to anything
 * and not the theme's NAME: what a restore needs to know is "did this arrive
 * from a theme file or from a person", and a name would go stale the moment a
 * theme is renamed while answering no question anybody asks.
 *
 * The backfill is best-effort ON PURPOSE. Rows seeded before this column
 * existed carry no mark, so they are matched the same way a restore matches —
 * zone, type and title — against what the themes on disk actually ship today.
 * A block the owner has since edited beyond recognition stays theirs, which is
 * the safe direction to be wrong in: the worst case is that "replace" warns
 * about a block it did not need to warn about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_blocks', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('type');
        });

        foreach ($this->themeDefaults() as $zone => $defaults) {
            foreach ($defaults as $block) {
                DB::table('m_blocks')
                    ->where('zone', $zone)
                    ->where('type', $block['type'])
                    ->where(fn ($q) => isset($block['title'])
                        ? $q->where('title', $block['title'])
                        : $q->whereNull('title'))
                    ->update(['source' => 'theme']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('m_blocks', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }

    /**
     * Every theme on disk, not just the active one — same reasoning the seeding
     * migration gives: a shop that switches themes later should find that
     * theme's own blocks already recognised as the theme's.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function themeDefaults(): array
    {
        $base = config('torgash.themes_path');

        if (! is_string($base) || ! is_dir($base)) {
            return [];
        }

        $found = [];

        foreach (glob($base.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'theme.json') ?: [] as $file) {
            $meta = json_decode((string) file_get_contents($file), true);

            foreach ($meta['zones'] ?? [] as $zone) {
                foreach ($zone['default_blocks'] ?? [] as $block) {
                    if (! empty($zone['name']) && ! empty($block['type'])) {
                        $found[$zone['name']][] = $block;
                    }
                }
            }
        }

        return $found;
    }
};
