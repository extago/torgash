<?php

namespace Torgash\Collections;

use App\Core\Modules\ModuleLifecycle;
use App\Core\Storefronts\StorefrontRoutes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Torgash\Collections\Models\Collection;

class CollectionsServiceProvider extends ServiceProvider implements ModuleLifecycle
{
    public function boot(): void
    {
        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        // Pictures and galleries inside a collection's description, as in a product's.
        hook_listen('media.owners', fn (array $context) => $context['owners']->register('collection', Collection::class, 'collections.manage', content: true));

        Route::middleware(['web', 'auth', 'can:collections.manage'])
            ->prefix('admin/collections')
            ->name('admin.collections.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // "Products of a collection" in any product block — the catalogue's
        // own block, the same way a sale's products are offered.
        hook_listen('catalog.product_sources', fn (array $sources) => $sources + [
            'collection' => [
                'label' => 'Collection',
                'choices' => fn () => Collection::orderBy('sort')->orderByDesc('id')->get()
                    ->mapWithKeys(fn (Collection $c) => [$c->id => $c->t('name')])->all(),
                'apply' => function (Builder $query, string $arg) {
                    $collection = Collection::find((int) $arg);

                    return $collection && $collection->isShown() ? $collection->constrain($query) : $query->whereRaw('1 = 0');
                },
                'heading' => fn (string $arg) => Collection::find((int) $arg)?->t('name'),
                'url' => function (string $arg) {
                    $collection = Collection::shown()->find((int) $arg);

                    return $collection ? route('collections.show', $collection->slug) : null;
                },
            ],
        ]);

        // A menu item can point at the list by name (torgash.menus, PLAN §39).
        hook_listen('menus.pages', fn (array $pages) => $pages + [
            'route:collections.index' => ['label' => 'Collections', 'route' => 'collections.index', 'icon' => 'layers'],
        ]);

        hook_listen('catalog.sitemap.urls', function (array $urls) {
            $shown = Collection::shown()->get();

            if ($shown->isEmpty()) {
                return $urls;
            }

            $urls[] = ['loc' => route('collections.index'), 'changefreq' => 'weekly', 'priority' => '0.5'];

            foreach ($shown as $collection) {
                $urls[] = ['loc' => route('collections.show', $collection->slug), 'lastmod' => $collection->updated_at?->toAtomString(), 'changefreq' => 'weekly', 'priority' => '0.6'];
            }

            return $urls;
        });

        $this->blockType();
    }

    protected function blockType(): void
    {
        if (! class_exists(\Torgash\Blocks\BlockTypes::class)) {
            return;
        }

        \Torgash\Blocks\BlockTypes::register('collections', [
            'label' => 'Collections',
            'description' => 'Tiles of collections, each with its cover and name — the ones you pick, or every listed one.',
            'icon' => 'layers',
            'fits' => ['wide'],
            'view' => 'torgash.collections::blocks.collections',
            'schema' => [
                ['key' => 'heading', 'type' => 'text', 'translatable' => true, 'label' => 'Heading'],
                [
                    'key' => 'collections', 'type' => 'picks', 'label' => 'Collections',
                    'help' => 'Empty — every collection in the list.',
                    'options' => fn () => Collection::orderBy('sort')->orderByDesc('id')->get()
                        ->mapWithKeys(fn (Collection $c) => [$c->id => $c->t('name')])->all(),
                ],
                ['key' => 'limit', 'type' => 'number', 'default' => 6, 'label' => 'How many at most'],
            ],
        ]);

        \Torgash\Blocks\BlockTypes::register('collection_looks', [
            'label' => 'Looks of a collection',
            'description' => 'The pictures of a collection, with points on the products in them.',
            'icon' => 'scan-search',
            'fits' => ['wide'],
            'view' => 'torgash.collections::blocks.looks',
            'schema' => [
                ['key' => 'heading', 'type' => 'text', 'translatable' => true, 'label' => 'Heading'],
                [
                    'key' => 'collection', 'type' => 'select', 'label' => 'Collection',
                    'options' => fn () => Collection::orderBy('sort')->orderByDesc('id')->get()
                        ->mapWithKeys(fn (Collection $c) => [$c->id => $c->t('name')])->all(),
                ],
                ['key' => 'limit', 'type' => 'number', 'default' => 3, 'label' => 'How many pictures at most'],
            ],
        ]);
    }

    public function install(): void {}

    /**
     * Its tables go with the migrations; the covers and looks are on the
     * public disk, the description's pictures are media rows — every
     * collection through its model, so they go with it.
     */
    public function uninstall(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('m_collections')) {
            Collection::query()->get()->each->delete();
        }

        Storage::disk('public')->deleteDirectory(Collection::FILES);
    }
}
