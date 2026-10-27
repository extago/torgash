{{-- What is filtered right now, and the way to drop each one.

     LINKS, never inputs. This row used to be a second copy of the whole filter
     panel — the same input names inside the same form — so every value was sent
     twice and unticking a box in one copy changed nothing, because the other
     one was still ticked. A chip cannot be half of a form's state. --}}
@if (! empty($activeFilters))
    <div class="bg-base-100 border border-base-300 rounded-box p-3 flex flex-wrap gap-2 items-center">
        <span class="text-sm text-base-content/50 mr-1">{{ __('Filtered by') }}</span>

        @foreach ($activeFilters as $chip)
            <a href="{{ $chip['url'] }}" class="btn btn-sm btn-outline gap-1.5 normal-case"
               title="{{ __('Remove this filter') }}">
                {{ $chip['label'] }}
                <x-icon name="x" size="sm" class="opacity-50" />
            </a>
        @endforeach

        @if (count($activeFilters) > 1)
            <a href="{{ $currentUrl }}" class="btn btn-sm btn-ghost">{{ __('Reset') }}</a>
        @endif
    </div>
@endif
