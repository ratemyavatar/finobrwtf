<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Administration') - Finobe</title>
<link rel="icon" href="/s/img/finnobe3logo.png">
<link rel="stylesheet" href="/s/css/{{ in_array($actor->theme, ['1', 1, 'night'], true) ? 'night' : 'light' }}.css">

@if(request()->is('admin/users*'))
<link rel="stylesheet" href="/s/admin/workbench.css">
@endif
<link rel="stylesheet" href="/s/css/all.min.css">
<script src="/s/admin/jquery.min.js"></script>
<script src="/s/admin/bootstrap.bundle.min.js"></script>

@if(request()->is('admin/users*') || request()->is('admin/servers*'))
<link rel="stylesheet" href="/s/admin/classic-panels.css?v={{ filemtime(public_path('s/admin/classic-panels.css')) }}">
@endif
</head>
<body class="finobe-admin">
{!! $navbarHtml !!}
<main class="container mt-4">@yield('content')</main>
@yield('scripts')
</body></html>
