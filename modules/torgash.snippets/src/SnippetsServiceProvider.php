<?php

namespace Torgash\Snippets;

use App\Core\Support\PerRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Snippets\Models\Snippet;

class SnippetsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Placements::class);

        // ...and forgotten when the next request begins: which snippets are
        // visible is a property of the page. See App\Core\Support\PerRequest.
        PerRequest::forget($this->app, Placements::class);
    }

    public function boot(): void
    {
        // Every snippet and what it is for consent, for a privacy screen to list (PLAN §49).
        hook_listen('core.consent.inventory', fn (array $rows) => [...$rows, ...Snippet::query()->orderBy('position')->orderBy('id')->get()
            ->map(fn (Snippet $snippet) => [
                'label' => $snippet->name,
                'category' => $snippet->category ?: 'necessary',
                'guess' => Snippet::guessCategory((string) $snippet->code),
                'url' => route('admin.snippets.edit', $snippet),
            ])->all()]);

        // The three slots core's theme prints. Answered lazily and per slot, so
        // a page with no snippets at all costs one query and nothing else.
        foreach (Snippet::PLACEMENTS as $placement) {
            hook_listen(
                'core.'.$placement,
                fn (string $html) => $html.app(Placements::class)->render($placement),
            );
        }

        Route::middleware(['web', 'auth', 'can:snippets.manage'])
            ->prefix('admin/snippets')
            ->name('admin.snippets.')
            ->group(dirname(__DIR__).'/routes-admin.php');
    }
}
