<?php

namespace Torgash\Wishlist;

use Torgash\Wishlist\Jobs\NotifyBackInStock;

/**
 * The products one action brought back (`catalog.product.restocked`, fired
 * per product), handed to ONE queued job when the action is over — at the
 * end of the request, or after the queued job that restocked them (an
 * import). That is what turns "three of your products are back" into one
 * letter rather than three.
 */
class Restocks
{
    /** @var array<int, true> */
    protected array $ids = [];

    public function add(int $productId): void
    {
        $this->ids[$productId] = true;
    }

    public function flush(): void
    {
        if ($this->ids === []) {
            return;
        }

        $ids = array_keys($this->ids);
        $this->ids = [];
        NotifyBackInStock::dispatch($ids);
    }
}
