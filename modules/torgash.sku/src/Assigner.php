<?php

namespace Torgash\Sku;

use Torgash\Catalog\Models\Product;
use WeakMap;

/**
 * When a product gets a SKU by the template, and the second look once its
 * categories are known.
 *
 * The catalogue asks `catalog.product.sku` the moment a product is created —
 * its id is known by then, its categories are NOT: every door (the form, an
 * import) attaches them after the row exists. So the SKU is made by
 * the template that applies at that moment (usually the shop's), and the
 * product is remembered with it; when the admin form says it has finished
 * (`catalog.product.saved`, categories synced) and the product's category has
 * a template of its own, the SKU is made again by that one. An import has no
 * such moment: its products keep the shop's template, and "Renumber" on the
 * module's screen puts a category in order afterwards.
 *
 * The memory is a WeakMap on the model object — it lives exactly as long as
 * the product being saved, never into the next request of a worker.
 */
class Assigner
{
    /** @var WeakMap<Product, string> the product => the template its SKU was just made by */
    protected WeakMap $madeBy;

    public function __construct(protected Generator $generator, protected Templates $templates)
    {
        $this->madeBy = new WeakMap;
    }

    /** `catalog.product.sku`: the SKU a NEW product is created with. */
    public function onCreate(string $sku, Product $product, bool $typed): string
    {
        $mode = (string) setting('torgash.sku', 'on_create', 'blank');

        if ($mode === 'off' || ($typed && $mode !== 'always')) {
            return $sku;
        }

        ['template' => $template, 'category' => $category] = $this->templates->for($product);
        $made = $this->generator->make($template, $product, $category);

        if ($made === null) {
            return $sku;
        }

        $this->madeBy[$product] = $template;

        return $made;
    }

    /** `catalog.product.saved`: the form is done, its categories are in — does a category template apply? */
    public function afterSave(Product $product): void
    {
        if (! isset($this->madeBy[$product])) {
            return;
        }

        $was = $this->madeBy[$product];
        unset($this->madeBy[$product]);

        ['template' => $template, 'category' => $category] = $this->templates->for($product);

        if ($template !== $was && ($made = $this->generator->make($template, $product, $category)) !== null) {
            // Through the model: the audit and the search see the change.
            $product->sku = $made;
            $product->save();
        }
    }

    /**
     * Renumbering an existing product (the module's screen): its SKU by the
     * template that applies to it now. False when the template made nothing.
     */
    public function renumber(Product $product): bool
    {
        ['template' => $template, 'category' => $category] = $this->templates->for($product);
        $made = $this->generator->make($template, $product, $category);

        if ($made === null || $made === $product->sku) {
            return false;
        }

        $product->sku = $made;
        $product->save();

        return true;
    }
}
