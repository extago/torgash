{{--
    The picture a link shows in a messenger or a social network
    (App\Core\Seo\SharePicture): the storefront's, on its form, and a
    record's, in the SEO card. `<share>_file` uploads it and
    `<share>_image_remove` drops it — the form around it is multipart.
--}}
@props([
    'path' => null,
    'share' => 'share',
    'help' => null,
])
@php $sharePictureUrl = \App\Core\Seo\SharePicture::url($path); @endphp
<fieldset class="fieldset p-0">
    <legend class="fieldset-legend">{{ __('Picture for shared links') }}</legend>
    <div class="flex items-center gap-2">
        <input type="file" name="{{ $share }}_file" accept="image/png,image/jpeg,image/webp,image/gif"
               class="file-input file-input-bordered w-full" aria-label="{{ __('Picture for shared links') }}">
        @if ($sharePictureUrl)
            <img src="{{ $sharePictureUrl }}" alt="" class="h-10 w-19 shrink-0 rounded-field border border-base-300 object-cover">
        @endif
    </div>
    @if ($sharePictureUrl)
        <label class="label cursor-pointer justify-start gap-2 mt-1">
            <input type="checkbox" name="{{ $share }}_image_remove" value="1" class="checkbox checkbox-sm">
            <span class="label-text">{{ __('Remove the picture') }}</span>
        </label>
    @endif
    <p class="label whitespace-normal">{{ $help }}</p>
</fieldset>
