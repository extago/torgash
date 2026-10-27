<?php

namespace Torgash\Checkout\Support;

use Illuminate\Support\Collection;
use Torgash\Checkout\Models\OrderStatus;

/**
 * The order-status table, read once per request.
 *
 * A status is a code on the order and a name plus a colour in a seven-row
 * table, so any screen that lists orders needs the whole table exactly once —
 * the customer's own order list is the case that made this necessary: it
 * printed the raw code (`open`) because looking the name up per row was a query
 * per row.
 *
 * A registered singleton and not a `static` on the model, so the memo dies with
 * the request even under a long-lived worker — same reasoning as
 * App\Core\Support\Currencies.
 */
class Statuses
{
    /** @var Collection<string, OrderStatus>|null */
    protected ?Collection $byCode = null;

    /** @return Collection<string, OrderStatus> code => status, in admin order */
    public function all(): Collection
    {
        return $this->byCode ??= OrderStatus::orderBy('position')->get()->keyBy('code');
    }

    public function find(?string $code): ?OrderStatus
    {
        return $code === null ? null : $this->all()->get($code);
    }

    /** The status's name in the current locale, falling back to its own code. */
    public function label(?string $code): string
    {
        return $this->find($code)?->t('name') ?: (string) $code;
    }

    public function color(?string $code): string
    {
        return $this->find($code)?->color ?: '#9ca3af';
    }

    /** For the admin, which edits statuses and then reads them back. */
    public function flush(): void
    {
        $this->byCode = null;
    }
}
