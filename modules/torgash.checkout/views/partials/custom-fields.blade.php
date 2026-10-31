{{--
    The shop's own questions placed at `$customPlace` (Orders\CheckoutFields).

    Whether one is asked follows the form as it is filled in — a company's
    question for a company, the courier's "who takes the parcel" once the
    courier is chosen — read from the form itself (`buyer_type`, the checked
    `shipping_method_id`) on every change and after the methods are
    re-quoted. A question not asked is hidden AND disabled: a hidden
    required field would block the submit with a message nobody can see,
    and a disabled one is not posted. The server asks the same of the order
    again (CheckoutField::appliesTo()).

    Named custom* — an @include hands a partial everything its caller has.
--}}
@php $customAsked = app(\Torgash\Checkout\Orders\CheckoutFields::class)->asked($customPlace); @endphp
@if ($customAsked->isNotEmpty())
    @once
        @push('scripts')
            <script nonce="{{ csp_nonce() }}">
                document.addEventListener('alpine:init', () => {
                    Alpine.data('checkoutField', ({ buyer, methods }) => ({
                        asked: true,
                        init() {
                            const form = this.$el.closest('form');
                            const check = () => {
                                const who = form?.querySelector('[name=buyer_type]:checked')?.value || 'person';
                                const offer = form?.querySelector('[name=shipping_method_id]:checked')?.value || '';
                                this.asked = (buyer === 'all' || buyer === who)
                                    && (methods.length === 0 || methods.includes(offer.split(':')[0]));
                            };
                            check();
                            form?.addEventListener('change', check);
                            document.body.addEventListener('htmx:afterSettle', check);
                        },
                    }));
                });
            </script>
        @endpush
    @endonce

    <div class="grid gap-4">
        @foreach ($customAsked as $customField)
            @php
                $customName = 'fields['.$customField->code.']';
                $customOld = old('fields.'.$customField->code);
                $customHelp = $customField->t('help');
                $customLegend = e((string) $customField->t('label')).($customField->required ? ' *' : '');
            @endphp
            <div x-data="checkoutField(@js(['buyer' => $customField->buyer, 'methods' => array_map('strval', $customField->methodIds())]))"
                 x-show="asked" @if ($customField->buyer !== 'all' || $customField->methodIds() !== []) x-cloak @endif
                 class="fieldset p-0">
                @if ($customField->type === 'checkbox')
                    <label class="label cursor-pointer justify-start gap-2 whitespace-normal">
                        <input type="checkbox" name="{{ $customName }}" value="1" class="checkbox checkbox-primary"
                               :disabled="! asked" @if ($customField->required) :required="asked" @endif @checked($customOld)>
                        <span class="label-text">{!! $customLegend !!}</span>
                    </label>
                @else
                    <span class="fieldset-legend">{!! $customLegend !!}</span>
                    @if ($customHelp && $customField->type === 'radio')
                        <span class="text-sm opacity-70 -mt-1">{{ $customHelp }}</span>
                    @endif
                    @switch ($customField->type)
                        @case('textarea')
                            <textarea name="{{ $customName }}" rows="2" class="textarea textarea-bordered w-full" placeholder="{{ $customField->t('placeholder') }}"
                                      :disabled="! asked" @if ($customField->required) :required="asked" @endif>{{ $customOld }}</textarea>
                            @break
                        @case('select')
                            <select name="{{ $customName }}" class="select select-bordered w-full"
                                    :disabled="! asked" @if ($customField->required) :required="asked" @endif>
                                <option value="">{{ $customField->t('placeholder') ?: __('Not chosen') }}</option>
                                @foreach ($customField->choices() as $customIndex => $customChoice)
                                    <option value="{{ $customIndex }}" @selected((string) $customOld === (string) $customIndex)>{{ $customChoice }}</option>
                                @endforeach
                            </select>
                            @break
                        @case('radio')
                            {{-- One of several as chips: the answers in sight, one tap each. --}}
                            <div class="flex flex-wrap gap-2">
                                @foreach ($customField->choices() as $customIndex => $customChoice)
                                    <label class="flex items-center gap-2 border border-base-300 rounded-field px-3 h-10 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                        <input type="radio" name="{{ $customName }}" value="{{ $customIndex }}" class="radio radio-primary"
                                               :disabled="! asked" @if ($customField->required) :required="asked" @endif @checked((string) $customOld === (string) $customIndex)>
                                        <span>{{ $customChoice }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @break
                        @case('date')
                            <input type="date" name="{{ $customName }}" value="{{ $customOld }}" class="input input-bordered w-full max-w-xs"
                                   :disabled="! asked" @if ($customField->required) :required="asked" @endif>
                            @break
                        @default
                            <input type="text" name="{{ $customName }}" value="{{ $customOld }}" class="input input-bordered w-full" placeholder="{{ $customField->t('placeholder') }}"
                                   :disabled="! asked" @if ($customField->required) :required="asked" @endif>
                    @endswitch
                @endif
                @if ($customHelp && $customField->type !== 'radio')
                    <span class="text-xs opacity-60">{{ $customHelp }}</span>
                @endif
                @error('fields.'.$customField->code) <span class="text-xs text-error">{{ $message }}</span> @enderror
            </div>
        @endforeach
    </div>
@endif
