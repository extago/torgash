{{-- A column's first values on the mapping screen — and, with a formula, what
     it makes of them. Redrawn by ImportController::formula() as the formula is
     typed, so the id stays on this element. --}}
<div id="map-values-{{ $columnIndex }}" class="min-w-0 text-xs space-y-0.5">
    @if ($formulaSample === null)
        @foreach ($sample as $sampleRow)
            @if (trim((string) ($sampleRow[$columnIndex] ?? '')) !== '')
                <div class="truncate opacity-70" title="{{ \Illuminate\Support\Str::limit((string) $sampleRow[$columnIndex], 500) }}">{{ \Illuminate\Support\Str::limit((string) $sampleRow[$columnIndex], 120) }}</div>
            @endif
        @endforeach
    @elseif ($formulaSample['error'])
        <div class="text-error flex items-start gap-1"><x-icon name="triangle-alert" size="sm" class="shrink-0 mt-px" /> {{ $formulaSample['error'] }}</div>
    @else
        @foreach ($formulaSample['rows'] as [$valuesCell, $valuesResult, $valuesProblem])
            <div class="truncate" title="{{ \Illuminate\Support\Str::limit($valuesCell, 500) }}">
                <span class="opacity-50">{{ $valuesCell !== '' ? \Illuminate\Support\Str::limit($valuesCell, 50) : __('(empty)') }}</span>
                <span class="opacity-50">→</span>
                @if ($valuesProblem !== null)
                    <span class="text-error">{{ $valuesProblem }}</span>
                @else
                    <span class="font-medium">{{ $valuesResult !== '' ? \Illuminate\Support\Str::limit($valuesResult, 80) : __('(empty)') }}</span>
                @endif
            </div>
        @endforeach
    @endif
</div>
