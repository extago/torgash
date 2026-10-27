<?php

namespace Torgash\ShopReviews;

use App\Core\Storefronts\StorefrontRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\ShopReviews\Models\ShopReview;

class ShopReviewsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        Route::middleware(['web', 'auth', 'can:shopreviews.moderate'])
            ->prefix('admin/shop-reviews')
            ->name('admin.shopreviews.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        hook_listen('core.personal_data', fn (array $context) => ShopReviewPersonalData::register($context['registry']));
        hook_listen('core.mail.events', fn (array $events) => $events + Mail\ShopReviewLetters::events());
        hook_listen('core.forms', fn (array $forms) => $forms + [Submit::FORM => 'Shop reviews']);

        // An account going: the review stays, signed with the name it was
        // written under.
        hook_listen('core.user.deleting', function (array $context) {
            if ($user = $context['user'] ?? null) {
                ShopReview::where('user_id', $user->id)->update(['user_id' => null]);
            }
        });

        // The admin's bell: reviews nobody has read yet.
        hook_listen('core.admin.notifications', function (array $rows, array $context) {
            if (! ($context['user'] ?? null)?->can('shopreviews.moderate')) {
                return $rows;
            }

            return [...$rows, [
                'key' => 'shopreviews',
                'label' => __('Shop reviews to moderate'),
                'count' => ShopReview::where('status', ShopReview::PENDING)->count(),
                'url' => route('admin.shopreviews.index'),
                'icon' => 'message-square-heart',
            ]];
        });

        // The page, for any menu — the top strip beside the language is where
        // the examples put it.
        hook_listen('menus.pages', fn (array $pages) => $pages + [
            'route:shopreviews.index' => ['label' => 'Shop reviews', 'route' => 'shopreviews.index', 'icon' => 'message-square-heart'],
        ]);

        hook_listen('catalog.sitemap.urls', function (array $urls) {
            if (ShopReview::query()->shown()->exists()) {
                $urls[] = ['loc' => route('shopreviews.index'), 'changefreq' => 'weekly', 'priority' => '0.5'];
            }

            return $urls;
        });

        $this->blocks();
    }

    /**
     * The shop's mark for a footer or a column, and the latest reviews for the
     * home page — torgash.blocks' types, when it is installed.
     */
    protected function blocks(): void
    {
        if (! class_exists(\Torgash\Blocks\BlockTypes::class)) {
            return;
        }

        \Torgash\Blocks\BlockTypes::register('shop_rating', [
            'description' => 'The shop\'s average from its reviews, the stars and how many, linking to the reviews page.',
            'label' => 'Shop rating',
            'icon' => 'star',
            'view' => 'torgash.shopreviews::blocks.rating',
            'schema' => [
                ['key' => 'heading', 'type' => 'text', 'translatable' => true, 'label' => 'Heading', 'help' => 'Optional.'],
            ],
        ]);

        \Torgash\Blocks\BlockTypes::register('shop_reviews', [
            'description' => 'The latest published reviews of the shop as cards, with the average and a link to all of them.',
            'fits' => ['wide'],
            'label' => 'Shop reviews',
            'icon' => 'message-square-heart',
            'view' => 'torgash.shopreviews::blocks.latest',
            'schema' => [
                ['key' => 'heading', 'type' => 'text', 'translatable' => true, 'label' => 'Heading', 'help' => 'Empty — "Shop reviews".'],
                ['key' => 'limit', 'type' => 'number', 'default' => 3, 'min' => 1, 'max' => 12, 'label' => 'How many reviews'],
                ['key' => 'min_rating', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 5, 'label' => 'Only reviews rated at least',
                    'help' => '0 — any. The page itself always shows every published review.'],
            ],
        ]);
    }
}
