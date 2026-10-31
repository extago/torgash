@extends('admin.layout')

@section('title', __('Checkout fields'))
@section('title_hint', __("Questions of your own at checkout — who takes the parcel, how the customer found you, a company's bank details. The answers are kept on the order: in its card, the letters, the invoice, automations and the export."))

@section('title_actions')
    <a href="{{ route('admin.checkout.fields.create') }}" class="btn btn-primary">+ {{ __('New field') }}</a>
@endsection

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php $fieldsLocale = admin_content_locale(); @endphp

    {{-- Dragged into order (x-sort); the order is posted at once. Under a
         finger the arrows do it. --}}
    <div class="card bg-base-100 border border-base-300"
         x-data="{
             ids: @js($fields->pluck('id')->map(fn ($id) => (string) $id)->all()),
             move(id, position) {
                 const from = this.ids.indexOf(String(id));
                 if (from === -1 || position < 0 || position >= this.ids.length) { return; }
                 this.ids.splice(position, 0, this.ids.splice(from, 1)[0]);
                 fetch(@js(route('admin.checkout.fields.sort')), {
                     method: 'POST',
                     headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                     body: JSON.stringify({ ids: this.ids }),
                 });
             },
         }">
        <div class="card-body gap-4">
            @if ($fields->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="text-cursor-input" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ __('No fields of your own yet — checkout asks only its usual questions.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table admin-cards">
                        <thead>
                            <tr>
                                <th class="w-10"></th>
                                <th>{{ __('Field') }}</th>
                                <th>{{ __('Type') }}</th>
                                <th>{{ __('Where') }}</th>
                                <th>{{ __('Asked of') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody x-sort="move($item, $position)" x-sort:config="{ delay: 150, delayOnTouchOnly: true, handle: '[x-sort\\:handle]' }">
                            @foreach ($fields as $field)
                                <tr class="hover:bg-base-200/50" x-sort:item="'{{ $field->id }}'">
                                    <td data-cell="handle">
                                        <span class="cursor-grab opacity-30 hover:opacity-70 inline-flex pointer-coarse:p-2" title="{{ __('Drag to reorder') }}" x-sort:handle>
                                            <x-icon name="grip-vertical" size="sm" />
                                        </span>
                                        <span class="hidden pointer-coarse:inline-flex items-center">
                                            <button type="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('Move earlier') }}" aria-label="{{ __('Move earlier') }}"
                                                    x-on:click="move('{{ $field->id }}', ids.indexOf('{{ $field->id }}') - 1)"><x-icon name="arrow-up" size="sm" /></button>
                                            <button type="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('Move later') }}" aria-label="{{ __('Move later') }}"
                                                    x-on:click="move('{{ $field->id }}', ids.indexOf('{{ $field->id }}') + 1)"><x-icon name="arrow-down" size="sm" /></button>
                                        </span>
                                    </td>
                                    <td data-cell="name">
                                        <a href="{{ route('admin.checkout.fields.edit', $field) }}" class="font-medium link link-hover">{{ $field->t('label', $fieldsLocale) ?: $field->code }}</a>
                                        @if ($field->required)<span class="text-error">*</span>@endif
                                        <div class="text-xs opacity-50 font-mono">{{ $field->code }}</div>
                                    </td>
                                    <td data-cell="type" class="text-sm">{{ __(\Torgash\Checkout\Models\CheckoutField::TYPES[$field->type] ?? $field->type) }}</td>
                                    <td data-cell="place" class="text-sm">{{ __(\Torgash\Checkout\Models\CheckoutField::PLACES[$field->place] ?? $field->place) }}</td>
                                    <td data-cell="buyer" class="text-sm">
                                        {{ __(\Torgash\Checkout\Models\CheckoutField::BUYERS[$field->buyer] ?? $field->buyer) }}
                                        @if ($field->methodIds())
                                            <div class="text-xs opacity-60">{{ collect($field->methodIds())->map(fn ($id) => $methods[(string) $id] ?? '#'.$id)->implode(', ') }}</div>
                                        @endif
                                    </td>
                                    <td data-cell="status">
                                        <span @class(['badge badge-sm', 'badge-success' => $field->enabled, 'badge-ghost' => ! $field->enabled])>{{ $field->enabled ? __('On') : __('Off') }}</span>
                                    </td>
                                    <td data-cell="actions" class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.checkout.fields.edit', $field) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.checkout.fields.destroy', $field) }}" class="inline"
                                                  data-confirm="{{ __('Delete this field? Orders keep the answers they were given.') }}">
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
        </div>
    </div>
@endsection
