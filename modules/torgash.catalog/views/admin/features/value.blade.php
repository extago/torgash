@extends('admin.layout')

@section('title', 'Brand page: '.$value->display())

@section('content')
    <form method="POST"
          action="{{ route('admin.catalog.features.values.update', [$feature, $value]) }}"
          enctype="multipart/form-data"
          class="flex flex-col gap-4 max-w-3xl">
        @csrf @method('PUT')

        <div class="card bg-base-100 shadow-sm"><div class="card-body gap-4">
            @if ($errors->any())
                <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
            @endif

            <div>
                <div class="text-xs font-semibold uppercase tracking-wide opacity-50">{{ $feature->t('name') }}</div>
                <h1 class="text-xl font-bold">{{ $value->display() }}</h1>
            </div>

            <fieldset class="fieldset p-0"><legend class="fieldset-legend">Slug</legend>
                <input name="slug" value="{{ old('slug', $value->slug) }}" class="input input-bordered w-full"
                       placeholder="auto from name">
            </fieldset>
            <div class="text-xs opacity-50 -mt-2">Page URL: {{ url('/brand/'.($value->slug ?: '…')) }}</div>

            <x-admin.translatable-field name="description" label="Description" :value="$value->description ?? []" textarea rich :rows="4" placeholder="Shown on the brand page" />

            <div>
                <div class="text-sm opacity-60 mb-1.5">Logo</div>
                @if ($value->logoUrl())
                    <img src="{{ $value->logoUrl() }}" class="h-16 w-16 object-contain rounded-lg border border-base-300 mb-2 bg-base-200 p-1">
                @endif
                <input name="logo" type="file" accept="image/*" class="file-input file-input-bordered w-full">
            </div>

        </div></div>

        <x-seo.fields :record="$value"
                      :url-preview="$value->slug ? url('/brand/'.$value->slug) : null" />

        <div class="flex gap-2">
            <button class="btn btn-primary">Save</button>
            <a href="{{ route('admin.catalog.features.edit', $feature) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
@endsection
