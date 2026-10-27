{{-- The heading of one filter block — the <summary> of its <details>, so a
     click opens and closes it with no script. How many of its values are
     ticked rides beside the name, which is what a CLOSED block has to say
     about itself. Variables are named filter*: an included view inherits the
     caller's whole scope. --}}
<summary class="flex items-center gap-2 px-4 py-3.5 cursor-pointer select-none list-none [&::-webkit-details-marker]:hidden text-sm font-medium hover:text-primary">
    <span class="grow">{{ $filterHeading }}</span>
    @if (($filterCount ?? 0) > 0)
        <span class="badge badge-primary badge-sm">{{ $filterCount }}</span>
    @endif
    <x-icon name="chevron-down" size="sm" class="shrink-0 opacity-50 transition-transform group-open:rotate-180" />
</summary>
