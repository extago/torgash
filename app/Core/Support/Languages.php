<?php

namespace App\Core\Support;

use App\Models\Language;
use App\Models\Storefront;
use Illuminate\Database\Eloquent\Collection;

/**
 * The language table, read once per request — what Currencies is for the
 * currencies, and for the same reason: every storefront page draws the
 * language picker (the layout's header, and the "language and currency" block
 * of the phone's menu), and each drew it with a query of its own for the same
 * handful of rows. Measured on a live shop: two identical queries on every
 * page that was not served from the kept ones.
 *
 * A registered singleton, never a `static`; emptied before every queue job
 * and at the start of a worker's request (`PerRequest::flushPerJob`), and at
 * once when a language is written.
 */
class Languages
{
    /** @var Collection<int, Language>|null in the order the shop sorted them */
    protected ?Collection $rows = null;

    /** @return Collection<int, Language> */
    public function all(): Collection
    {
        return $this->rows ??= Language::query()->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * The languages a storefront offers its visitors: its own list, without
     * the ones switched off, in the shop's order.
     *
     * @return Collection<int, Language>
     */
    public function offered(?Storefront $storefront): Collection
    {
        $codes = (array) ($storefront?->locales ?? []);

        return $this->all()
            ->filter(fn (Language $language) => $language->enabled && in_array($language->code, $codes, true))
            ->values();
    }

    public function flush(): void
    {
        $this->rows = null;
    }
}
