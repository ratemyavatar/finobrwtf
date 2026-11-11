<article class="post-full post {{ $entry->feature_image ? '' : 'no-image' }}">
<header class="post-full-header">
@if($entry->tags->isNotEmpty())<section class="post-full-tags">@foreach($entry->tags as $tag)<a href="{{ $tag->url }}">{{ $tag->name }}</a>@if(!$loop->last) / @endif @endforeach</section>@endif
<h1 class="post-full-title">{{ $entry->title }}</h1>
<div class="post-full-byline">
<section class="post-full-byline-content">
<ul class="author-list">
<li class="author-list-item">
<div class="author-card">
<div class="author-profile-image">@if($entry->author->avatar)<img alt="{{ $entry->author->name }}" src="{{ $entry->author->avatar }}"/>@else<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><g fill="none" fill-rule="evenodd"><path d="M3.513 18.998C4.749 15.504 8.082 13 12 13s7.251 2.504 8.487 5.998C18.47 21.442 15.417 23 12 23s-6.47-1.558-8.487-4.002zM12 12c2.21 0 4-2.79 4-5s-1.79-4-4-4-4 1.79-4 4 1.79 5 4 5z" fill="#FFF"></path></g></svg>@endif</div>
<div class="author-info">
<h2>{{ $entry->author->name }}</h2>
<p>Read <a href="{{ $entry->author->url }}">more posts</a> by this author.</p>
</div>
</div>
<a class="author-avatar author-profile-image" href="{{ $entry->author->url }}">@if($entry->author->avatar)<img alt="{{ $entry->author->name }}" src="{{ $entry->author->avatar }}"/>@else<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><g fill="none" fill-rule="evenodd"><path d="M3.513 18.998C4.749 15.504 8.082 13 12 13s7.251 2.504 8.487 5.998C18.47 21.442 15.417 23 12 23s-6.47-1.558-8.487-4.002zM12 12c2.21 0 4-2.79 4-5s-1.79-4-4-4-4 1.79-4 4 1.79 5 4 5z" fill="#FFF"></path></g></svg>@endif</a>
</li>
</ul>
<section class="post-full-byline-meta">
<h4 class="author-name"><a href="{{ $entry->author->url }}">{{ $entry->author->name }}</a></h4>
<div class="byline-meta-content">
<time class="byline-meta-date" datetime="{{ $entry->published_at?->format('Y-m-d') }}">{{ $entry->published_at?->format('j M Y') ?? 'Draft' }}</time>
<span class="byline-reading-time"><span class="bull">•</span> {{ $entry->reading_time }} min read</span>
</div>
</section>
</section>
</div>
</header>
@if($entry->feature_image)<figure class="post-full-image"><img alt="{{ $entry->image_alt ?: $entry->title }}" src="{{ $entry->feature_image }}"/></figure>@endif<section class="post-full-content">
<div class="post-content">{!! $entry->html !!}</div>
</section>
</article>
