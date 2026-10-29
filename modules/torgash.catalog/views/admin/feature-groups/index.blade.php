@extends('admin.layout')

@section('title', __('Characteristic groups'))
@section('title_hint', __('The headings characteristics are filed under on a product\'s page and in its form, in this order. Rename a group in every language here; a characteristic picks its group on its own form, and a new word there makes a new group.'))

{{-- No states to tab: the actions stand level with the heading. --}}
@section('title_actions')
    <a href="{{ route('admin.catalog.features.index') }}" class="btn btn-ghost gap-1.5"><x-icon name="arrow-left" size="sm" /> {{ __('Back to characteristics') }}</a>
    <a href="{{ route('admin.catalog.feature-groups.create') }}" class="btn btn-primary">+ {{ __('New group') }}</a>
@endsection

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php $groupLocale = admin_content_locale(); @endphp

    {{-- Dragged into order (x-sort — Alpine Sort over SortableJS); the order
         is posted at once, nothing to save. Under a finger the arrows do it. --}}
    <div class="card bg-base-100 border border-base-300"
         x-data="{
             ids: @js($groups->pluck('id')->map(fn ($id) => (string) $id)->all()),
             move(id, position) {
                 const from = this.ids.indexOf(String(id));
                 if (from === -1 || position < 0 || position >= this.ids.length) { return; }
                 this.ids.splice(position, 0, this.ids.splice(from, 1)[0]);
                 this.save();
             },
             save() {
                 fetch(@js(route('admin.catalog.feature-groups.sort')), {
                     method: 'POST',
                     headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                     body: JSON.stringify({ ids: this.ids }),
                 });
             },
         }">
        <div class="card-body gap-4">
            @if ($groups->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="layers" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ __('No groups yet — name one here, or type a new word in a characteristic\'s "Group" box.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table admin-cards">
                        <thead>
                            <tr>
                                <th class="w-10"></th>
                                <th>{{ __('Name') }}</th>
                                <th class="text-end">{{ __('Characteristics') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody x-sort="move($item, $position)" x-sort:config="{ delay: 150, delayOnTouchOnly: true, handle: '[x-sort\\:handle]' }">
                            @foreach ($groups as $group)
                                <tr class="hover:bg-base-200/50" x-sort:item="'{{ $group->id }}'">
                                    <td data-cell="handle">
                                        <span class="cursor-grab opacity-30 hover:opacity-70 inline-flex pointer-coarse:p-2" title="{{ __('Drag to reorder') }}" x-sort:handle>
                                            <x-icon name="grip-vertical" size="sm" />
                                        </span>
                                        <span class="hidden pointer-coarse:inline-flex items-center">
                                            <button type="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('Move earlier') }}" aria-label="{{ __('Move earlier') }}"
                                                    x-on:click="move('{{ $group->id }}', ids.indexOf('{{ $group->id }}') - 1)"><x-icon name="arrow-up" size="sm" /></button>
                                            <button type="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('Move later') }}" aria-label="{{ __('Move later') }}"
                                                    x-on:click="move('{{ $group->id }}', ids.indexOf('{{ $group->id }}') + 1)"><x-icon name="arrow-down" size="sm" /></button>
                                        </span>
                                    </td>
                                    <td data-cell="name">
                                        <a href="{{ route('admin.catalog.feature-groups.edit', $group) }}" class="font-medium link link-hover">{{ $group->t('name', $groupLocale) ?: '#'.$group->id }}</a>
                                        @php $groupAlsoIn = collect($group->name ?? [])->except($groupLocale)->filter()->keys(); @endphp
                                        @if ($groupAlsoIn->isNotEmpty())
                                            <div class="text-xs opacity-50">{{ __('Also filled in: :locales', ['locales' => $groupAlsoIn->map('strtoupper')->implode(', ')]) }}</div>
                                        @endif
                                    </td>
                                    <td data-cell="count" class="text-end tabular-nums">
                                        {{ $group->features_count }}
                                    </td>
                                    <td data-cell="actions" class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.catalog.feature-groups.edit', $group) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.catalog.feature-groups.destroy', $group) }}" class="inline"
                                                  data-confirm="{{ __('Delete this group? Its characteristics stay, without a group.') }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p class="text-xs opacity-50">{{ trans_choice(':count characteristic is without a group.|:count characteristics are without a group.', $ungrouped, ['count' => $ungrouped]) }}</p>
        </div>
    </div>
@endsection
