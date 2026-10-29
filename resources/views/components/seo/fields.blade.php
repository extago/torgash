{{--
    The SEO card, drawn the same on every form that has one — product,
    category, brand, page.

    In core because the FIELDS are core's contract (App\Core\Seo\PageSubject)
    even though every column belongs to a module: four copies of this markup
    would drift within a release, and the shop owner would learn four slightly
    different screens for one idea.

    `titleField` exists for the one module whose column is named differently;
    see App\Core\Seo\MetaFields. `picture` adds the picture for shared links,
    for a record with a `share_image` column (App\Core\Seo\SharePicture) —
    the form around it must then be multipart.
--}}
@props([
    'record' => null,
    'titleField' => 'meta_title',
    'urlPreview' => null,
    'picture' => false,
])
<div class="card bg-base-100 border border-base-300">
    <div class="card-body gap-4">
        <h3 class="font-semibold">{{ __('SEO') }}</h3>

        <p class="text-xs opacity-60 -mt-2">
            {{ __('Leave a field empty and the shop works one out: the name for the title, the first sentences of the description. Fill it in only where you want something else.') }}
        </p>

        <x-admin.translatable-field
            :name="$titleField"
            :label="__('Page title')"
            :value="$record?->{$titleField} ?? []"
            :placeholder="__('the name, plus the shop')" />

        <x-admin.translatable-field
            name="meta_description"
            :label="__('Meta description')"
            :value="$record?->meta_description ?? []"
            textarea
            rows="2"
            :placeholder="__('about 150–160 characters — this is the text under the link in search results')" />

        <x-admin.translatable-field
            name="h1"
            :label="__('Heading (H1)')"
            :value="$record?->h1 ?? []"
            :placeholder="__('same as the title')" />

        <fieldset class="fieldset p-0">
            <legend class="fieldset-legend">{{ __('Canonical address') }}</legend>
            <input name="canonical_url" value="{{ old('canonical_url', $record?->canonical_url) }}" aria-label="{{ __('Canonical address') }}"
                   class="input input-bordered w-full" placeholder="{{ $urlPreview ?? __('this page') }}">
            <p class="text-xs opacity-60 mt-1">
                {{ __('Fill this in only when this page is a copy of another one: search engines will be told to count that other address instead.') }}
            </p>
        </fieldset>

        @if ($picture)
            <x-admin.share-picture :path="$record?->share_image"
                :help="__('Shown when a link to this page is sent in a messenger or posted to a social network. Empty: the page\'s own picture (a product\'s photo, a category\'s tile), else the storefront\'s. About 1200×630, JPG or PNG up to 5 MB.')" />
        @endif

        <label class="label cursor-pointer justify-start gap-2">
            <input type="checkbox" name="noindex" value="1" class="toggle toggle-sm toggle-warning"
                   @checked(old('noindex', $record?->noindex ?? false))>
            <span class="label-text">{{ __('Keep out of search engines') }}</span>
        </label>
        <p class="text-xs opacity-60 -mt-2">
            {{ __('The page stays open to anyone with the link — this only asks search engines not to list it.') }}
        </p>

        {{ $slot }}
    </div>
</div>
