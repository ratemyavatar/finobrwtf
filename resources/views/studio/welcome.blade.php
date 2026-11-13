@extends('studio.layout')

@section('title', 'Finobe Studio')

@section('head')
<style>
.side { float: left; width: 190px; padding: 18px 0 0 18px; }
.side a { display: block; padding: 9px 12px; border-radius: 4px; color: #2b2d31; font-weight: bold; margin-bottom: 4px; }
.side a.on, .side a:hover { background: #fff; color: #f82860; text-decoration: none; }
.main { margin-left: 210px; }
.hero { text-align: center; padding: 30px 10px 10px 10px; }
.hero img { height: 90px; }
.tpl .pic { background: #8fc6f0; }
</style>
@endsection

@section('body')
<div class="top">
    <div class="brand"><img src="/s/img/studio/logo.png" alt=""><span>Finobe Studio</span></div>
    <div class="who">
        @if ($user)
            <img src="{{ $avatar }}" alt="" onerror="this.onerror=null; this.src='/s/img/studio/logo.png'"><span class="name">{{ $user->username }}</span>
            <a class="btn grey small" href="#" onclick="document.getElementById('logout').submit(); return false;">Log out</a>
        @else
            <a class="btn" href="/ide/login">Log in</a>
        @endif
    </div>
</div>

@if ($user)
<form method="post" action="/ide/logout" id="logout" style="display: none">@csrf</form>
@endif

<div class="side">
    <a href="#new" class="on" onclick="show('new'); return false;" id="nav-new">New</a>
    @if ($user)
        <a href="#games" onclick="show('games'); return false;" id="nav-games">My Games</a>
    @endif
    <a href="https://{{ $domain }}/" target="_blank">Finobe.wtf</a>
</div>

<div class="main wrap">
    <div id="tab-new">
        <h2>New</h2>
        <div class="grid">
            @foreach ($templates as $template)
                <div class="card tpl" onclick="openTemplate('{{ $template['key'] }}')">
                    <div class="pic"><img src="/s/img/studio/tpl-{{ $template['key'] }}.png" alt=""></div>
                    <div class="txt"><div class="t">{{ $template['name'] }}</div><div class="s">Template</div></div>
                </div>
            @endforeach
        </div>
        @if (!$user)
            <div class="panel">
                <b>Log in to edit and publish your games.</b>
                <div class="muted">Use your Finobe email and password. You can still build and save places to your computer without logging in.</div>
                <div style="margin-top: 10px"><a class="btn" href="/ide/login">Log in</a></div>
            </div>
        @endif
    </div>

    @if ($user)
        <div id="tab-games" style="display: none">
            <h2>My Games</h2>
            @if (count($places) === 0)
                <div class="panel muted">You don't have any games yet. Start from a template, then use File &gt; Publish to Finobe As.</div>
            @endif
            <div class="grid">
                @foreach ($places as $place)
                    <div class="card" onclick="editPlace({{ $place['id'] }})" title="Edit {{ $place['title'] }}">
                        <div class="pic"><img src="{{ $place['thumbnail'] }}" alt="" onerror="this.style.visibility='hidden'"></div>
                        <div class="txt">
                            <div class="t">{{ $place['title'] }}</div>
                            <div class="s">{{ $place['updated'] }} <span class="tag">{{ $place['version'] }}</span></div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="muted">Saving a game from Studio sets it to the 2016 client.</div>
        </div>
    @endif
</div>

<script>
var BASE = 'https://www.{{ $domain }}';
function show(tab) {
    var tabs = ['new', 'games'];
    for (var i = 0; i < tabs.length; i++) {
        var el = document.getElementById('tab-' + tabs[i]);
        var nav = document.getElementById('nav-' + tabs[i]);
        if (el) { el.style.display = tabs[i] === tab ? 'block' : 'none'; }
        if (nav) { nav.className = tabs[i] === tab ? 'on' : ''; }
    }
}
function runScript(url) {
    if (!window.external || typeof window.external.StartGame === 'undefined') {
        alert('Open this page in Finobe Studio.');
        return;
    }
    window.external.StartGame('', '', url);
}
function editPlace(id) { runScript(BASE + '/game/edit.ashx?placeid=' + id); }
function openTemplate(key) { runScript(BASE + '/game/edit.ashx?template=' + key); }
if (window.location.hash === '#games') { show('games'); }
</script>
@endsection
