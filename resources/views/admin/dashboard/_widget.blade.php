{{--
    One widget's frame: its title, its link, the eye that hides it, and its
    body. `@container` because a widget lays itself out by the width of the
    column it stands in — the same widget in a 2/3 column and a 1/3 one is
    two different shapes, and the screen's width answers neither.
    `$dashWidget` is named distinctively: an @include inherits every variable.
--}}
<section class="@container card bg-base-100 border border-base-300 min-w-0" data-widget="{{ $dashWidget['key'] }}">
    <div class="card-body gap-3 p-5">
        <div class="flex items-center gap-2 min-w-0">
            <x-icon :name="$dashWidget['icon']" class="opacity-60 shrink-0" />
            <h2 class="font-semibold truncate">{{ $dashWidget['title'] }}</h2>
            <div class="ml-auto flex items-center gap-1 shrink-0">
                @if (! empty($dashWidget['link']['url']))
                    <a href="{{ $dashWidget['link']['url'] }}" class="link link-hover text-xs opacity-70 whitespace-nowrap">{{ label_text($dashWidget['link']['label'] ?? 'Open') }} →</a>
                @endif
                <form method="POST" action="{{ route('admin.dashboard.layout') }}">
                    @csrf
                    <input type="hidden" name="hide" value="{{ $dashWidget['key'] }}">
                    <button class="btn btn-ghost btn-sm btn-square opacity-40 hover:opacity-100" title="{{ __('Hide this widget') }}" aria-label="{{ __('Hide this widget') }}">
                        <x-icon name="eye-off" size="sm" />
                    </button>
                </form>
            </div>
        </div>
        {!! $dashboard->render($dashWidget) !!}
    </div>
</section>
