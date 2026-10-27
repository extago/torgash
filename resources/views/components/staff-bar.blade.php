{{-- A strip at the top of the storefront for staff, saying what customers
     see differently from them: a storefront closed for visitors, safe mode.
     Printed by core through `core.body_start` (CoreServiceProvider), only
     when one of the two is true, so an ordinary page asks nothing. --}}
<div class="bg-warning text-warning-content text-sm" role="status" data-staff-bar>
    <div class="max-w-screen-2xl mx-auto px-4 py-1.5 flex flex-wrap items-center gap-x-4 gap-y-1">
        @if ($closed)
            <span class="inline-flex items-center gap-2"><x-icon name="lock" size="sm" /> {{ __('The storefront is closed: visitors see a notice, you see the shop because you are staff.') }}</span>
        @endif
        @if ($safe)
            <span class="inline-flex items-center gap-2"><x-icon name="shield" size="sm" />
                {{ \App\Core\Support\SafeMode::forced()
                    ? __('Safe mode for everyone (TORGASH_SAFE_MODE in .env): the engine\'s own theme, no shop CSS, no code snippets.')
                    : __('Safe mode, for you only: the engine\'s own theme, no shop CSS, no code snippets.') }}
            </span>
            @unless (\App\Core\Support\SafeMode::forced())
                <form method="POST" action="{{ route('admin.safe_mode') }}" class="inline">
                    @csrf
                    <input type="hidden" name="on" value="0">
                    <input type="hidden" name="back" value="{{ request()->fullUrl() }}">
                    <button class="link font-semibold">{{ __('Turn off') }}</button>
                </form>
            @endunless
        @endif
    </div>
</div>
