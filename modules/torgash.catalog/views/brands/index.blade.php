@extends('layouts.storefront')

@section('content')
    <h1 class="text-2xl font-bold mb-4">{{ __('Brands') }}</h1>

    @if ($brands->isEmpty())
        <div class="alert">{{ __('No brands yet.') }}</div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
            @foreach ($brands as $brand)
                <a href="{{ route('catalog.brands.show', $brand->slug) }}"
                   class="card bg-base-100 shadow-sm hover:shadow-md transition-shadow">
                    <div class="card-body items-center text-center p-4 gap-2">
                        <div class="h-16 w-full flex items-center justify-center">
                            @if ($brand->logoUrl())
                                <img src="{{ $brand->logoUrl() }}" alt="{{ $brand->display() }}" class="max-h-16 object-contain">
                            @else
                                <span class="text-2xl font-bold opacity-20">{{ $brand->display() }}</span>
                            @endif
                        </div>
                        <div class="text-sm font-medium">{{ $brand->display() }}</div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
