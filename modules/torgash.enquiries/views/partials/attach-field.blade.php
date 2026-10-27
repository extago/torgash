{{--
    "Attach photos" — the input, wherever somebody writes.

    Renders nothing when the shop has turned attachments off (the limit is 0),
    so every form can include it unconditionally.

    The enclosing form MUST carry enctype="multipart/form-data"; there is no way
    for a partial to add that to its parent, and a form that forgets it posts
    the text and silently drops the files.

    `accept` is a convenience for the file picker and NOTHING more — the real
    check is the sniffed mime inside ImageProcessor, because an attribute in
    the markup is a suggestion to a browser.
--}}
@php
    use Torgash\Enquiries\Attachments;

    $maxFiles = Attachments::maxFiles();
    $inputName = 'attachments';
@endphp

@if ($maxFiles > 0)
    <fieldset class="fieldset p-0">
        <legend class="fieldset-legend">{{ __('Photos') }}</legend>
        <input aria-label="{{ __('Photos') }}" type="file" name="{{ $inputName }}[]" multiple accept="image/*"
               class="file-input file-input-bordered w-full">
        <p class="text-xs opacity-50">{{ __('Up to :n photos.', ['n' => $maxFiles]) }}</p>
    </fieldset>
@endif
