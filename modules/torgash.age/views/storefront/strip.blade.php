{{-- Above the page (core.body_start) when the page covered or left out goods
     for an age the visitor has not confirmed (torgash.age, PLAN §71). Drawn
     after the page's content, so it knows what that content did. The button
     is a POST: a link prefetcher follows a GET on its own. --}}
<div class="bg-warning/15 border-b border-warning/40">
    <div class="container mx-auto max-w-(--torgash-container) px-(--torgash-gutter) py-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-sm">
        <p class="flex items-center gap-2">
            <x-icon name="shield-alert" class="shrink-0" />
            <span>{{ __('Some goods here are sold only to people of :age or older, so they are not shown in full.', ['age' => $ageAsk]) }}</span>
        </p>
        <form method="POST" action="{{ route('age.confirm') }}">
            @csrf
            <input type="hidden" name="age" value="{{ $ageAsk }}">
            <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
            <button class="btn btn-sm btn-warning">{{ __('I am :age or older', ['age' => $ageAsk]) }}</button>
        </form>
    </div>
</div>
