{{--
    The catalogue as a menu (PLAN §38): the top categories — icon, name, a
    chevron where there is more below — and a second panel per department that
    slides over the first with "back", "everything in X" and its children.
    Two levels, from the tree the page already has (CategoryTree, read once);
    deeper categories are reached from the category page itself. Without
    script the chevrons do nothing and every name is still a link.
--}}
@php
    $catsTree = app(\Torgash\Catalog\Support\CategoryTree::class);
    $catsRoots = collect($catsTree->ordered(listedOnly: true))->filter(fn ($c) => $c->parent_id === null)->values();
    $catsChildren = collect($catsTree->ordered(listedOnly: true))->groupBy('parent_id');
    $catsHeading = trim((string) ($settings['heading'] ?? ''));
    $catsIcon = fn ($category) => $category->lucideIcon();
@endphp
@if ($catsRoots->isNotEmpty())
    <nav class="torgash-block torgash-block-categories relative overflow-hidden py-2" x-data="{ panel: null }" aria-label="{{ $catsHeading !== '' ? $catsHeading : __('Catalog') }}">
        <div x-show="panel === null" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-4 opacity-0">
            @if ($catsHeading !== '')
                <div class="px-5 pt-2 pb-1 text-sm opacity-60">{{ $catsHeading }}</div>
            @endif
            <ul>
                @foreach ($catsRoots as $catsRoot)
                    @php $catsKids = $catsChildren->get($catsRoot->id, collect()); @endphp
                    <li class="flex items-center">
                        <a href="{{ route('catalog.category', $catsRoot->slug) }}" class="flex-1 min-w-0 flex items-center gap-3 px-5 py-2.5 min-h-12 hover:bg-base-200">
                            @if ($catsRootImage = $catsRoot->iconImageUrl())
                                <img src="{{ $catsRootImage }}" alt="" class="w-6 h-6 shrink-0 object-contain">
                            @elseif ($catsRootIcon = $catsIcon($catsRoot))
                                <x-icon :name="$catsRootIcon" class="w-6 h-6 shrink-0 opacity-80" />
                            @endif
                            <span class="font-medium truncate">{{ $catsRoot->t('name') }}</span>
                        </a>
                        @if ($catsKids->isNotEmpty())
                            <button type="button" x-on:click="panel = {{ $catsRoot->id }}" class="btn btn-ghost btn-square btn-touch mr-2 opacity-60"
                                    aria-label="{{ __('Open :category', ['category' => $catsRoot->t('name')]) }}">
                                <x-icon name="chevron-right" />
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>

        @foreach ($catsRoots as $catsRoot)
            @php $catsKids = $catsChildren->get($catsRoot->id, collect()); @endphp
            @continue($catsKids->isEmpty())
            <div x-show="panel === {{ $catsRoot->id }}" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-4 opacity-0">
                <button type="button" x-on:click="panel = null" class="flex items-center gap-2 px-5 py-3 min-h-12 w-full text-left font-semibold hover:bg-base-200">
                    <x-icon name="chevron-left" /> {{ $catsRoot->t('name') }}
                </button>
                <ul>
                    <li><a href="{{ route('catalog.category', $catsRoot->slug) }}" class="flex items-center px-5 py-2.5 min-h-12 text-primary font-medium hover:bg-base-200">{{ __('Everything in :category', ['category' => $catsRoot->t('name')]) }}</a></li>
                    @foreach ($catsKids as $catsKid)
                        <li><a href="{{ route('catalog.category', $catsKid->slug) }}" class="flex items-center gap-3 px-5 py-2.5 min-h-12 hover:bg-base-200">
                            @if ($catsKidIcon = $catsIcon($catsKid))<x-icon :name="$catsKidIcon" class="w-5 h-5 shrink-0 opacity-70" />@endif
                            {{ $catsKid->t('name') }}
                        </a></li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>
@endif
