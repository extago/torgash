@extends('admin.layout')

@section('title', $document->typeLabel().' '.$document->number)
@section('own_heading', '1')

@section('content')
    {{-- Anything but a draft is read only: posted, or posted and revoked. --}}
    @php $posted = ! $document->isDraft(); @endphp

    <x-admin.form-bar>
        <a href="{{ route('admin.inventory.documents.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Stock documents') }}" aria-label="{{ __('Stock documents') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none">{{ $document->typeLabel() }}</div>
            <div class="font-semibold truncate leading-tight flex items-center gap-2">
                <span class="font-mono">{{ $document->number }}</span>
                @if ($document->isRevoked())
                    <span class="badge badge-sm badge-error">{{ __('Revoked') }} {{ $document->revoked_at?->format('d.m.Y H:i') }}</span>
                @elseif ($posted)
                    <span class="badge badge-sm badge-success">{{ __('Posted') }} {{ $document->posted_at?->format('d.m.Y H:i') }}</span>
                @else
                    <span class="badge badge-sm badge-ghost">{{ __('Draft') }}</span>
                @endif
            </div>
        </div>
        <a href="{{ route('admin.inventory.documents.print', $document) }}" target="_blank" rel="noopener" class="btn btn-sm gap-1.5">
            <x-icon name="printer" size="sm" /> {{ __('Print') }}
        </a>
        <x-slot:actions>
            @unless ($posted)
                <button type="submit" form="document-form" name="after" value="save" class="btn btn-sm btn-primary">{{ __('Save') }}</button>
                <button type="submit" form="document-form" name="after" value="post" class="btn btn-sm btn-primary gap-1.5"
                        data-confirm="{{ __('Post this document? The stock moves now, and the document can no longer be changed.') }}">
                    <x-icon name="send" size="sm" /> {{ __('Save and post') }}
                </button>
            @endunless
        </x-slot:actions>
    </x-admin.form-bar>

    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif
    @if ($document->isRevoked())
        <div class="alert alert-error alert-soft text-sm flex flex-col items-start gap-1">
            <span class="font-semibold">{{ __('This document has been revoked: the stock is as it was before it was posted, and its movements are gone from the journal.') }}</span>
            <span>{{ __('By :name on :date. Reason: :reason', ['name' => $document->revoked_by_name ?? '—', 'date' => $document->revoked_at?->format('d.m.Y H:i'), 'reason' => $document->revoke_reason]) }}</span>
        </div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm flex flex-col items-start gap-1">
            @foreach ($errors->all() as $error)
                <span>{{ $error }}</span>
            @endforeach
        </div>
    @endif

    {{-- Quick entry lives OUTSIDE the document's form: a form inside a form is
         dropped by the parser and its inputs ride out on the wrong submit. --}}
    @unless ($posted)
        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-2">
                <form class="flex flex-wrap items-center gap-2"
                      hx-post="{{ route('admin.inventory.documents.lines.add', $document) }}" hx-target="#document-lines" hx-swap="outerHTML"
                      hx-on::after-request="if (event.detail.successful) { this.reset(); this.querySelector('[name=code]').focus(); }">
                    @csrf
                    <label class="input input-bordered flex items-center gap-2 grow sm:grow-0 sm:w-80">
                        <x-icon name="scan-barcode" size="sm" class="opacity-50" />
                        <input type="text" name="code" autocapitalize="off" autocorrect="off" spellcheck="false" autocomplete="off" autofocus class="grow" placeholder="{{ __('SKU — Enter adds it') }}"
                               hx-get="{{ route('admin.inventory.documents.search', $document) }}" hx-trigger="input changed delay:300ms"
                               hx-target="#document-search" hx-swap="innerHTML">
                    </label>
                    <input type="number" name="qty" value="1" min="1" class="input input-bordered w-24" aria-label="{{ __('Quantity') }}">
                    <button class="btn">{{ __('Add') }}</button>
                </form>
                <div id="document-search"></div>
            </div>
        </section>
    @endunless

    <form id="document-form" method="POST" action="{{ route('admin.inventory.documents.update', $document) }}" class="flex flex-col gap-4">
        @csrf @method('PUT')

        <section class="card bg-base-100 border border-base-300">
            <div class="card-body grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ $document->type === 'transfer' ? __('From warehouse') : __('Warehouse') }}</legend>
                    <select name="warehouse_id" class="select select-bordered w-full" @disabled($posted)>
                        @foreach ($warehouses as $id => $row)
                            <option value="{{ $id }}" @selected($document->warehouse_id === $id)>{{ pick_translation($row->name) }}</option>
                        @endforeach
                    </select>
                </fieldset>
                @if ($document->type === 'transfer')
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('To warehouse') }}</legend>
                        <select name="to_warehouse_id" class="select select-bordered w-full" @disabled($posted)>
                            <option value="">—</option>
                            @foreach ($warehouses as $id => $row)
                                <option value="{{ $id }}" @selected($document->to_warehouse_id === $id)>{{ pick_translation($row->name) }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                @endif
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Date') }}</legend>
                    <input type="date" name="document_date" value="{{ $document->document_date?->toDateString() }}" class="input input-bordered w-full" @disabled($posted)>
                </fieldset>
                @if (in_array($document->type, ['receipt', 'writeoff'], true))
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ $document->type === 'receipt' ? __('Supplier') : __('Reason') }}</legend>
                        <input name="counterparty" value="{{ $document->counterparty }}" maxlength="190" class="input input-bordered w-full" @disabled($posted)>
                    </fieldset>
                @endif
                <fieldset class="fieldset p-0 sm:col-span-2 lg:col-span-4">
                    <legend class="fieldset-legend">{{ __('Note') }}</legend>
                    <textarea name="note" rows="2" class="textarea textarea-bordered w-full" @disabled($posted)>{{ $document->note }}</textarea>
                </fieldset>
            </div>
        </section>

        <section class="card bg-base-100 border border-base-300">
            <div class="card-body">
                @include('torgash.inventory::admin.documents._lines', ['document' => $document, 'lineError' => null])
            </div>
        </section>
    </form>

    @unless ($posted)
        <form method="POST" action="{{ route('admin.inventory.documents.destroy', $document) }}" class="flex justify-end"
              data-confirm="{{ __('Delete this draft?') }}">
            @csrf @method('DELETE')
            <button class="btn btn-ghost btn-sm text-error gap-1.5"><x-icon name="trash-2" size="sm" /> {{ __('Delete the draft') }}</button>
        </form>
    @endunless

    {{-- Taking a posted document back: only for someone given the permission,
         and only with their own password typed now (Revoking). --}}
    @if ($revokeObstacles !== null)
        <div class="flex justify-end">
            <x-modal name="document-revoke" :title="__('Revoke the document')" icon="undo-2"
                     :trigger="__('Revoke the document')" trigger-icon="undo-2" trigger-class="btn btn-ghost btn-sm text-error gap-1.5">
                @if ($revokeObstacles)
                    <div class="flex flex-col gap-3 text-sm">
                        <p>{{ __('This document cannot be revoked: undoing it would change what happened after it.') }}</p>
                        <ul class="list-disc ps-5 text-error">
                            @foreach ($revokeObstacles as $obstacle)
                                <li>{{ $obstacle }}</li>
                            @endforeach
                        </ul>
                        <p class="opacity-70">{{ __('Correct it with another document instead — a write-off for a receipt, a receipt for a write-off.') }}</p>
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.inventory.documents.revoke', $document) }}" class="flex flex-col gap-4">
                        @csrf
                        <p class="text-sm">{{ __('The stock goes back to what it was before this document, its movements and batches leave the journal, and the document stays marked as revoked with your name and the reason. This cannot be undone.') }}</p>
                        @if ($errors->revoke->any())
                            <div class="alert alert-error text-sm flex flex-col items-start gap-1">
                                @foreach ($errors->revoke->all() as $error)
                                    <span>{{ $error }}</span>
                                @endforeach
                            </div>
                        @endif
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Why') }}</legend>
                            <input type="text" name="reason" value="{{ old('reason') }}" required maxlength="500" class="input input-bordered w-full"
                                   placeholder="{{ __('e.g. posted by mistake, a duplicate of IN-000012') }}">
                        </fieldset>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Your password') }}</legend>
                            <input type="password" name="current_password" required autocomplete="current-password" class="input input-bordered w-full">
                        </fieldset>
                        <div>
                            <button class="btn btn-sm btn-error btn-outline gap-1.5"><x-icon name="undo-2" size="sm" /> {{ __('Revoke') }}</button>
                        </div>
                    </form>
                @endif
            </x-modal>
        </div>

        @if ($errors->revoke->any())
            <script nonce="{{ csp_nonce() }}">document.getElementById('modal-document-revoke').showModal()</script>
        @endif
    @endif
@endsection
