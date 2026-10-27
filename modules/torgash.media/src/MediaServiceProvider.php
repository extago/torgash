<?php

namespace Torgash\Media;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Owner registrations come from other modules' providers, so the
        // registry has to be a singleton that outlives any single request path.
        $this->app->singleton(MediaOwners::class);
        $this->app->singleton(ImageProcessor::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'can:admin.access'])
            ->prefix('admin/media')
            ->name('admin.media.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // Where the rich editor may put an image that goes INSIDE a text. The
        // question is asked by torgash.editor, which has never heard of this
        // module; the answer is null for a record nobody registered as keeping
        // content images, for one that has not been saved yet, and for somebody
        // without the ability to edit it — so an editor with nowhere to upload
        // to simply draws no image button.
        //
        // Answered lazily, in a closure, because provider boot order is not
        // ours to depend on: the owner registrations arrive from other modules'
        // providers, and this must read the registry when the question is
        // asked rather than when it is registered.
        hook_listen('editor.images', fn (mixed $current, array $context) => $current
            ?? Content::context($context['record'] ?? null));

        // A gallery inside a text (`<div data-gallery>`, the editor's gallery
        // node) on any storefront page: the words and icons it needs, inert
        // inside one <template>, and a loader that imports the script only on
        // a page that has a gallery — and again after HTMX brings one.
        hook_listen('core.footer', fn (string $html) => $html.view('torgash.media::content-gallery')->render());

        // Pictures inside a printed text get their width and height, so the
        // words under them do not jump when they load (ContentImageSizes).
        hook_listen('core.content.cleaned', fn (string $html) => ContentImageSizes::fill($html));

        // Photo links an import collected (torgash.exim's batch, filled by
        // whichever module owns the rows — catalog's "Photos (links)"),
        // fetched once the rows are committed, as a task of their own.
        hook_listen('exim.imported', function (array $context) {
            $batch = $context['batch'] ?? null;

            if ($batch && method_exists($batch, 'items') && ($items = $batch->items('images'))) {
                Jobs\FetchImages::start($items);
            }
        });
    }
}
