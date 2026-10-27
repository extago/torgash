<?php

namespace Torgash\CommerceMl\Import;

use Torgash\CommerceMl\Models\Link;

/**
 * "1C sent this. What is it here?"
 *
 * The one place that answers it, and the one place that records the question
 * when there is no answer yet. Both halves matter: an importer that silently
 * ignores an unknown price type leaves the shop with no way to find out it is
 * losing prices, and an importer that guesses leaves it with a catalogue nobody
 * ordered.
 *
 * Three kinds of thing come through here, and they are the ones the shop must
 * not have created behind its back — price types, characteristics, their
 * values. Products, categories, warehouses and customers do NOT: they carry
 * `external_id` of their own (§58), the shop's own convention for exactly this,
 * and putting them in a link table too would be a second answer to a settled
 * question.
 */
class Matching
{
    /** @var array<string, ?Link> Per-slice memo: a catalogue asks about the same property ten thousand times. */
    protected array $memo = [];

    /**
     * Forget what was read, at the start of every slice.
     *
     * The memo is worth having — ten thousand products asking about the same
     * property is ten thousand queries without it — but it must not outlive one
     * batch. An import is spread over many requests by design, and a shop that
     * answers the matching screen halfway through should have its answer take
     * effect at the next batch rather than at the next import.
     */
    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * Record that 1C has this, and give back the row that says what to do.
     *
     * Called on the way past, for everything, whether or not it will be used —
     * the queue is only useful if it holds what actually arrives.
     */
    public function see(string $kind, string $guid, ?string $name = null, ?string $parentGuid = null): Link
    {
        $key = $kind.'|'.$guid;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $link = Link::firstOrNew(['kind' => $kind, 'guid' => $guid]);

        // The name is refreshed on every sighting and is never a key: 1C
        // renames things, and two price types called "Оферта" and "оферта" is
        // something a real shop has. It exists so a person can recognise the
        // row, and for nothing else.
        $link->forceFill([
            'name' => $name ?? $link->name,
            'parent_guid' => $parentGuid ?? $link->parent_guid,
            'seen_at' => now(),
        ])->save();

        return $this->memo[$key] = $link;
    }

    /**
     * The local row this points at, or null.
     *
     * Null means one of three different things — nobody has decided, the shop
     * said "skip", or it said "create" and the thing does not exist yet — and
     * the caller that needs to tell them apart asks `decision()`.
     */
    public function localId(string $kind, string $guid): ?int
    {
        $link = $this->find($kind, $guid);

        return $link && $link->decision === Link::LINK ? $link->local_id : null;
    }

    public function localKey(string $kind, string $guid): ?string
    {
        $link = $this->find($kind, $guid);

        return $link && $link->decision === Link::LINK ? $link->local_key : null;
    }

    public function decision(string $kind, string $guid): string
    {
        return $this->find($kind, $guid)?->decision ?? Link::ASK;
    }

    /** Say what a row now points at — how the matching screen writes. */
    public function point(Link $link, ?int $localId, ?string $localKey = null): void
    {
        $link->forceFill([
            'decision' => Link::LINK,
            'local_id' => $localId,
            'local_key' => $localKey,
        ])->save();

        unset($this->memo[$link->kind.'|'.$link->guid]);
    }

    /**
     * Everything still waiting on a person, per kind.
     *
     * This is what the screen counts and what the exchange log warns about:
     * "1 object was not matched and will be skipped" is a sentence a shop
     * should read before it wonders where its wholesale prices went.
     *
     * @return array<string, int>
     */
    public function pending(): array
    {
        return Link::where('decision', Link::ASK)
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind')
            ->all();
    }

    protected function find(string $kind, string $guid): ?Link
    {
        return $this->memo[$kind.'|'.$guid]
            ??= Link::where(['kind' => $kind, 'guid' => $guid])->first();
    }
}
