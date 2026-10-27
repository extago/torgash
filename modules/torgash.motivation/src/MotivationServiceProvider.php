<?php

namespace Torgash\Motivation;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MotivationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The catalog page calls this filter with '' and appends whatever
        // comes back — same append pattern as cart.rows_before_total — so
        // another module's card can stack below ours without either one
        // knowing about the other.
        hook_listen('catalog.product.after_buy_box', function (string $value, array $context) {
            return $value.view('torgash.motivation::partials.block', ['product' => $context['product']])->render();
        });

        Route::middleware(['web', 'auth', 'can:motivation.manage'])
            ->prefix('admin/motivation')
            ->name('admin.motivation.')
            ->group(dirname(__DIR__).'/routes-admin.php');
    }
}
