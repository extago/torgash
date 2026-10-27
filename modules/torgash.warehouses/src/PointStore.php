<?php

namespace Torgash\Warehouses;

use Torgash\Warehouses\Models\Warehouse;

/**
 * Which of the shop's points this visitor is shopping at, remembered between
 * pages.
 *
 * The session, not the URL: the choice outlives a search and a category walk,
 * and putting it in the address would give every page two canonical URLs.
 *
 * Resolved through a per-request memo (this is a registered singleton) because
 * a catalogue page asks the same question once per card plus once for the
 * header — never a `static`, which under a persistent worker would serve one
 * visitor's shop to the next (CLAUDE.md).
 *
 * A visitor who has chosen nothing is the ordinary case and the default one:
 * `current()` is null, the catalogue behaves exactly as it did before this
 * module, and nothing about availability is claimed.
 */
class PointStore
{
    protected string $sessionKey = 'warehouses_point';

    protected string $onlyKey = 'warehouses_point_only';

    protected bool $loaded = false;

    protected ?Warehouse $point = null;

    public function id(): ?int
    {
        $id = session($this->sessionKey);

        return $id ? (int) $id : null;
    }

    /**
     * The chosen point, or null — including when it has since been closed or
     * unpublished. A stale id in a session must not resurrect a point the shop
     * has taken down, so it is re-read through the same scope the picker uses.
     */
    public function current(): ?Warehouse
    {
        if ($this->loaded) {
            return $this->point;
        }

        $this->loaded = true;
        $id = $this->id();

        return $this->point = $id ? Warehouse::pickup()->find($id) : null;
    }

    public function choose(int $id): ?Warehouse
    {
        $point = Warehouse::pickup()->find($id);

        if (! $point) {
            return null;
        }

        session([$this->sessionKey => $point->id]);
        $this->loaded = true;

        return $this->point = $point;
    }

    public function forget(): void
    {
        session()->forget([$this->sessionKey, $this->onlyKey]);
        $this->loaded = true;
        $this->point = null;
    }

    /**
     * The customer's own switch "stock at the chosen shop" (PLAN §38) — only
     * meaningful where the shop lets them decide (`point_availability =
     * choice`).
     */
    public function onlyHere(): bool
    {
        return (bool) session($this->onlyKey, false);
    }

    public function setOnlyHere(bool $only): void
    {
        session([$this->onlyKey => $only]);
    }

    /**
     * Is the catalogue this point's assortment right now? The shop decides
     * (`hide` always, `mark` never) or leaves it to the customer (`choice`).
     */
    public function filtering(): bool
    {
        return match (setting('torgash.warehouses', 'point_availability', 'mark')) {
            'hide' => true,
            'choice' => $this->onlyHere(),
            default => false,
        };
    }
}
