<header class="site-home-header">
<style type="text/css">
    .responsive-header-img {
        background-image: url("{{ $blogSettings['cover'] }}");
    }

    @media(max-width: 1000px) {
        .responsive-header-img {
            background-image: url("{{ $blogSettings['cover'] }}");
            background-image: -webkit-image-set(url("{{ $blogSettings['cover'] }}") 1x,
                url("{{ $blogSettings['cover'] }}") 2x);
            background-image: image-set(url("{{ $blogSettings['cover'] }}") 1x,
                url("{{ $blogSettings['cover'] }}") 2x);
        }
    }

    @media(max-width: 600px) {
        .responsive-header-img {
            background-image: url("{{ $blogSettings['cover'] }}");
            background-image: -webkit-image-set(url("{{ $blogSettings['cover'] }}") 1x,
                url("{{ $blogSettings['cover'] }}") 2x);
            background-image: image-set(url("{{ $blogSettings['cover'] }}") 1x,
                url("{{ $blogSettings['cover'] }}") 2x);
        }
    }
</style>
<div class="outer site-header-background responsive-header-img">
<div class="inner">
@include('blog.partials.nav')
<div class="site-header-content">
<h1 class="site-title">
<img alt="{{ $blogSettings['title'] }}" class="site-logo" src="{{ $blogSettings['logo'] }}"/>
</h1>
<h2 class="site-description">{{ $blogSettings['description'] }}</h2>
</div>
</div>
</div>
</header>
