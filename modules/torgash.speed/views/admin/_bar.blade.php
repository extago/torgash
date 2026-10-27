{{-- One request's time as five stacked parts. $barParts: part => ms (per
     request); $barScale: the ms that is the full width. The tooltip carries
     every number, so no part is known by its color alone. --}}
@php
    $barTotal = array_sum($barParts);
    $barWidth = $barScale > 0 ? min(100, $barTotal / $barScale * 100) : 0;
    $barTitle = collect(\Torgash\Speed\Format::PARTS)
        ->map(fn ($label, $part) => __($label).': '.\Torgash\Speed\Format::ms($barParts[$part] ?? 0))
        ->prepend(__('In total').': '.\Torgash\Speed\Format::ms($barTotal))
        ->implode("\n");
@endphp
<div class="h-3 w-full rounded" style="background: var(--speed-track)" title="{{ $barTitle }}">
    <div class="h-full flex" style="width: {{ max($barWidth, $barTotal > 0 ? 1 : 0) }}%">
        @foreach (\Torgash\Speed\Format::PARTS as $part => $label)
            @if (($barParts[$part] ?? 0) > 0 && $barTotal > 0)
                <div class="speed-seg" data-part="{{ $part }}" style="flex: {{ $barParts[$part] / $barTotal }} 1 0"></div>
            @endif
        @endforeach
    </div>
</div>
