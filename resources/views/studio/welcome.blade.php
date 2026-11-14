@extends('studio.layout')

@section('title', 'Finobe Studio')

@section('head')
<style>
.nav { position: absolute; top: 51px; left: 0; bottom: 0; width: 200px; background: #fff; border-right: 1px solid #e3e3e3; padding-top: 12px; }
.nav a { display: block; height: 40px; line-height: 40px; padding-left: 20px; color: #191919; font-size: 16px; border-left: 4px solid transparent; }
.nav a:hover { background: #f2f2f2; text-decoration: none; }
.nav a.active { border-left-color: #00a2ff; color: #00a2ff; background: #f2f2f2; }
.nav .divider { height: 1px; background: #e3e3e3; margin: 12px 0; }
.main { margin-left: 200px; }
.empty { padding: 30px 0; text-align: center; }
</style>
@endsection

@section('body')
<div class="header">
    <a class="logo" href="/ide/welcome"><img src="/s/img/studio/logo.png" alt=""><span>Finobe Studio</span></a>
    <div class="right">
        @if ($user)
            <img class="avatar" src="{{ $avatar }}" alt="" onerror="this.onerror=null; this.src='/s/img/studio/logo.png'"><span class="name">{{ $user->username }}</span>
            <a class="btn-control" href="#" onclick="document.getElementById('logout').submit(); return false;">Log Out</a>
        @else
            <a class="btn-control" href="/ide/login">Log In</a>
        @endif
    </div>
</div>
@if ($user)
<form method="post" action="/ide/logout" id="logout" style="display: none">@csrf</form>
@endif

<div class="nav">
    <a href="#new" class="active" onclick="show('new'); return false;" id="nav-new">New</a>
    <a href="#games" onclick="show('games'); return false;" id="nav-games">My Games</a>
    <div class="divider"></div>
    <a href="https://www.{{ $domain }}/" target="_blank">Finobe.wtf</a>
</div>

<div class="main content">
    <div id="tab-new">
        <h2>New Project</h2>
        <h3 class="text-secondary">Themes</h3>
        <div class="tiles">
            @foreach ($templates as $template)
                <div class="tile" onclick="openTemplate('{{ $template['key'] }}')" title="{{ $template['name'] }}">
                    <div class="thumb"><img src="/s/img/studio/tpl-{{ $template['key'] }}.png" alt=""></div>
                    <div class="info"><div class="title">{{ $template['name'] }}</div></div>
                </div>
            @endforeach
        </div>
    </div>

    <div id="tab-games" style="display: none">
        <h2>My Games</h2>
        @if (!$user)
            <div class="section empty">
                <p>Log in to see your games.</p>
                <a class="btn-primary" href="/ide/login">Log In</a>
            </div>
        @elseif (count($places) === 0)
            <div class="section empty text-secondary">You don't have any games yet. Start a new project, then use File &gt; Publish to Finobe As.</div>
        @else
            <div class="tiles">
                @foreach ($places as $place)
                    <div class="tile" onclick="editPlace({{ $place['id'] }})" title="{{ $place['title'] }}">
                        <div class="thumb"><img src="{{ $place['thumbnail'] }}" alt="" onerror="this.style.visibility='hidden'"></div>
                        <div class="info">
                            <div class="title">{{ $place['title'] }}</div>
                            <div class="meta">Updated {{ $place['updated'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

<script>
var BASE = 'https://www.{{ $domain }}';
function show(tab) {
    var tabs = ['new', 'games'];
    for (var i = 0; i < tabs.length; i++) {
        document.getElementById('tab-' + tabs[i]).style.display = tabs[i] === tab ? 'block' : 'none';
        document.getElementById('nav-' + tabs[i]).className = tabs[i] === tab ? 'active' : '';
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
