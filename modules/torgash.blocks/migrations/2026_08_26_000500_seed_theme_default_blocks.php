<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Default content a THEME ships for its own zones.
 *
 * A theme.json zone may carry `default_blocks`, a list of {type, title,
 * settings}. This seeds them once, so a fresh install has a header that looks
 * finished instead of an empty zone the merchant has to discover and fill.
 *
 * Why the theme owns this rather than a module or the core seeder:
 *
 *   - it IS theme content. The markup positions itself with the theme's own
 *     classes and belongs to whoever drew the header;
 *   - Tailwind scans `themes/` (see the @source lines in resources/css/app.css),
 *     and it cannot scan the database. Default block markup written in a theme
 *     file therefore keeps its classes in the compiled stylesheet; the same
 *     markup pasted straight into a DB row would render unstyled after the next
 *     build. This is the whole reason the snippet lives in theme.json and not
 *     inline below.
 *
 * Guarded per zone, not per block: a zone that already contains anything is
 * left completely alone, because the merchant has clearly arranged it and
 * "helpfully" adding a block back is the kind of thing an upgrade must never do.
 *
 * Settings are inserted as the theme wrote them, WITHOUT BlockTypes::sanitize():
 * a theme file is code under version control, at the same trust level as a
 * template, and sanitize() would drop permission-gated fields here anyway since
 * a migration runs with no authenticated user.
 */
return new class extends Migration
{
    public function up(): void
    {
        $base = config('torgash.themes_path');

        if (! is_string($base) || ! is_dir($base)) {
            return;
        }

        // Every theme on disk, not just the active one: a store that switches
        // themes later should find that theme's zones already furnished, and a
        // block sitting in a zone no active template renders costs nothing.
        foreach (glob($base.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'theme.json') ?: [] as $file) {
            $meta = json_decode((string) file_get_contents($file), true);

            foreach ($meta['zones'] ?? [] as $zone) {
                $this->seedZone($zone);
            }
        }
    }

    protected function seedZone(array $zone): void
    {
        $name = $zone['name'] ?? null;
        $defaults = $zone['default_blocks'] ?? [];

        if (! $name || $defaults === [] || DB::table('m_blocks')->where('zone', $name)->exists()) {
            return;
        }

        $sort = 0;
        $now = now();

        foreach ($defaults as $block) {
            if (empty($block['type'])) {
                continue;
            }

            DB::table('m_blocks')->insert([
                'zone' => $name,
                'type' => $block['type'],
                'title' => $block['title'] ?? null,
                'settings' => json_encode($block['settings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort' => $sort++,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Deliberately empty. Rolling this back cannot tell a seeded block from one
     * the merchant has since edited, and uninstalling the module drops the
     * whole table anyway.
     */
    public function down(): void {}
};
