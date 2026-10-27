<?php

namespace Torgash\Seo;

use App\Core\Support\PerRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per request: a listing screen asks about fifty rows and they nearly
        // all resolve to the same one or two templates. Scoped rather than a
        // static, for the reason every memo in this engine is.
        $this->app->scoped(Templates::class);
        $this->app->scoped(Subjects::class);

        // Forgotten between requests rather than merely declared per request —
        // see App\Core\Support\PerRequest for why that is not the same thing.
        PerRequest::forget($this->app, Templates::class, Subjects::class);
    }

    public function boot(): void
    {
        $this->app->make(Metadata::class)->register();
        $this->app->make(Images::class)->register();

        // What must be true about what the shop publishes. Answered lazily, so
        // this costs nothing when torgash.guard is not installed.
        hook_listen('guard.invariants', fn (array $all) => $all
            + $this->app->make(Invariants\SeoInvariants::class)->register());

        Route::middleware(['web', 'auth', 'can:seo.manage'])
            ->prefix('admin/seo')
            ->name('admin.seo.')
            ->group(dirname(__DIR__).'/routes-admin.php');
    }
}
