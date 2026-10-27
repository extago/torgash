<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The theme's `default_blocks` again, for zones that have appeared since the
 * first seeding ran — today `storefront.home.products`, where the home page's
 * product tabs used to be written into the template by hand.
 *
 * The same guard as the first pass (2026_08_26_000500), and the only one that
 * is safe for an upgrade: a zone holding ANYTHING is left completely alone. A
 * migration cannot tell "the owner arranged this" from "the theme did", so it
 * does not try — and an owner who wants the theme's blocks back in an arranged
 * zone has Blocks → "Restore from theme" for that, which says what it will do
 * first.
 *
 * Without this pass a shop upgrading would find its home page with no products
 * on it at all: the template no longer draws them, and the zone that does is
 * new and therefore empty. The rows carry `source = theme` and their `tabs`
 * word, exactly as ThemeDefaults::insert() writes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $base = config('torgash.themes_path');

        if (! is_string($base) || ! is_dir($base)) {
            return;
        }

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

        if (! $name || $defaults === []) {
            return;
        }

        // On a FRESH install the first pass has already laid this zone out —
        // it reads every theme.json on disk — but it predates the `tabs`
        // column and wrote none. The word is put onto those rows here, matched
        // the way `source` was backfilled (zone, type, title, and the theme's
        // own mark), or a new shop's home page would draw its tabs as three
        // stacked grids.
        foreach ($defaults as $block) {
            if (empty($block['type']) || empty($block['tabs'])) {
                continue;
            }

            DB::table('m_blocks')
                ->where('zone', $name)
                ->where('type', $block['type'])
                ->where('source', 'theme')
                ->whereNull('tabs')
                ->where(fn ($q) => isset($block['title']) ? $q->where('title', $block['title']) : $q->whereNull('title'))
                ->update(['tabs' => $block['tabs']]);
        }

        if (DB::table('m_blocks')->where('zone', $name)->exists()) {
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
                'source' => 'theme',
                'title' => $block['title'] ?? null,
                'tabs' => $block['tabs'] ?? null,
                'settings' => json_encode($block['settings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort' => $sort++,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** Empty for the first pass's reason: a seeded block cannot be told from an edited one. */
    public function down(): void {}
};
