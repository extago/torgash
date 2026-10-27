{{-- A layout whose header draws a position BEFORE the page's content and
     stack are printed: what a broken part of the header must not take with it. --}}
<header>{!! hook_render('test.fragment.zone') !!}</header>
<main>@yield('content')</main>
<footer>@stack('fragment-scripts')</footer>
