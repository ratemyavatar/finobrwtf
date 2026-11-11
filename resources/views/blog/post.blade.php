@extends('blog.layout')
@section('content')
<header class="site-header"><div class="outer site-nav-main"><div class="inner">@include('blog.partials.nav')</div></div></header>
<main id="site-main" class="site-main outer"><div class="inner">@include('blog.partials.article')</div></main>
@if($related->isNotEmpty())<aside class="read-next outer"><div class="inner"><div class="read-next-feed">@foreach($related as $relatedEntry) @include('blog.partials.card',['entry'=>$relatedEntry]) @endforeach</div></div></aside>@endif
@endsection
