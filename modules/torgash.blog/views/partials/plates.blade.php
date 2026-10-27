{{-- A post's rubrics as coloured plates (PLAN §47), each a link to its feed. --}}
@foreach ($plateRubrics as $plateRubric)
    <a href="{{ $plateRubric->url() }}"
       class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-semibold hover:opacity-80"
       style="color: {{ $plateRubric->colour() }}; background-color: {{ $plateRubric->colour() }}1a">
        @if ($plateRubric->icon)
            <x-icon :name="$plateRubric->icon" size="sm" />
        @endif
        {{ $plateRubric->t('name') }}
    </a>
@endforeach
