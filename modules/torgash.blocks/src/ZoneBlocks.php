<?php

namespace Torgash\Blocks;

use Illuminate\Support\Collection;
use Torgash\Blocks\Models\Block;

/**
 * The enabled blocks of every zone, read once per request — the moment the
 * first zone is drawn — and never earlier.
 *
 * They used to be read when the module booted and handed to each zone's
 * listener. Under php-fpm that is the same moment; under a persistent worker
 * (FrankenPHP / Octane) it is the moment the WORKER started, so a block
 * switched off, edited or added — and a zone switched off — stayed as it was
 * until the workers were restarted. A per-request memo instead, forgotten
 * when every request (and queue job) begins (PerRequest::forget).
 */
class ZoneBlocks
{
    /** @var Collection<string, Collection<int, Block>>|null */
    protected ?Collection $byZone = null;

    /** @return Collection<int, Block> enabled, in zone order; empty for a zone switched off */
    public function in(string $zone): Collection
    {
        if (! app(ZoneSwitches::class)->enabled($zone)) {
            return collect();
        }

        $this->byZone ??= Block::enabled()->orderBy('sort')->orderBy('id')->get()->groupBy('zone');

        return $this->byZone->get($zone, collect());
    }

    public function flush(): void
    {
        $this->byZone = null;
    }
}
