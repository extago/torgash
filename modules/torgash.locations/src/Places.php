<?php

namespace Torgash\Locations;

use Illuminate\Support\Collection;
use Torgash\Locations\Models\Place;

/**
 * The places one request asks about, read once.
 *
 * Everything that stores a place stores only its id — a zone rule, a warehouse,
 * a city page — and a page mentions the same town several times over: the
 * title, the H1, the description and the crumb are four expansions of one
 * template. A registered per-request singleton for the same reason as
 * Support\Currencies and Catalog\Support\Labels, and never a `static`: that
 * would outlive the request under a persistent worker and answer the next
 * visitor with last request's rows.
 *
 * `prime()` is the other half, and the one that keeps a list of city pages to
 * one query — the idiom `Measurements::prime()` and `FilterPage::primeValues()`
 * already use.
 */
class Places
{
    /** @var array<int, Place|null> */
    protected array $memo = [];

    public function find(int $id): ?Place
    {
        if (array_key_exists($id, $this->memo)) {
            return $this->memo[$id];
        }

        $this->prime([$id]);

        return $this->memo[$id] ?? null;
    }

    /**
     * Every one of these ids in one query, misses remembered as misses so a
     * deleted place is not looked up again on the same page.
     *
     * @param  iterable<int|string>  $ids
     * @return Collection<int, Place>
     */
    public function prime(iterable $ids): Collection
    {
        $wanted = [];

        foreach ($ids as $id) {
            if (($id = (int) $id) > 0 && ! array_key_exists($id, $this->memo)) {
                $wanted[$id] = $id;
            }
        }

        if ($wanted === []) {
            return collect($this->memo)->filter();
        }

        $found = Place::with('state')->whereIn('id', $wanted)->get()->keyBy('id');

        foreach ($wanted as $id) {
            $this->memo[$id] = $found->get($id);
        }

        return collect($this->memo)->filter();
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
