{{-- The heart (PLAN §47): one per browser, a second press takes it back,
     answered with itself. A plain form — with no script it posts and comes
     back to the article. --}}
<form method="POST" action="{{ route('blog.like', $post->slug) }}" id="blog-like"
      hx-post="{{ route('blog.like', $post->slug) }}" hx-target="#blog-like" hx-swap="outerHTML">
    @csrf
    <button @class(['btn btn-sm gap-1.5', 'btn-error text-white' => $liked, 'btn-outline' => ! $liked])
            aria-pressed="{{ $liked ? 'true' : 'false' }}" title="{{ $liked ? __('You like it — press again to take it back') : __('Like') }}">
        <x-icon name="heart" size="sm" class="{{ $liked ? 'fill-current' : '' }}" />
        <span class="tabular-nums">{{ $post->likes_count }}</span>
    </button>
</form>
