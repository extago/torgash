<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The phone's drawer used two blocks that bypassed menus (PLAN §39.6): the
 * `categories` block (the tree, with no say in what it shows) and a `links`
 * block of typed text. The theme now lays out `menu` blocks in their place;
 * a shop whose drawer is still EXACTLY what the theme laid out gets the same
 * swap here, and one that edited either block keeps its own — its words are
 * the shop's decision, not the theme's.
 *
 * Down leaves them: the old types still exist, and a menu block the shop may
 * have edited since cannot be told from a converted one.
 */
return new class extends Migration
{
    protected const ZONE = 'storefront.mobile.menu';

    public function up(): void
    {
        if (! Schema::hasTable('m_blocks')) {
            return;
        }

        $empty = ['heading' => ['en' => '', 'ru' => '']];
        $links = [
            'heading' => ['en' => '', 'ru' => ''],
            'style' => 'rows',
            'items' => [
                'en' => "!Sale | /sale | badge-percent | Products on sale\nBrands | /brands | tag | Makers and brands\nWishlist | /wishlist | heart\nComparison | /compare | square-kanban",
                'ru' => "!Акции | /sale | badge-percent | Товары со скидкой\nБренды | /brands | tag | Каталог марок и брендов\nИзбранное | /wishlist | heart\nСравнение | /compare | square-kanban",
            ],
        ];

        $this->swap('categories', $empty, ['menu' => 'catalog', 'look' => 'drawer', 'depth' => 2, 'icons' => true, 'badges' => true, 'heading' => ['en' => '', 'ru' => '']]);
        $this->swap('links', $links, ['menu' => 'mobile-links', 'look' => 'rows', 'depth' => 1, 'icons' => true, 'badges' => true, 'heading' => ['en' => '', 'ru' => '']]);
    }

    protected function swap(string $type, array $asLaidOut, array $settings): void
    {
        foreach (DB::table('m_blocks')->where('zone', self::ZONE)->where('type', $type)->where('source', 'theme')->get() as $row) {
            if (! $this->same(json_decode((string) $row->settings, true) ?? [], $asLaidOut)) {
                continue;
            }

            DB::table('m_blocks')->where('id', $row->id)->update([
                'type' => 'menu',
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }
    }

    /** jsonb does not keep key order, so compare as sorted structures. */
    protected function same(array $a, array $b): bool
    {
        $sort = function (array $x) use (&$sort) {
            ksort($x);

            return array_map(fn ($v) => is_array($v) ? $sort($v) : $v, $x);
        };

        return $sort($a) === $sort($b);
    }

    public function down(): void {}
};
