{{-- The module's three screens, as links: the reviews, what they are rated
     by, and the shop's marks on other sites. --}}
<div class="join">
    @foreach ([
        'admin.shopreviews.index' => ['message-square-heart', __('Reviews')],
        'admin.shopreviews.criteria.index' => ['list-checks', __('Rating criteria')],
        'admin.shopreviews.platforms.index' => ['globe', __('Other sites')],
    ] as $srRoute => [$srIcon, $srText])
        <a href="{{ route($srRoute) }}" @class(['btn btn-sm join-item gap-1.5', 'btn-active' => request()->routeIs($srRoute)])>
            <x-icon :name="$srIcon" size="sm" /> {{ $srText }}
        </a>
    @endforeach
</div>
