<article class="post-card post {{ $entry->feature_image ? '' : 'no-image' }}">@if($entry->feature_image)<a class="post-card-image-link" href="{{ $entry->url }}">
<img alt="{{ $entry->image_alt ?: $entry->title }}" class="post-card-image" loading="lazy" src="{{ $entry->feature_image }}"/>
</a>@endif
<div class="post-card-content">
<a class="post-card-content-link" href="{{ $entry->url }}">
<header class="post-card-header">
@if($entry->tags->isNotEmpty())<span class="post-card-primary-tag">{{ $entry->tags->first()->name }}</span>@endif
<h2 class="post-card-title">{{ $entry->title }}</h2>
</header>
<section class="post-card-excerpt">
<p>{{ $entry->summary }}</p>
</section>
</a>
<footer class="post-card-meta">
<ul class="author-list">
<li class="author-list-item">
<div class="author-name-tooltip">{{ $entry->author->name }}</div>
<a class="static-avatar author-profile-image" href="{{ $entry->author->url }}">@if($entry->author->avatar)<img alt="{{ $entry->author->name }}" src="{{ $entry->author->avatar }}"/>@else<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><g fill="none" fill-rule="evenodd"><path d="M3.513 18.998C4.749 15.504 8.082 13 12 13s7.251 2.504 8.487 5.998C18.47 21.442 15.417 23 12 23s-6.47-1.558-8.487-4.002zM12 12c2.21 0 4-2.79 4-5s-1.79-4-4-4-4 1.79-4 4 1.79 5 4 5z" fill="#FFF"></path></g></svg>@endif</a>
</li>
</ul>
<div class="post-card-byline-content">
<span><a href="{{ $entry->author->url }}">{{ $entry->author->name }}</a></span>
<span class="post-card-byline-date"><time datetime="{{ $entry->published_at->format('Y-m-d') }}">{{ $entry->published_at->format('j M Y') }}</time> <span class="bull">•</span> {{ $entry->reading_time }} min read</span>
</div>
</footer>
</div>
</article>
