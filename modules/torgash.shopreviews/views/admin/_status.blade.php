{{-- A shop review's state, moved in place through Moderate (the numbers follow). --}}
@php
    $srStates = [
        \Torgash\ShopReviews\Models\ShopReview::PENDING => ['badge-warning', __('Waiting')],
        \Torgash\ShopReviews\Models\ShopReview::PUBLISHED => ['badge-success', __('Published')],
        \Torgash\ShopReviews\Models\ShopReview::REJECTED => ['badge-ghost', __('Rejected')],
    ];
    [$srBadge, $srLabel] = $srStates[$review->status] ?? ['badge-ghost', $review->status];
@endphp
<div id="shopreview-status-{{ $review->id }}" class="dropdown dropdown-end">
    <div tabindex="0" role="button" class="badge badge-sm gap-1 cursor-pointer {{ $srBadge }}" title="{{ __('Change status') }}">
        {{ $srLabel }} <x-icon name="chevron-down" size="sm" />
    </div>
    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-44 mt-1 p-1 z-50 border border-base-300">
        @foreach ([\Torgash\ShopReviews\Models\ShopReview::PUBLISHED => __('Publish'), \Torgash\ShopReviews\Models\ShopReview::REJECTED => __('Reject'), \Torgash\ShopReviews\Models\ShopReview::PENDING => __('Back to waiting')] as $value => $text)
            <li>
                <button type="button"
                        hx-patch="{{ route('admin.shopreviews.status', $review) }}"
                        hx-vals='@json(['status' => $value])'
                        hx-target="#shopreview-status-{{ $review->id }}" hx-swap="outerHTML"
                        @class(['font-semibold text-primary' => $review->status === $value])>
                    {{ $text }}
                </button>
            </li>
        @endforeach
    </ul>
</div>
