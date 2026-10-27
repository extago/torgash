<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The theme's `default_blocks` for `storefront.mobile.contacts` — what the
 * phone icon of the phone's header opens: the number, "Call", the callback
 * and the messengers, laid out for a thumb. Until a block is there the icon
 * keeps dialling straight away.
 *
 * Only this zone, and only while it is EMPTY — the guard of
 * 2026_09_17_000200: a zone holding anything was arranged by someone.
 */
return new class extends Migration
{
    protected const ZONE = 'storefront.mobile.contacts';

    public function up(): void
    {
        $base = config('torgash.themes_path');

        if (! is_string($base) || ! is_dir($base) || DB::table('m_blocks')->where('zone', self::ZONE)->exists()) {
            return;
        }

        foreach (glob($base.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'theme.json') ?: [] as $file) {
            $meta = json_decode((string) file_get_contents($file), true);

            foreach ($meta['zones'] ?? [] as $zone) {
                if (($zone['name'] ?? null) !== self::ZONE || empty($zone['default_blocks'])) {
                    continue;
                }

                $sort = 0;
                $now = now();

                foreach ($zone['default_blocks'] as $block) {
                    if (empty($block['type'])) {
                        continue;
                    }

                    DB::table('m_blocks')->insert([
                        'zone' => self::ZONE,
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

                // One theme's blocks: two themes declaring the zone would
                // otherwise stack both sets on one page.
                return;
            }
        }
    }

    /** Empty for the first pass's reason: a seeded block cannot be told from an edited one. */
    public function down(): void {}
};
