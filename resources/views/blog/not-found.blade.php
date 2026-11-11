@extends('blog.layout')
@section('content')
<header class="site-header"><div class="outer site-nav-main"><div class="inner">@include('blog.partials.nav')</div></div></header>
<main class="site-main outer"><div class="inner"><section class="error-message"><h1 class="error-code">404</h1><p class="error-description">Page not found</p><a class="error-link" href="{{ config('blog.url') }}/">Go to the front page →</a></section></div></main>
@endsection
