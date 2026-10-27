<?php

namespace Torgash\Blog\Demo;

use App\Core\Demo\DemoPack;
use Torgash\Blog\Models\Post;
use Torgash\Blog\Models\Rubric;
use Torgash\Catalog\Models\Product;

/**
 * Its part of the demo shop (PLAN §55.3): two rubrics and four articles —
 * three buying guides with `{product:SKU}` cards in the text and a news item
 * about the demo's smartphone sale — each with its "products in this
 * article" carousel, published days apart so the feed has an order.
 *
 * Read from `database/demo/blog/blog.json`. An address the shop already uses
 * is left to the shop's own article or rubric; an SKU the pack did not make
 * is left out of the carousel (and its card in the text draws nothing).
 */
class BlogDemo
{
    public function install(DemoPack $pack): void
    {
        $data = $pack->json('blog/blog');
        $rubrics = [];

        foreach ((array) ($data['rubrics'] ?? []) as $sort => $row) {
            $rubrics[$row['slug']] = Rubric::where('slug', $row['slug'])->first() ?? Rubric::create([
                'slug' => $row['slug'],
                'name' => $row['name'],
                'icon' => $row['icon'] ?? null,
                'color' => $row['color'] ?? null,
                'sort' => $sort,
                'enabled' => true,
            ]);
        }

        $made = 0;

        foreach ((array) ($data['posts'] ?? []) as $row) {
            if (Post::where('slug', $row['slug'])->exists()) {
                continue;
            }

            $at = now()->subDays((int) ($row['days_ago'] ?? 1))->setTime(10, 0);

            $post = Post::create([
                'slug' => $row['slug'],
                'title' => $row['title'],
                'lead' => $row['lead'] ?? null,
                'body' => $row['body'],
                'status' => Post::PUBLISHED,
                'published_at' => $at,
                'comments_enabled' => true,
            ]);

            foreach ((array) ($row['rubrics'] ?? []) as $position => $slug) {
                if (isset($rubrics[$slug])) {
                    $post->rubrics()->attach($rubrics[$slug]->id, ['position' => $position]);
                }
            }

            $position = 0;
            foreach ((array) ($row['products'] ?? []) as $sku) {
                if ($id = Product::where('sku', $sku)->value('id')) {
                    $post->products()->attach($id, ['position' => $position++]);
                }
            }

            $made++;
        }

        $this->homeBlock();

        $pack->say($made.' articles.');
    }

    /**
     * The latest articles at the bottom of the home page, when torgash.blocks
     * is there and the home page has no such block yet — the same condition
     * the block type itself is registered on.
     */
    protected function homeBlock(): void
    {
        $block = 'Torgash\\Blocks\\Models\\Block';

        if (! class_exists($block) || $block::where('type', 'blog_posts')->where('zone', 'like', 'storefront.home.%')->exists()) {
            return;
        }

        $block::create([
            'zone' => 'storefront.home.bottom',
            'type' => 'blog_posts',
            'title' => 'From the blog',
            'enabled' => true,
            'sort' => 100,
            'settings' => ['heading' => ['en' => 'From the blog', 'ru' => 'Из блога'], 'layout' => 'featured', 'limit' => 4],
            'rules_match' => 'all',
        ]);
    }
}
