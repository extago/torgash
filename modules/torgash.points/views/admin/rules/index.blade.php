@extends('admin.layout')

@section('title', __('Bonus points'))
@section('title_hint', __('What goods earn and whether points pay for them. A product\'s own answer on its Bonus points tab comes first; then these rules from the top — the first one that fits and speaks of the question decides it; then the module\'s settings. A customer group\'s multiplier multiplies whatever came out.'))
@section('title_actions')
    <a href="{{ url('/admin/settings/torgash.points') }}" class="btn btn-ghost gap-1.5"><x-icon name="settings" size="sm" /> {{ __('Settings') }}</a>
    <a href="{{ route('admin.points.rules.create') }}" class="btn btn-primary">+ {{ __('New rule') }}</a>
@endsection

@section('content')
    @include('torgash.points::admin._nav', ['pointsScreen' => 'rules'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    @php
        $ruleNumber = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        $ruleList = fn (array $ids, array $known) => collect($ids)->map(fn ($id) => $known[$id] ?? '#'.$id)->implode(', ');
    @endphp

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
        {{-- Dragged into order (x-sort); the order is posted at once and the
             page drawn again, because which rules are out of reach depends on it. --}}
        <div class="card bg-base-100 border border-base-300"
             x-data="{
                 ids: @js($rules->pluck('id')->map(fn ($id) => (string) $id)->all()),
                 move(id, position) {
                     const from = this.ids.indexOf(String(id));
                     if (from === -1 || position < 0 || position >= this.ids.length) { return; }
                     this.ids.splice(position, 0, this.ids.splice(from, 1)[0]);
                     fetch(@js(route('admin.points.rules.sort')), {
                         method: 'POST',
                         headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                         body: JSON.stringify({ ids: this.ids }),
                     }).then(() => window.location.reload());
                 },
             }">
            <div class="card-body gap-4">
                @if ($rules->isEmpty())
                    <div class="text-center py-12 opacity-60">
                        <x-icon name="list-ordered" size="xl" class="opacity-40" />
                        <p class="mt-2">{{ __('No rules yet — every product earns and is paid for as the settings say.') }}</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table admin-cards">
                            <thead>
                                <tr>
                                    <th class="w-10"></th>
                                    <th>{{ __('Which goods') }}</th>
                                    <th>{{ __('Earns') }}</th>
                                    <th>{{ __('Paying with points') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody x-sort="move($item, $position)" x-sort:config="{ delay: 150, delayOnTouchOnly: true, handle: '[x-sort\\:handle]' }">
                                @foreach ($rules as $rule)
                                    @php
                                        $ruleConditions = \Torgash\Points\Rules::clean((array) $rule->conditions);
                                        $ruleParts = array_filter([
                                            isset($ruleConditions['categories']) ? __('Category').': '.$ruleList($ruleConditions['categories'], $names['categories']) : null,
                                            isset($ruleConditions['feature_values']) ? __('Characteristic').': '.$ruleList($ruleConditions['feature_values'], $names['feature_values']) : null,
                                            isset($ruleConditions['products']) ? __('Products').': '.$ruleList($ruleConditions['products'], $names['products']) : null,
                                            isset($ruleConditions['price_min']) ? __('price from :amount', ['amount' => admin_price($ruleConditions['price_min'])]) : null,
                                            isset($ruleConditions['price_max']) ? __('price up to :amount', ['amount' => admin_price($ruleConditions['price_max'])]) : null,
                                            isset($ruleConditions['groups']) ? __('Customer group').': '.$ruleList($ruleConditions['groups'], $names['groups']) : null,
                                        ]);
                                    @endphp
                                    <tr class="hover:bg-base-200/50" x-sort:item="'{{ $rule->id }}'">
                                        <td data-cell="handle">
                                            <span class="cursor-grab opacity-30 hover:opacity-70 inline-flex pointer-coarse:p-2" title="{{ __('Drag to reorder') }}" x-sort:handle>
                                                <x-icon name="grip-vertical" size="sm" />
                                            </span>
                                            <span class="hidden pointer-coarse:inline-flex items-center">
                                                <button type="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('Move earlier') }}" aria-label="{{ __('Move earlier') }}"
                                                        x-on:click="move('{{ $rule->id }}', ids.indexOf('{{ $rule->id }}') - 1)"><x-icon name="arrow-up" size="sm" /></button>
                                                <button type="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('Move later') }}" aria-label="{{ __('Move later') }}"
                                                        x-on:click="move('{{ $rule->id }}', ids.indexOf('{{ $rule->id }}') + 1)"><x-icon name="arrow-down" size="sm" /></button>
                                            </span>
                                        </td>
                                        <td data-cell="name">
                                            <a href="{{ route('admin.points.rules.edit', $rule) }}" class="font-medium link link-hover">{{ $rule->name ?: __('Rule').' #'.$rule->id }}</a>
                                            <div class="text-xs opacity-60">{{ $ruleParts ? implode(' · ', $ruleParts) : __('Every product') }}</div>
                                            @if (isset($dead[$rule->id]))
                                                <form method="POST" action="{{ route('admin.points.rules.raise', $rule) }}" class="mt-1 flex flex-wrap items-center gap-2 text-xs text-warning">
                                                    @csrf
                                                    <span class="flex items-center gap-1"><x-icon name="triangle-alert" size="sm" />
                                                        {{ __('Never reached: ":name" above already decides for the same goods.', ['name' => $dead[$rule->id]->name ?: __('Rule').' #'.$dead[$rule->id]->id]) }}</span>
                                                    <button class="btn btn-sm">{{ __('Put it above') }}</button>
                                                </form>
                                            @endif
                                        </td>
                                        <td data-cell="earn" class="text-sm whitespace-nowrap">
                                            @switch($rule->earn_mode)
                                                @case('multiplier') ×{{ $ruleNumber($rule->earn_value) }} @break
                                                @case('percent') {{ $ruleNumber($rule->earn_value) }}% {{ __('of the price') }} @break
                                                @case('fixed') +{{ $ruleNumber($rule->earn_value) }} {{ __('per piece') }} @break
                                                @case('none') {{ __('Nothing') }} @break
                                                @default <span class="opacity-40">—</span>
                                            @endswitch
                                        </td>
                                        <td data-cell="spend" class="text-sm whitespace-nowrap">
                                            @switch($rule->spend_mode)
                                                @case('allowed') {{ __('Allowed') }} @break
                                                @case('limit') {{ __('up to :percent%', ['percent' => (int) $rule->spend_percent]) }} @break
                                                @case('forbidden') {{ __('Not allowed') }} @break
                                                @default <span class="opacity-40">—</span>
                                            @endswitch
                                        </td>
                                        <td data-cell="status">
                                            <span @class(['badge badge-sm', 'badge-success' => $rule->enabled, 'badge-ghost' => ! $rule->enabled])>{{ $rule->enabled ? __('On') : __('Off') }}</span>
                                        </td>
                                        <td data-cell="actions" class="text-end whitespace-nowrap">
                                            <div class="inline-flex items-center gap-1">
                                                <a href="{{ route('admin.points.rules.edit', $rule) }}" class="btn btn-ghost btn-sm btn-square"
                                                   title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                                <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                                <form method="POST" action="{{ route('admin.points.rules.destroy', $rule) }}" class="inline"
                                                      data-confirm="{{ __('Delete this rule? Orders already placed keep what they were to earn.') }}">
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

        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <h3 class="font-semibold">{{ __('Customer groups') }}</h3>
                <p class="text-xs opacity-60">{{ __('Who buys, not what: a group\'s multiplier multiplies whatever the product and the rules came to. ×2 earns double, ×0 earns nothing.') }}</p>

                @foreach ($groups as $group)
                    @continue(! isset($groupMultipliers[$group->id]))
                    <div class="flex items-center justify-between gap-2 text-sm">
                        <span class="min-w-0 truncate">{{ $group->t('name') }}</span>
                        <span class="flex items-center gap-1">
                            <span class="badge badge-sm badge-ghost">×{{ $ruleNumber($groupMultipliers[$group->id]) }}</span>
                            <form method="POST" action="{{ route('admin.points.groups.destroy', $group->id) }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" size="sm" /></button>
                            </form>
                        </span>
                    </div>
                @endforeach

                @if ($groups->isNotEmpty())
                    <form method="POST" action="{{ route('admin.points.groups.store') }}" class="flex flex-col gap-2 border-t border-base-300 pt-3">
                        @csrf
                        <select name="group_id" class="select select-bordered w-full" aria-label="{{ __('Customer group') }}">
                            @foreach ($groups as $group)
                                <option value="{{ $group->id }}">{{ $group->t('name') }}</option>
                            @endforeach
                        </select>
                        <label class="input input-bordered w-full">
                            <span class="opacity-50">×</span>
                            <input name="multiplier" type="number" step="0.01" min="0" max="100" required class="grow" value="2" aria-label="{{ __('Multiplier') }}">
                        </label>
                        <button class="btn btn-sm">{{ __('Save') }}</button>
                    </form>
                @endif
            </div>
        </section>
    </div>
@endsection
