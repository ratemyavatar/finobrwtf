@extends('blog.layout')
@section('content')
@if($kind === 'home') @include('blog.partials.home-header')
@else
<header class="site-archive-header"><div class="outer site-nav-main"><div class="inner">@include('blog.partials.nav')</div></div><div class="outer site-header-background no-image"><div class="inner">
<div class="site-header-content {{ $kind === 'author' ? 'author-header' : '' }}">
@if($kind === 'author')<span class="author-profile-image">@if($archive->avatar)<img src="{{ $archive->avatar }}" alt="{{ $archive->name }}">@else @include('blog.partials.avatar') @endif</span>@endif
<div class="author-header-content"><h1 class="site-title">{{ $archive->name }}</h1>
@if($kind === 'author' && $archive->bio)<h2 class="author-bio">{{ $archive->bio }}</h2>@elseif($kind === 'tag' && $archive->description)<h2 class="site-description">{{ $archive->description }}</h2>@endif
<div class="author-meta"><div class="author-stats">{{ $posts->total() }} {{ $posts->total() === 1 ? 'post' : 'posts' }}</div>@if($kind === 'author' && $archive->location)<span class="author-location">{{ $archive->location }}</span>@endif @if($kind === 'author' && $archive->website)<a class="author-link" href="{{ $archive->website }}" rel="noopener">Website</a>@endif</div></div></div></div></div></header>
@endif
<main id="site-main" class="site-main outer"><div class="inner posts"><div class="post-feed">@forelse($posts as $entry) @include('blog.partials.card') @empty<p>No posts yet.</p>@endforelse</div>
<noscript><nav aria-label="Pages">@if($previous)<a href="{{ $previous }}">Newer posts</a>@endif @if($next)<a href="{{ $next }}">Older posts</a>@endif</nav></noscript>
</div></main>
@if($kind === 'home')<div class="outer site-nav-main"><div class="inner">@include('blog.partials.nav')</div></div>@endif
@endsection
