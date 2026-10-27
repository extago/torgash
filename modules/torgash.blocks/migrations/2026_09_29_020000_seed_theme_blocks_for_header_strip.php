<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The header's top strip gained two zones (PLAN §39,
 * `storefront.header.top_start` / `top_end`), and the theme puts its "top"
 * menu in the first through a `menu` block — which replaces the links the
 * light header used to draw by hand. The same pass as the earlier seeding
 * migrations, with the same guard: a zone that already has a block is the
 * shop's and is left alone.
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
                $name = $zone['name'] ?? null;
                $defaults = array_values(array_filter($zone['default_blocks'] ?? [], fn ($b) => ! empty($b['type'])));

                if (! $name || $defaults === [] || DB::table('m_blocks')->where('zone', $name)->exists()) {
                    continue;
                }

                $now = now();

                foreach ($defaults as $sort => $block) {
                    DB::table('m_blocks')->insert([
                        'zone' => $name,
                        'type' => $block['type'],
                        'source' => 'theme',
                        'title' => $block['title'] ?? null,
                        'tabs' => $block['tabs'] ?? null,
                        'settings' => json_encode($block['settings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'sort' => $sort,
                        'enabled' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    /** Empty for the first pass's reason: a seeded block cannot be told from an edited one. */
    public function down(): void {}
};
