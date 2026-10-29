{{-- The module's three screens — the reviews, what they are rated by, and
     the shop's marks on other sites — as the quiet underlined tabs under the
     heading, the way every module's screens are reached. --}}
<div role="tablist" class="tabs tabs-border">
    @foreach ([
        'admin.shopreviews.index' => ['message-square-heart', __('Reviews')],
        'admin.shopreviews.criteria.index' => ['list-checks', __('Rating criteria')],
        'admin.shopreviews.platforms.index' => ['globe', __('Other sites')],
    ] as $srRoute => [$srIcon, $srText])
        <a role="tab" href="{{ route($srRoute) }}" @class(['tab gap-1.5', 'tab-active' => request()->routeIs($srRoute)])>
            <x-icon :name="$srIcon" size="sm" /> {{ $srText }}
        </a>
    @endforeach
</div>
