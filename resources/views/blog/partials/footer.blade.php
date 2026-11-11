<footer class="site-footer outer">
<div class="site-footer-content inner">
<section class="copyright"><a href="{{ config('blog.url') }}">{{ $blogSettings['title'] }}</a> © {{ now()->year }}</section>
<nav class="site-footer-nav">
<a href="{{ config('blog.url') }}">Latest Posts</a>
@if(!empty($blogSettings['twitter_url']))<a href="{{ $blogSettings['twitter_url'] }}" rel="noopener" target="_blank">Twitter</a>@endif
<a href="https://github.com/TryGhost/Casper" rel="noopener" target="_blank">Casper</a>
</nav>
</div>
</footer>
