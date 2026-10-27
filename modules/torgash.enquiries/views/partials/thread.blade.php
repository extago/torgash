{{--
    A conversation, as the person who started it sees it — anywhere.

    The same markup the account uses, extracted because a thread is also shown
    to somebody who has NO account: a guest reading their order by its signed
    link. Which is why the reply form's action is passed in rather than built
    here — the caller knows what authorises this reader, and for a guest that is
    a signature rather than a session.

    $enquiry, $replyAction (a URL, or null for read-only).
--}}
@php
    $threadClosed = $enquiry->isLocked();
@endphp

<div class="flex flex-col gap-3">
    <div class="flex items-baseline gap-2 flex-wrap">
        <span class="font-medium">{{ $enquiry->kindLabel() }}</span>
        <span class="badge {{ $enquiry->customerBadge() }} badge-sm">{{ $enquiry->customerLabel() }}</span>
        <span class="text-xs opacity-50">{{ $enquiry->created_at?->format('Y-m-d') }}</span>
    </div>

    @foreach ($enquiry->answers() as $answer)
        <div class="text-sm"><span class="opacity-60">{{ $answer['label'] }}:</span> {{ $answer['value'] }}</div>
    @endforeach

    @include('torgash.enquiries::partials.attachments', ['attachmentOwner' => $enquiry])

    @foreach ($enquiry->messages as $post)
        <div class="flex flex-col gap-1 rounded-box p-3 {{ $post->is_staff ? 'bg-base-200' : 'border border-base-300' }}">
            <div class="flex items-baseline gap-2 flex-wrap">
                <span class="font-medium">{{ $post->is_staff ? __('The shop') : __('You') }}</span>
                <span class="text-xs opacity-50">{{ $post->created_at?->format('Y-m-d H:i') }}</span>
            </div>
            <div class="text-sm whitespace-pre-line">{{ $post->body }}</div>
            @include('torgash.enquiries::partials.attachments', ['attachmentOwner' => $post])
        </div>
    @endforeach

    @if ($threadClosed)
        <p class="text-sm opacity-60">{{ __('This conversation is closed.') }}</p>
    @elseif ($replyAction)
        <form method="POST" action="{{ $replyAction }}" enctype="multipart/form-data" class="flex flex-col gap-2">
            @csrf
            <textarea name="body" rows="2" required maxlength="5000"
                      class="textarea textarea-bordered w-full"
                      placeholder="{{ __('Add to this conversation') }}"></textarea>
            @include('torgash.enquiries::partials.attach-field')
            <div>
                <button class="btn btn-primary btn-sm">{{ __('Send') }}</button>
            </div>
        </form>
    @endif
</div>
