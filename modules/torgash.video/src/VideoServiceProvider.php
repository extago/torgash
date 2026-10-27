<?php

namespace Torgash\Video;

use App\Core\Modules\ModuleLifecycle;
use App\Core\Support\PerRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Product;
use Torgash\Media\Gallery;
use Torgash\Video\Models\Video;
use Torgash\Video\Sources\Sources;

/**
 * Videos beside a product's photos (PLAN §50).
 *
 * Everything reaches the catalogue through its own hooks — the form's tab,
 * the gallery's items, the card's meta line, the page's structured data — so
 * the catalogue has never heard of a video, and a shop without this module
 * has exactly the product page it had.
 */
class VideoServiceProvider extends ServiceProvider implements ModuleLifecycle
{
    public function register(): void
    {
        $this->app->singleton(Sources::class);
        $this->app->singleton(VideoOwners::class);
        $this->app->singleton(Videos::class);
        $this->app->singleton(VideoMarks::class);
        // "Has it a video" is a fact about rows an admin may have just
        // changed: a reused application must not answer from the last visit.
        PerRequest::forget($this->app, VideoMarks::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'can:admin.access'])
            ->prefix('admin/video')
            ->name('admin.video.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        $this->app->make(VideoOwners::class)->register('product', Product::class, 'catalog.manage');

        $this->productForm();
        $this->productPage();
        $this->catalogueCards();
        $this->exchange();

        $this->app->booted(fn () => $this->otherOwners());
        $this->videoToken();

        // Halves of uploads nobody finished — a closed tab, a lost line.
        hook_listen('core.schedule', function (array $context) {
            $context['schedule']->call(fn () => app(Uploads::class)->sweep())->dailyAt('04:10')->name('video:sweep-uploads');
        });
    }

    protected function productForm(): void
    {
        hook_listen('catalog.product.form_tabs', function (array $tabs, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $tabs;
            }

            $tabs[] = [
                'key' => 'videos',
                'label' => __('Video'),
                'html' => view('torgash.video::admin.editor', ['videoOwner' => 'product', 'videoRecord' => $product])->render(),
            ];

            return $tabs;
        });

        hook_listen('catalog.product.saved', function (array $context) {
            $product = $context['product'] ?? null;
            $input = $context['request']?->input('videos');

            if ($product instanceof Product && is_array($input)) {
                app(Videos::class)->applyForm('product', (int) $product->id, $input);
            }
        });

        // A product in the bin keeps its videos — it may come back. Gone for
        // good, it takes them and their posters along.
        Product::forceDeleted(fn (Product $product) => app(Videos::class)->forget('product', (int) $product->id));
    }

    protected function productPage(): void
    {
        // After the photos, in the order the admin left them.
        hook_listen('catalog.product.gallery', function (array $items, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $items;
            }

            foreach (app(Videos::class)->of('product', (int) $product->id) as $video) {
                if (! $video->source()) {
                    continue;
                }

                $poster = $video->poster();
                $title = $video->t('title') ?: (string) $product->t('name');

                $items[] = [
                    'video' => true,
                    'thumb' => $poster ? Gallery::thumbUrl($poster, 120) : null,
                    'stage' => $poster ? Gallery::thumbUrl($poster, 800) : null,
                    'full' => null,
                    'width' => null,
                    'height' => null,
                    'alt' => $title,
                    'platform' => $video->platform(),
                    'player' => view('torgash.video::storefront.player', ['playerVideo' => $video, 'playerTitle' => $title, 'playerPoster' => $poster ? Gallery::thumbUrl($poster, 800) : null])->render(),
                ];
            }

            return $items;
        });

        hook_listen('catalog.product.structured_data', function (array $schema, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $schema;
            }

            $at = '@';
            $objects = [];

            foreach (app(Videos::class)->of('product', (int) $product->id) as $video) {
                $poster = $video->poster();

                // A VideoObject without a picture is one Google will not show,
                // and says so in its report; better not to state it.
                if (! $poster || ! $video->source()) {
                    continue;
                }

                $name = $video->t('title') ?: (string) $product->t('name');

                $objects[] = array_filter([
                    $at.'type' => 'VideoObject',
                    'name' => $name,
                    'description' => $name,
                    'thumbnailUrl' => $poster->getUrl(),
                    // When the shop put it on the page — the platform's own
                    // upload date is not something every platform tells.
                    'uploadDate' => $video->created_at?->toIso8601String(),
                    // A platform's player is embedded; the shop's own file IS the content.
                    'embedUrl' => $video->isFile() ? null : $video->playerUrl(),
                    'contentUrl' => $video->isFile() ? $video->playerUrl() : null,
                    'duration' => $video->duration ? 'PT'.$video->duration.'S' : null,
                ]);
            }

            if ($objects !== []) {
                $schema['subjectOf'] = $objects;
            }

            return $schema;
        });
    }

    /**
     * The records other modules registered (`video.owners`): their form
     * shows the video list, their save applies titles and order, their
     * deletion takes the videos along. Wired after boot, when every module
     * has answered.
     */
    protected function otherOwners(): void
    {
        foreach (app(VideoOwners::class)->all() as $key => $owner) {
            $seams = $owner['seams'];

            if (! empty($seams['form'])) {
                hook_listen($seams['form'], function (string $html, array $context) use ($key, $owner) {
                    $record = $context['record'] ?? null;

                    return $record instanceof $owner['model']
                        ? $html.view('torgash.video::admin.editor', ['videoOwner' => $key, 'videoRecord' => $record, 'videoInText' => ! empty($owner['seams']['context'])])->render()
                        : $html;
                });
            }

            if (! empty($seams['saved'])) {
                hook_listen($seams['saved'], function (array $context) use ($key, $owner) {
                    $record = $context['record'] ?? null;
                    $input = $context['request']?->input('videos');

                    if ($record instanceof $owner['model'] && is_array($input)) {
                        app(Videos::class)->applyForm($key, (int) $record->getKey(), $input);
                    }
                });
            }

            $owner['model']::deleted(function ($record) use ($key) {
                // A model in a bin is not gone: only a real deletion takes the videos.
                if (! method_exists($record, 'isForceDeleting') || $record->isForceDeleting()) {
                    app(Videos::class)->forget($key, (int) $record->getKey());
                }
            });
        }
    }

    /**
     * `{video:ID}` in an article's or a page's text: one of THAT record's
     * videos (an id of somebody else's plays nothing), drawn as the gallery
     * draws one — the shop's poster and a play button, the player put on the
     * page by the press of play. Alone in a paragraph it replaces the
     * paragraph (`block`).
     */
    protected function videoToken(): void
    {
        app(\App\Core\Content\Placeholders::class)->register('video', [
            'label' => 'A video of this record',
            'group' => 'page',
            'raw' => true,
            'block' => true,
            'argument' => ['label' => 'id', 'example' => '1'],
            'resolve' => function (array $context = [], ?string $id = null) {
                $found = app(VideoOwners::class)->fromContext($context);

                if (! $found || ! ctype_digit(trim((string) $id))) {
                    return '';
                }

                [$key, $record] = $found;
                $video = Video::query()->ownedBy($key, (int) $record->getKey())->with('media')->find((int) $id);

                if (! $video || ! $video->source()) {
                    return '';
                }

                return view('torgash.video::storefront.inline', [
                    'inlineVideo' => $video,
                    'inlineTitle' => $video->t('title') ?: (string) ($record->t('title') ?? ''),
                ])->render();
            },
        ]);
    }

    /**
     * A "Videos" column in the product exchange (exim): links, comma
     * separated — the same registry reads them as reads an admin's paste,
     * with the same refusal of hosts nobody allowed. A shop moving over from
     * another engine brings its YouTube links this way.
     */
    protected function exchange(): void
    {
        hook_listen('exim.schema.product', fn (array $fields) => [...$fields, [
            'key' => 'videos',
            'group' => 'texts',
            'label' => 'Videos (links, comma separated)',
            'get' => fn (Product $product) => app(Videos::class)->links('product', (int) $product->id),
            'set' => null,
            'after' => function (Product $product, $value) {
                $unknown = app(Videos::class)->syncLinks('product', (int) $product->id, (string) $value);

                if ($unknown !== []) {
                    logger()->info('Exim: video links not recognised', ['product' => $product->sku, 'links' => $unknown]);
                }
            },
        ]]);

        // A link added or removed is a change of the product feed that has the column.
        hook_listen('exim.watch.product', fn (array $tables) => [...$tables, 'm_video_videos']);
    }

    protected function catalogueCards(): void
    {
        hook_listen('catalog.products.listed', function (array $context) {
            if (setting('torgash.video', 'card_icon')) {
                app(VideoMarks::class)->prime($context['products'] ?? []);
            }
        });

        hook_listen('catalog.product.card_meta', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product || ! setting('torgash.video', 'card_icon') || ! app(VideoMarks::class)->has((int) $product->id)) {
                return $html;
            }

            return $html.view('torgash.video::storefront.card-mark')->render();
        });
    }

    public function install(): void {}

    /**
     * The posters are media-library files belonging to Video rows; dropping
     * the table would leave them on disk and in `media` with nothing to own
     * them. Deleted row by row, while the table is still there.
     */
    public function uninstall(): void
    {
        if (Schema::hasTable('m_video_videos')) {
            Video::query()->get()->each->delete();
        }

        // Uploaded files, and anything an interrupted delete left behind;
        // halves of uploads in the scratch folder too.
        \Illuminate\Support\Facades\Storage::disk('public')->deleteDirectory('videos');
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/video-uploads'));
    }
}
