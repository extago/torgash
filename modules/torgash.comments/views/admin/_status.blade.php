{{-- A comment's state, moved in place — the reviews list's control. --}}
@php
    $commentStates = [
        \Torgash\Comments\Models\Comment::PENDING => ['badge-warning', __('Waiting')],
        \Torgash\Comments\Models\Comment::APPROVED => ['badge-success', __('Published')],
        \Torgash\Comments\Models\Comment::REJECTED => ['badge-ghost', __('Rejected')],
    ];
    [$commentBadge, $commentLabel] = $commentStates[$comment->status] ?? ['badge-ghost', $comment->status];
@endphp
<div id="comment-status-{{ $comment->id }}" class="dropdown dropdown-end">
    <div tabindex="0" role="button" class="badge badge-sm gap-1 cursor-pointer {{ $commentBadge }}" title="{{ __('Change status') }}">
        {{ $commentLabel }} <x-icon name="chevron-down" size="sm" />
    </div>
    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-44 mt-1 p-1 z-50 border border-base-300">
        @foreach ([\Torgash\Comments\Models\Comment::APPROVED => __('Publish'), \Torgash\Comments\Models\Comment::REJECTED => __('Reject'), \Torgash\Comments\Models\Comment::PENDING => __('Back to waiting')] as $value => $text)
            <li>
                <button type="button"
                        hx-patch="{{ route('admin.comments.status', $comment) }}"
                        hx-vals='@json(['status' => $value])'
                        hx-target="#comment-status-{{ $comment->id }}" hx-swap="outerHTML"
                        @class(['font-semibold text-primary' => $comment->status === $value])>
                    {{ $text }}
                </button>
            </li>
        @endforeach
    </ul>
</div>
