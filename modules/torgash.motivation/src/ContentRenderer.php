<?php

namespace Torgash\Motivation;

use Torgash\Catalog\Models\Product;
use Torgash\Motivation\Models\MotivationItem;

/**
 * Turns one item's content_type into HTML, or null when there is genuinely
 * nothing to show — the usual rule of hiding a motivation card
 * whose captured content strips down to nothing (e.g. a product with no
 * categories, or a store with every payment method disabled).
 */
class ContentRenderer
{
    public function render(MotivationItem $item, Product $product): ?string
    {
        $html = match ($item->content_type) {
            'categories' => $this->categories($product),
            'payment_methods' => $this->paymentMethods(),
            'shipping_methods' => $this->shippingMethods(),
            // The staff's own text — cleaned (App\Core\Content\SafeHtml); the
            // three lists above are the engine's markup.
            default => app(\App\Core\Content\SafeHtml::class)->clean((string) $item->t('content')),
        };

        return trim(strip_tags($html)) !== '' ? $html : null;
    }

    protected function categories(Product $product): string
    {
        if ($product->categories->isEmpty()) {
            return '';
        }

        return view('torgash.motivation::partials.categories-list', [
            'categories' => $product->categories,
        ])->render();
    }

    /**
     * class_exists is the whole check: the checkout module's autoloader is
     * only registered while it is enabled (see ModuleManager::registerEnabled),
     * so this is false — not a fatal error — the moment checkout is off, even
     * for an item that was configured while it was on.
     */
    protected function paymentMethods(): string
    {
        if (! class_exists(\Torgash\Checkout\Models\PaymentMethod::class)) {
            return '';
        }

        $methods = \Torgash\Checkout\Models\PaymentMethod::where('enabled', true)->orderBy('position')->get();

        return $methods->isEmpty() ? '' : view('torgash.motivation::partials.methods-list', [
            'methods' => $methods->map(fn ($m) => ['name' => $m->t('name'), 'note' => $m->t('instructions'), 'logo' => $m->logoUrl()]),
            'methodIcon' => 'credit-card',
        ])->render();
    }

    protected function shippingMethods(): string
    {
        if (! class_exists(\Torgash\Shipping\Models\ShippingMethod::class)) {
            return '';
        }

        $methods = \Torgash\Shipping\Models\ShippingMethod::where('enabled', true)->orderBy('position')->get();

        return $methods->isEmpty() ? '' : view('torgash.motivation::partials.methods-list', [
            'methods' => $methods->map(fn ($m) => ['name' => $m->t('name'), 'note' => null, 'logo' => $m->logoUrl()]),
            'methodIcon' => 'truck',
        ])->render();
    }
}
