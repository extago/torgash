{{-- "Was this review helpful?" — the pair of buttons and the count, together
     in the element that gets replaced, so which button is lit and the number
     beside it always come from one render.

     `$mine` is what THIS reader has already said: true, false, or null. A
     reader may press the lit button again to take the vote back, because the
     button is the state rather than a counter — and one that cannot be undone
     tells somebody their answer is final in markup that does not look it. --}}
@php
    $mine = $mine ?? null;
    $yes = (int) $review->helpful_yes;
    $no = (int) $review->helpful_no;
    $own = auth()->id() && (int) auth()->id() === (int) $review->user_id;
@endphp
<div id="helpful-{{ $review->id }}" class="flex flex-wrap items-center gap-2 text-sm">
    {{-- Never on your own review: the number exists to tell other readers
         which reviews helped them, and the author is the one person it cannot
         be evidence from. --}}
    @unless ($own)
        <span class="opacity-60">{{ __('Helpful?') }}</span>
        {{-- A list of pairs, not [true => …, false => …]: PHP turns boolean
             keys into 1 and 0, and `$mine === $value` then never held, so
             the button a reader had pressed was never lit. --}}
        @foreach ([[true, 'thumbs-up'], [false, 'thumbs-down']] as [$value, $icon])
            <button type="button" aria-pressed="{{ $mine === $value ? 'true' : 'false' }}"
                    class="btn btn-sm rounded-full gap-1.5 tabular-nums {{ $mine === $value ? 'btn-primary btn-soft' : 'btn-ghost border-base-300' }}"
                    @guest disabled title="{{ __('Sign in to answer') }}" @endguest
                    hx-post="{{ route('reviews.helpful', $review) }}"
                    hx-vals='{"helpful": {{ $value ? 'true' : 'false' }}}'
                    hx-target="#helpful-{{ $review->id }}" hx-swap="outerHTML">
                <x-icon name="{{ $icon }}" size="sm" />
                {{ $value ? $yes : $no }}
            </button>
        @endforeach
    @endunless

    @if ($yes + $no > 0 && $own)
        <span class="opacity-50">
            {{ trans_choice('{1}:yes of :total found this helpful|[2,*]:yes of :total found this helpful',
                $yes + $no, ['yes' => $yes, 'total' => $yes + $no]) }}
        </span>
    @endif
</div>
