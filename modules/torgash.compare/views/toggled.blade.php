{{-- The answer to a toggle: the button in its new state, the header counter
     out of band and, on an ADD, core's corner notice — which also says what
     was pushed out when the group was full. Taking one out says nothing. --}}
@include('torgash.compare::button', ['compareProduct' => $compareProduct, 'compareLabelled' => $compareLabelled])
@include('torgash.compare::counter', ['compareCounterOob' => true])
@if ($compareAdded && setting('torgash.compare', 'notice_on_add'))
    @php
        $compareNoticeText = $comparePushed
            ? __(':name — in place of «:old»: up to :max products of one category are compared.', [
                'name' => $compareProduct->t('name'),
                'old' => $comparePushed->t('name'),
                'max' => max(1, (int) setting('torgash.compare', 'per_group_max')),
            ])
            : $compareProduct->t('name');
    @endphp
    <x-notice :title="__('Added to comparison')" :text="$compareNoticeText"
              :image="$compareProduct->primaryImageUrl(160)" icon="square-kanban" icon-class="text-primary torgash-icon-filled"
              :href="route('compare.index', ['group' => $compareGroup])" :action="__('Compare')" />
@endif
