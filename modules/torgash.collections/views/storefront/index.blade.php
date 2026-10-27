@extends('layouts.storefront')

@section('title', __('Collections').' · '.current_storefront()?->name)

@section('content')
    <x-breadcrumbs />
    <h1 class="text-2xl font-bold mb-4">{{ __('Collections') }}</h1>

    @if ($collections->isEmpty())
        <p class="opacity-70">{{ __('No collections yet.') }}</p>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($collections as $collectionCard)
                @include('torgash.collections::storefront.card', ['collectionCard' => $collectionCard])
            @endforeach
        </div>
    @endif
@endsection
