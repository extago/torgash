@php
    use Torgash\Enquiries\Kinds;

    $blockKind = $settings['kind'] ?? 'message';
    // A form the shop built (PLAN §48) that has been switched off draws
    // nothing, wherever it was placed.
    $blockForm = Kinds::find($blockKind)['form'] ?? null;
    $blockHeading = ($settings['heading'] ?? '') ?: Kinds::label($blockKind);
    $blockNote = ($settings['note'] ?? '') ?: $blockForm?->t('intro');
@endphp

@if (Kinds::exists($blockKind) && (! $blockForm || $blockForm->enabled))
    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-3">
            @if ($blockHeading)
                <h2 class="card-title text-base">{{ $blockHeading }}</h2>
            @endif

            @include('torgash.enquiries::partials.form', [
                'enquiryKind' => $blockKind,
                'enquiryNote' => $blockNote,
            ])
        </div>
    </div>
@endif
