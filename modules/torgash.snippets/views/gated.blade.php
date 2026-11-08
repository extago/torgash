{{-- A snippet of a category the visitor is asked about (PLAN §49) — a personal
     part of the page. Not allowed yet, it goes out inside a template element:
     the browser parses it and runs nothing, and consent.js moves it into the
     page the moment the visitor agrees, on this page, without a reload. --}}
@php $gatedCode = app(\App\Core\Http\Csp::class)->sign($code); @endphp
@if (\App\Core\Privacy\Consent::allows($category))
{!! $gatedCode !!}
@else
<template data-consent="{{ $category }}">{!! $gatedCode !!}</template>
@endif
