{{-- A review's state, moved in place through Moderate (the average follows). --}}
@php
    $reviewStates = [
        \Torgash\Reviews\Models\Review::PENDING => ['badge-warning', __('Waiting')],
        \Torgash\Reviews\Models\Review::PUBLISHED => ['badge-success', __('Published')],
        \Torgash\Reviews\Models\Review::REJECTED => ['badge-ghost', __('Rejected')],
    ];
    [$reviewBadge, $reviewLabel] = $reviewStates[$review->status] ?? ['badge-ghost', $review->status];
@endphp
<div id="review-status-{{ $review->id }}" class="dropdown dropdown-end">
    <div tabindex="0" role="button" class="badge badge-sm gap-1 cursor-pointer {{ $reviewBadge }}" title="{{ __('Change status') }}">
        {{ $reviewLabel }} <x-icon name="chevron-down" size="sm" />
    </div>
    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-44 mt-1 p-1 z-50 border border-base-300">
        @foreach ([\Torgash\Reviews\Models\Review::PUBLISHED => __('Publish'), \Torgash\Reviews\Models\Review::REJECTED => __('Reject'), \Torgash\Reviews\Models\Review::PENDING => __('Back to waiting')] as $value => $text)
            <li>
                <button type="button"
                        hx-patch="{{ route('admin.reviews.status', $review) }}"
                        hx-vals='@json(['status' => $value])'
                        hx-target="#review-status-{{ $review->id }}" hx-swap="outerHTML"
                        @class(['font-semibold text-primary' => $review->status === $value])>
                    {{ $text }}
                </button>
            </li>
        @endforeach
    </ul>
</div>
