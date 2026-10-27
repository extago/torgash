@extends('admin.layout')

@section('title', __('Add a review'))
@section('own_heading', '1')

{{-- A review written somewhere else — on Google, by phone, on the old site —
     typed in by the staff with its own date and where it came from. Published
     at once: whoever types it is the moderator. --}}
@section('content')
    <form method="POST" action="{{ route('admin.shopreviews.store') }}" class="flex flex-col gap-4">
        @csrf

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.shopreviews.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to reviews') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ __('Shop reviews') }}</div>
                <div class="font-semibold truncate leading-tight">{{ __('Add a review') }}</div>
            </div>
            <a href="{{ route('admin.shopreviews.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ __('Create') }}</button>
        </div>

        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-4">
                    <h3 class="font-semibold">{{ __('The review') }}</h3>
                    <div class="grid gap-3 md:grid-cols-2">
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Author') }}</legend>
                            <input name="author_name" value="{{ old('author_name') }}" required maxlength="120" class="input input-bordered w-full">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Town') }}</legend>
                            <input name="city" value="{{ old('city') }}" maxlength="120" class="input input-bordered w-full">
                        </fieldset>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Pros') }}</legend>
                            <textarea name="pros" rows="2" maxlength="2000" class="textarea textarea-bordered w-full">{{ old('pros') }}</textarea>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Cons') }}</legend>
                            <textarea name="cons" rows="2" maxlength="2000" class="textarea textarea-bordered w-full">{{ old('cons') }}</textarea>
                        </fieldset>
                    </div>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Text') }}</legend>
                        <textarea name="body" rows="6" required maxlength="5000" class="textarea textarea-bordered w-full">{{ old('body') }}</textarea>
                    </fieldset>
                </div>
            </section>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Rating') }}</h3>
                        @if ($criteria->isEmpty())
                            <select name="rating" class="select select-bordered w-full" required>
                                <option value=""></option>
                                @foreach ([5, 4, 3, 2, 1] as $stars)
                                    <option value="{{ $stars }}" @selected((int) old('rating') === $stars)>{{ str_repeat('★', $stars) }} {{ $stars }}</option>
                                @endforeach
                            </select>
                        @else
                            @foreach ($criteria as $srCriterion)
                                <label class="flex items-center justify-between gap-3">
                                    <span class="text-sm">{{ $srCriterion->label() }}</span>
                                    <select name="criteria[{{ $srCriterion->id }}]" class="select select-bordered select-sm w-28">
                                        <option value="">—</option>
                                        @foreach ([5, 4, 3, 2, 1] as $stars)
                                            <option value="{{ $stars }}" @selected((int) old('criteria.'.$srCriterion->id) === $stars)>{{ $stars }} ★</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                            <p class="text-xs opacity-60">{{ __('The overall mark is the average of the criteria filled in.') }}</p>
                        @endif
                        @foreach (['recommend' => __('Recommends the shop'), 'will_return' => __('Will buy again')] as $srField => $srText)
                            <label class="flex items-center justify-between gap-3">
                                <span class="text-sm">{{ $srText }}</span>
                                <select name="{{ $srField }}" class="select select-bordered select-sm w-28">
                                    <option value="">—</option>
                                    <option value="1" @selected(old($srField) === '1')>{{ __('Yes') }}</option>
                                    <option value="0" @selected(old($srField) === '0')>{{ __('No') }}</option>
                                </select>
                            </label>
                        @endforeach
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Where it came from') }}</h3>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Source') }}</legend>
                            <input name="source" value="{{ old('source') }}" maxlength="60" class="input input-bordered w-full" placeholder="Google">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Printed beside the review ("From Google"). Empty — written on this site.') }}</p>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Written on') }}</legend>
                            <input type="date" name="written_at" value="{{ old('written_at') }}" max="{{ now()->toDateString() }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Empty — today.') }}</p>
                        </fieldset>
                    </div>
                </section>
            </div>
        </div>
    </form>
@endsection
