@extends('admin.layout')

@section('title', __('SKU templates'))

@section('content')
    @php
        $onCreateOptions = [
            'blank' => __('When the SKU is left blank'),
            'always' => __('Always — even over a SKU typed in'),
            'off' => __('Never — the template is used only by the renumbering'),
        ];
        // What a category inherits when it has no template of its own: the
        // nearest parent's, else the shop's.
        $skuInherited = function (int $categoryId) use ($tree, $own, $template): string {
            foreach ($tree->ancestors($categoryId) as $parentId) {
                if (($own[$parentId] ?? '') !== '') {
                    return $own[$parentId];
                }
            }

            return $template;
        };
        $skuTokens = [
            '{n:6}' => __('a running number, 6 digits'),
            '{id}' => __('the product\'s number'),
            '{category:3}' => __('the category\'s address in capitals, first 3'),
            '{brand:3}' => __('the brand\'s code, first 3'),
            '{year} {yy}' => __('this year'),
            '{rand:4}' => __('random letters and digits'),
            '{digits:4}' => __('random digits'),
            '{ean13}' => __('a whole EAN-13 for the shop\'s own barcodes'),
        ];
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.sku.save') }}" class="flex flex-col gap-6">
        @csrf

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-5">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('A product\'s SKU made by a template: the text as it is, and in braces what changes from product to product. A category may have a template of its own below; its subcategories take it too.') }}
                </p>

                <div class="grid lg:grid-cols-2 gap-6">
                    <div class="flex flex-col gap-4">
                        <label class="fieldset p-0">
                            <span class="fieldset-legend">{{ __('SKU template') }}</span>
                            <input name="template" value="{{ old('template', $template) }}" required maxlength="190" class="input input-bordered w-full font-mono">
                            <span class="text-xs opacity-60">{{ __('For example') }}: <span class="font-mono">{{ $example(old('template', $template)) }}</span></span>
                        </label>

                        <div class="fieldset p-0">
                            <span class="fieldset-legend">{{ __('Give a new product a SKU by the template') }}</span>
                            <div class="flex flex-col gap-2">
                                @foreach ($onCreateOptions as $optionKey => $optionLabel)
                                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                                        <input type="radio" name="on_create" value="{{ $optionKey }}" class="radio radio-sm" @checked(old('on_create', $onCreate) === $optionKey)>
                                        {{ $optionLabel }}
                                    </label>
                                @endforeach
                            </div>
                            <span class="text-xs opacity-60 leading-relaxed">{{ __('Blank is the safe choice: a SKU typed in, or brought by an import or 1C, is kept. "Always" suits a shop whose SKUs are only ever its own.') }}</span>
                        </div>
                    </div>

                    <div class="rounded-box bg-base-200/60 p-4 text-sm">
                        <div class="font-semibold mb-2">{{ __('In braces') }}</div>
                        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
                            @foreach ($skuTokens as $skuToken => $skuMeaning)
                                <dt class="font-mono">{{ $skuToken }}</dt>
                                <dd class="opacity-70">{{ $skuMeaning }}</dd>
                            @endforeach
                        </dl>
                        <p class="text-xs opacity-60 mt-3 leading-relaxed">{{ __('A running number belongs to its template: categories with the same template share one sequence, so no two products get the same SKU. EAN-13 starting with 2 is the range for a shop\'s own goods — for its scanner and labels, not for a marketplace.') }}</p>
                    </div>
                </div>
            </div>
        </div>

        @if ($categories->isNotEmpty())
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body gap-4">
                    <h2 class="font-semibold">{{ __('A template per category') }}</h2>
                    <p class="text-sm opacity-70 max-w-3xl">{{ __('Empty means the template above it: the parent category\'s, else the shop\'s. A product in several categories takes the deepest one.') }}</p>

                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('Category') }}</th>
                                    <th class="w-[40%]">{{ __('Template') }}</th>
                                    <th>{{ __('For example') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($categories as $skuCategory)
                                    @php
                                        $skuOwn = old('categories.'.$skuCategory->id, $own[$skuCategory->id] ?? '');
                                        $skuApplies = $skuOwn !== '' ? $skuOwn : $skuInherited($skuCategory->id);
                                    @endphp
                                    <tr>
                                        <td class="whitespace-nowrap">{{ $tree->indent($skuCategory) }}</td>
                                        <td>
                                            <input name="categories[{{ $skuCategory->id }}]" value="{{ $skuOwn }}" maxlength="190"
                                                   placeholder="{{ $skuInherited($skuCategory->id) }}"
                                                   class="input input-bordered input-sm w-full font-mono" aria-label="{{ __('Template') }}: {{ $skuCategory->t('name') }}">
                                        </td>
                                        <td class="font-mono text-xs opacity-70 whitespace-nowrap">{{ $example($skuApplies) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        <div>
            <button class="btn btn-primary gap-2"><x-icon name="check" /> {{ __('Save') }}</button>
        </div>
    </form>

    {{-- Beside the settings form, never inside it (CLAUDE.md: no nested forms). --}}
    <div class="card bg-base-100 border border-base-300 mt-6">
        <div class="card-body gap-4">
            <h2 class="font-semibold">{{ __('Renumber the products already in the shop') }}</h2>
            <p class="text-sm opacity-70 max-w-3xl">
                {{ __('Runs in the background and saves every product through the catalogue, so the audit keeps each old SKU. Save the templates first.') }}
            </p>

            @if ($parkedCount > 0)
                <div class="alert alert-warning text-sm max-w-3xl">{{ trans_choice('Renumbering stopped halfway: :count product still has a temporary SKU (~~…). Start "Clear every SKU" again to finish.|Renumbering stopped halfway: :count products still have a temporary SKU (~~…). Start "Clear every SKU" again to finish.', $parkedCount) }}</div>
            @endif

            <form method="POST" action="{{ route('admin.sku.renumber') }}" class="flex flex-col gap-4 max-w-2xl"
                  data-confirm="{{ __('Change the SKUs now? An import, 1C or a marketplace that knows the old SKUs will not find these products by them.') }}">
                @csrf
                <div class="flex flex-col gap-2">
                    <label class="flex items-start gap-2 text-sm cursor-pointer">
                        <input type="radio" name="scope" value="fallback" class="radio radio-sm mt-0.5" checked>
                        <span>
                            {{ __('Only the products nobody gave a SKU (P-…)') }}
                            <span class="badge badge-sm ml-1">{{ $fallbackCount }}</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2 text-sm cursor-pointer">
                        <input type="radio" name="scope" value="all" class="radio radio-sm mt-0.5">
                        <span>{{ __('Every product, a SKU typed by hand included — the running number goes on') }}</span>
                    </label>
                    <label class="flex items-start gap-2 text-sm cursor-pointer">
                        <input type="radio" name="scope" value="restart" class="radio radio-sm mt-0.5">
                        <span>
                            {{ __('Clear every SKU and number all the products again from 1') }}
                            <span class="block text-xs opacity-60">{{ __('The whole shop, whatever category is chosen below: the old SKUs are freed first, so the numbering comes out without holes and in the order the products were added.') }}</span>
                        </span>
                    </label>
                </div>

                <label class="fieldset p-0">
                    <span class="fieldset-legend">{{ __('In the category') }}</span>
                    <select name="category" class="select select-bordered w-full">
                        <option value="">{{ __('Every category') }}</option>
                        @foreach ($categories as $skuCategory)
                            <option value="{{ $skuCategory->id }}">{{ $tree->indent($skuCategory) }}</option>
                        @endforeach
                    </select>
                    <span class="text-xs opacity-60">{{ __('With everything under it.') }}</span>
                </label>

                <div>
                    <button class="btn gap-2"><x-icon name="list-restart" /> {{ __('Renumber') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
