{{-- The product's own tab (PLAN §33.6): LINKS, not an editor.

     A question with its answers is a form of its own — a kind per answer, a
     price rule per answer, conditions — and a second place to edit it would be
     a second place for it to be wrong. The same decision the product tabs card
     on this form already made, and for the same reason. --}}
<div class="flex flex-col gap-4">
    @unless ($product->exists)
        <div class="alert text-sm">
            <span>{{ __('Save the product first — an option has to belong to something.') }}</span>
        </div>
    @else
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <div class="flex items-center gap-2">
                    <h3 class="font-semibold grow">{{ __('Options on this product') }}</h3>
                    <a href="{{ route('admin.options.groups.create', ['product' => $product->id]) }}"
                       class="btn btn-ghost btn-sm">{{ __('Add an option') }}</a>
                </div>

                @forelse ($own as $group)
                    <div class="flex items-center gap-2 text-sm">
                        <a href="{{ route('admin.options.groups.edit', $group) }}" class="link link-hover grow">
                            {{ $group->t('name', admin_content_locale()) ?: $group->admin_name }}
                        </a>
                        <span class="opacity-50 text-xs">{{ $group->typeLabel() }}</span>
                        <span class="badge badge-ghost badge-sm">{{ $group->choices->count() }}</span>
                        @if ($group->required)
                            <span class="badge badge-sm badge-ghost">{{ __('Required') }}</span>
                        @endif
                        @unless ($group->enabled)
                            <span class="badge badge-ghost badge-sm">{{ __('Disabled') }}</span>
                        @endunless
                    </div>
                @empty
                    <p class="text-xs opacity-50">{{ __('This product has no options of its own.') }}</p>
                @endforelse
            </div>
        </div>

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <div>
                    <h3 class="font-semibold">{{ __('Shared options that reach this product') }}</h3>
                    <p class="text-xs opacity-50">
                        {{ __('Placed by their own conditions. A shared option is never re-priced here — its price is a formula read against this product. If this one needs a different number, copy it into an option of its own.') }}
                    </p>
                </div>

                @forelse ($offered as $group)
                    @php
                        $applies = $shared->contains('id', $group->id);
                        $isExcluded = in_array((int) $group->id, $excluded, true);
                    @endphp
                    @continue (! $applies && ! $isExcluded)

                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <a href="{{ route('admin.options.groups.edit', $group) }}" class="link link-hover grow">
                            {{ $group->t('name', admin_content_locale()) ?: $group->admin_name }}
                        </a>
                        @if ($isExcluded)
                            <span class="badge badge-ghost badge-sm">{{ __('Switched off here') }}</span>
                        @endif
                        {{-- Buttons targeting forms that live OUTSIDE this
                             product's form: a nested <form> loses its tag and
                             leaks its inputs into the outer submit. --}}
                        <button form="options-exclude-{{ $group->id }}" class="btn btn-ghost btn-sm">
                            {{ $isExcluded ? __('Ask it here too') : __('Do not ask it here') }}
                        </button>
                        <button form="options-copy-{{ $group->id }}" class="btn btn-ghost btn-sm">
                            {{ __('Copy into this product') }}
                        </button>
                    </div>
                @empty
                    <p class="text-xs opacity-50">{{ __('No shared option reaches this product.') }}</p>
                @endforelse
            </div>
        </div>
    @endunless
</div>

{{-- The forms those buttons target, pushed to the end of the PAGE so they are
     siblings of the product form rather than children of it: a nested <form>
     is dropped by the parser and its inputs leak into the outer submit. --}}
@if ($product->exists)
    @push('scripts')
        @foreach ($offered as $group)
            <form id="options-exclude-{{ $group->id }}" method="POST" class="hidden"
                  action="{{ route('admin.options.groups.exclude', $group) }}">
                @csrf
                <input type="hidden" name="product_id" value="{{ $product->id }}">
            </form>
            <form id="options-copy-{{ $group->id }}" method="POST" class="hidden"
                  action="{{ route('admin.options.groups.copy', $group) }}">
                @csrf
                <input type="hidden" name="product_id" value="{{ $product->id }}">
            </form>
        @endforeach
    @endpush
@endif
