{{-- "Are you 18 or older?" over the page (setting `window`; torgash.age,
     PLAN §71). A courtesy, not a check: anybody can press the button, and the
     page under it is still there for search engines. The answer is a POST;
     "No" goes to the home page, or — when the whole shop asks — says sorry. --}}
<div class="fixed inset-0 z-100 grid place-items-center overflow-y-auto bg-base-300/80 backdrop-blur-md p-4"
     role="dialog" aria-modal="true" aria-labelledby="age-window-title" x-data="{ refused: false }">
    <div class="card bg-base-100 shadow-xl w-full max-w-md">
        <div class="card-body items-center text-center gap-4">
            <span class="text-5xl font-bold" aria-hidden="true">{{ $ageAsk }}+</span>
            <h2 id="age-window-title" class="text-xl font-semibold">{{ __('Are you :age or older?', ['age' => $ageAsk]) }}</h2>
            <p class="opacity-70" x-show="! refused">
                {{ $ageEverywhere
                    ? __('The shop sells goods meant only for people of :age or older.', ['age' => $ageAsk])
                    : __('This page has goods meant only for people of :age or older.', ['age' => $ageAsk]) }}
            </p>
            <p x-cloak x-show="refused">{{ __('Sorry: these goods are only for people of :age or older.', ['age' => $ageAsk]) }}</p>
            <div class="flex flex-wrap justify-center gap-2" x-show="! refused">
                <form method="POST" action="{{ route('age.confirm') }}">
                    @csrf
                    <input type="hidden" name="age" value="{{ $ageAsk }}">
                    <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
                    <button class="btn btn-primary" autofocus>{{ __('Yes, I am :age or older', ['age' => $ageAsk]) }}</button>
                </form>
                @if ($ageEverywhere)
                    <button type="button" class="btn" x-on:click="refused = true">{{ __('No') }}</button>
                @else
                    <a href="{{ url('/') }}" class="btn">{{ __('No, to the home page') }}</a>
                @endif
            </div>
        </div>
    </div>
</div>
