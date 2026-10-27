{{--
    The photographs on one row of a conversation.

    Every URL is signed, because the files are not on the public disk and the
    reader may be a guest holding nothing but their order's key — the signature
    says WHICH file, not who is asking, exactly as the reply route's does.
    Whoever renders this has already decided the reader may see the thread.

    $attachmentOwner — an enquiry (what came with the form) or a message (what came with
    a reply).
--}}
@php
    use Illuminate\Support\Facades\URL;
    use Torgash\Enquiries\Attachments;
    use Torgash\Media\Gallery;

    $files = Attachments::of($attachmentOwner);
@endphp

@if ($files->isNotEmpty())
    <div class="flex flex-wrap gap-2">
        @foreach ($files as $file)
            @php
                $full = URL::signedRoute('enquiries.file', ['media' => $file->id]);
                // The shop's own thumbnail sizes, asked of the media module so
                // there is no second idea of which one is small enough.
                $size = Gallery::thumbName($file, 160);
                $small = $size ? URL::signedRoute('enquiries.file', ['media' => $file->id, 'size' => $size]) : $full;
            @endphp
            <a href="{{ $full }}" target="_blank" rel="noopener"
               class="block w-20 h-20 rounded-box overflow-hidden border border-base-300">
                <img src="{{ $small }}" alt="{{ $file->name }}" loading="lazy"
                     class="w-full h-full object-cover">
            </a>
        @endforeach
    </div>
@endif
