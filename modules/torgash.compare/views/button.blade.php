{{-- The comparison toggle — the wishlist heart's twin: one form answered with
     itself in its new state (toggled.blade.php); without JavaScript it posts
     and comes back to the page. compareLabelled draws it with words, for under
     the product page's buy button. Variables are named compare*: an @include
     hands a partial every variable its caller has. --}}
@php $compareIn = app(\Torgash\Compare\Comparison::class)->has($compareProduct); @endphp
<form method="POST" action="{{ route('compare.toggle', $compareProduct->slug) }}"
      hx-post="{{ route('compare.toggle', $compareProduct->slug) }}" hx-target="this" hx-swap="outerHTML"
      class="shrink-0" data-compare-toggle @if ($compareJust ?? false) data-just-toggled @endif>
    @csrf
    @if ($compareLabelled ?? false)
        <input type="hidden" name="labelled" value="1">
        <button class="btn btn-ghost btn-sm btn-touch gap-1.5 px-2 font-normal" aria-pressed="{{ $compareIn ? 'true' : 'false' }}"
                aria-label="{{ $compareIn ? __('Remove from comparison') : __('Add to comparison') }}">
            <span @class(['torgash-icon-filled text-primary' => $compareIn])><x-icon name="square-kanban" size="sm" /></span>
            {{-- One short word: "Add to comparison" took two lines and made its cell the row's tallest. --}}
            {{ $compareIn ? __('In comparison') : __('Compare') }}
        </button>
    @else
        <button class="btn btn-ghost btn-sm btn-touch btn-square" aria-pressed="{{ $compareIn ? 'true' : 'false' }}"
                title="{{ $compareIn ? __('Remove from comparison') : __('Add to comparison') }}"
                aria-label="{{ $compareIn ? __('Remove from comparison') : __('Add to comparison') }}: {{ $compareProduct->t('name') }}">
            <span @class(['torgash-icon-filled text-primary' => $compareIn])><x-icon name="square-kanban" size="lg" /></span>
        </button>
    @endif
</form>
