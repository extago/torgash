{{--
    The comments block.

    What it is commenting ON comes from the ZONE CONTEXT: every registered
    target declares which context key carries it ('page' → $zoneContext['page']),
    so the same block works under a page today and under a product the day
    catalog registers itself. A zone that carries nothing commentable renders
    nothing at all rather than an empty form addressed to no one.
--}}
@php
    use Torgash\Comments\CommentTargets;
    use Torgash\Comments\Models\Comment;

    $target = null;
    $targetType = null;

    foreach (CommentTargets::all() as $type => $definition) {
        $key = $definition['context'] ?? $type;

        if (! empty($zoneContext[$key])) {
            $target = $zoneContext[$key];
            $targetType = $type;
            break;
        }
    }

    $comments = $target
        ? Comment::approved()->for($targetType, $target->id)->whereNull('parent_id')
            ->with(['replies' => fn ($q) => $q->where('status', Comment::APPROVED)])
            ->orderBy('id')->get()
        : collect();

    $closed = ! empty($settings['closed']);
    $heading = trim((string) ($settings['heading'] ?? '')) ?: __('Comments');
@endphp

@if ($target)
    <div class="bg-base-100 border border-base-300 rounded-box p-5 flex flex-col gap-4">
        <div class="flex items-baseline gap-2">
            <h2 class="text-lg font-semibold">{{ $heading }}</h2>
            @if ($comments->isNotEmpty())
                <span class="badge badge-ghost badge-sm">{{ $comments->count() }}</span>
            @endif
        </div>

        @if (session('status'))
            <div class="alert alert-success py-2 text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->first('comment'))
            <div class="alert alert-warning py-2 text-sm">{{ $errors->first('comment') }}</div>
        @endif

        @forelse ($comments as $comment)
            <div class="flex flex-col gap-2 border-t border-base-300 pt-3 first:border-t-0 first:pt-0">
                <div class="flex items-baseline gap-2 flex-wrap">
                    <span class="font-medium">{{ $comment->author_name }}</span>
                    <span class="text-xs opacity-50">{{ $comment->created_at?->diffForHumans() }}</span>
                </div>
                <div class="text-sm whitespace-pre-line">{{ $comment->body }}</div>

                @foreach ($comment->replies as $reply)
                    <div class="ms-6 ps-3 border-s-2 border-base-300 flex flex-col gap-1 mt-1">
                        <div class="flex items-baseline gap-2 flex-wrap">
                            <span class="font-medium">{{ $reply->author_name }}</span>
                            @if ($reply->is_staff)
                                <span class="badge badge-primary badge-sm">{{ __('from the shop') }}</span>
                            @endif
                            <span class="text-xs opacity-50">{{ $reply->created_at?->diffForHumans() }}</span>
                        </div>
                        <div class="text-sm whitespace-pre-line">{{ $reply->body }}</div>
                    </div>
                @endforeach
            </div>
        @empty
            @unless ($closed)
                <p class="text-sm opacity-60">{{ __('No comments yet — be the first.') }}</p>
            @endunless
        @endforelse

        @if ($closed)
            <p class="text-sm opacity-60">{{ __('Comments are closed.') }}</p>
        @elseif (setting('torgash.comments', 'registered_only') && ! auth()->check())
            <p class="text-sm opacity-60">{{ __('Please sign in to leave a comment.') }}</p>
        @else
            <form method="POST" action="{{ route('comments.store') }}" class="flex flex-col gap-2 border-t border-base-300 pt-4">
                @csrf
                <input type="hidden" name="target_type" value="{{ $targetType }}">
                <input type="hidden" name="target_id" value="{{ $target->id }}">

                {{-- The form's age, the honeypot, and whatever else guards this
                     form — the same component every public form here uses. --}}
                <x-form-guard form="comment" />

                @guest
                    <div class="flex flex-wrap gap-2">
                        <input name="author_name" value="{{ old('author_name') }}" required
                               placeholder="{{ __('Your name') }}"
                               class="input input-bordered input-sm grow min-w-40">
                        <input name="author_email" type="email" value="{{ old('author_email') }}"
                               placeholder="{{ __('Email (not published)') }}"
                               class="input input-bordered input-sm grow min-w-40">
                    </div>
                @endguest

                <textarea name="body" rows="3" required placeholder="{{ __('Your comment') }}"
                          class="textarea textarea-bordered w-full">{{ old('body') }}</textarea>

                @error('body') <div class="text-xs text-error">{{ $message }}</div> @enderror

                <div class="flex items-center gap-3">
                    <button class="btn btn-primary btn-sm">{{ __('Send') }}</button>
                    @if (setting('torgash.comments', 'premoderate', true))
                        <span class="text-xs opacity-50">{{ __('Comments appear after a check.') }}</span>
                    @endif
                </div>
            </form>
        @endif
    </div>
@endif
