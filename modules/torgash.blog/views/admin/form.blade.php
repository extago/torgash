@extends('admin.layout')

@php $formLocale = admin_content_locale(); @endphp
@section('title', $post->exists ? ($post->t('title', $formLocale) ?: __('Article')) : __('New article'))
@section('own_heading', '1')

@section('content')
    <form method="POST" class="flex flex-col gap-4"
          action="{{ $post->exists ? route('admin.blog.update', $post) : route('admin.blog.store') }}">
        @csrf
        @if ($post->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.blog.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to the blog') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $post->exists ? __('Edit article') : __('New article') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $post->t('title', $formLocale) ?: __('New article') }}</div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($post->exists)
                        @if ($post->isReachable())
                            <li><a href="{{ $post->url() }}" target="_blank" rel="noopener"><x-icon name="external-link" /> {{ __('Open on the site') }}</a></li>
                        @endif
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="post-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    @endif
                </ul>
            </div>
            <a href="{{ route('admin.blog.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $post->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if (session('warning'))
            <div class="alert alert-warning text-sm">{{ session('warning') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <x-admin.translatable-field name="title" :label="__('Title')" :value="$post->title ?? []" required />
                        <x-admin.translatable-field name="lead" :label="__('Lead')" :value="$post->lead ?? []" textarea :rows="3" />
                        <p class="text-xs opacity-60 -mt-2">{{ __('A sentence or two under the title — on the card in the feed and at the top of the article.') }}</p>
                        <x-admin.translatable-field name="body" :label="__('Text')" :value="$post->body ?? []" rich :rows="22" :record="$post->exists ? $post : null" />
                        <p class="text-xs opacity-60">
                            {{ __('Headings (H2, H3) become the article\'s contents by themselves. A product goes right into the text as {product:SKU} on a line of its own — its card with "Open in the catalogue".') }}
                        </p>
                    </div>
                </section>

                @if ($post->exists)
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h3 class="font-semibold">{{ __('Cover') }}</h3>
                            <p class="text-xs opacity-60">{{ __('One picture — on the card in the feed and over the article; a new one replaces it. Pictures and galleries for the text are added in the text editor.') }}</p>
                            @include('torgash.media::admin.gallery', ['owner' => 'blog_post', 'model' => $post])
                        </div>
                    </section>
                @else
                    <div class="alert text-sm"><x-icon name="image" /> {{ __('Save the article once to add its cover, pictures and galleries.') }}</div>
                @endif

                {{-- Another module's section of this form (the article's videos).
                     Inside the form: whatever it draws is saved with the article. --}}
                {!! hook_filter('blog.post.form_sections', '', ['record' => $post]) !!}

                <x-seo.fields :record="$post" :url-preview="$post->exists ? $post->url() : null" />
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Publication') }}</h3>
                        @php $currentStatus = old('status', $post->status ?? 'draft'); @endphp
                        <select name="status" class="select select-bordered w-full">
                            <option value="draft" @selected($currentStatus === 'draft')>{{ __('Draft') }}</option>
                            <option value="published" @selected($currentStatus === 'published')>{{ __('Published') }}</option>
                            <option value="hidden" @selected($currentStatus === 'hidden')>{{ __('Hidden — only by its address') }}</option>
                        </select>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Publication date') }}</legend>
                            <input type="datetime-local" name="published_at" value="{{ old('published_at', $post->published_at?->format('Y-m-d\TH:i')) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('A date still ahead publishes it then, on its own. Empty — now, when published.') }}</p>
                        </fieldset>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Author') }}</legend>
                            <select name="author_id" class="select select-bordered w-full">
                                <option value="">—</option>
                                @foreach ($authors as $authorOption)
                                    <option value="{{ $authorOption->id }}" @selected((int) old('author_id', $post->author_id ?? auth()->id()) === $authorOption->id)>{{ $authorOption->name }}</option>
                                @endforeach
                            </select>
                        </fieldset>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Address') }}</legend>
                            <input name="slug" value="{{ old('slug', $post->slug) }}" class="input input-bordered w-full" placeholder="{{ __('made from the title') }}">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Renaming it later leaves a 301 behind, so old links keep working.') }}</p>
                        </fieldset>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="comments_enabled" value="1" class="toggle toggle-sm toggle-primary" @checked(old('comments_enabled', $post->comments_enabled ?? true))>
                            <span class="label-text">{{ __('Comments') }}</span>
                        </label>
                        @if ($post->exists)
                            <div class="text-xs opacity-60">{{ trans_choice(':count minute to read|:count minutes to read', $post->reading_minutes, ['count' => $post->reading_minutes]) }} · {{ __('Views') }}: {{ $post->views_count }} · {{ __('Likes') }}: {{ $post->likes_count }}</div>
                        @endif
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Rubrics') }}</h3>
                        @php $picked = collect(old('rubrics', $post->rubrics->pluck('id')->all()))->map('intval')->all(); @endphp
                        @forelse ($rubrics as $rubricOption)
                            <label class="label cursor-pointer justify-start gap-2">
                                <input type="checkbox" name="rubrics[]" value="{{ $rubricOption->id }}" class="checkbox checkbox-sm" @checked(in_array($rubricOption->id, $picked, true))>
                                <span class="badge badge-sm text-white border-0" style="background-color: {{ $rubricOption->colour() }}">{{ $rubricOption->t('name', $formLocale) }}</span>
                            </label>
                        @empty
                            <p class="text-sm opacity-60">{{ __('No rubrics yet.') }} <a href="{{ route('admin.blog.rubrics.create') }}" class="link">{{ __('Add one') }}</a></p>
                        @endforelse
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Products in the article') }}</h3>
                        <textarea name="products" rows="4" class="textarea textarea-bordered w-full font-mono text-sm" placeholder="SKU-1&#10;SKU-2">{{ old('products', $post->products->pluck('sku')->implode("\n")) }}</textarea>
                        <p class="text-xs opacity-60">{{ __('SKUs, one per line — a strip of their cards under the article, in this order.') }}</p>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($post->exists)
        <form id="post-delete" method="POST" action="{{ route('admin.blog.destroy', $post) }}"
              data-confirm="{{ __('Delete this article?') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
