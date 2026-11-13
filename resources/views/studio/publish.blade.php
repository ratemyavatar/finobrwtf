@extends('studio.layout')

@section('title', 'Publish - Finobe Studio')

@section('head')
<style>
.list { overflow: hidden; }
.card.new .pic { background: #f82860; color: #fff; font-size: 44px; text-align: center; line-height: 96px; font-weight: bold; }
.card.on { border-color: #f82860; box-shadow: 0 0 0 2px #f82860; }
#form-new { display: none; }
.foot { overflow: hidden; margin-top: 6px; }
.foot .btn { float: right; margin-left: 8px; }
#busy { display: none; }
</style>
@endsection

@section('body')
<div class="top">
    <div class="brand"><img src="/s/img/studio/logo.png" alt=""><span>Publish to Finobe</span></div>
</div>
<div class="wrap">
    @if (!$user)
        <div class="panel">
            <b>Log in to publish.</b>
            <div class="muted">Open the Start Page and log in with your Finobe account, then try again.</div>
            <div style="margin-top: 10px"><a class="btn" href="/ide/login">Log in</a></div>
        </div>
    @else
        <div id="error" class="error" style="display: none"></div>
        <div class="note">Publishing replaces the game's place file and sets the game to the 2016 client.</div>
        <div class="list">
            @if ($canCreate)
                <div class="card new" id="card-new" onclick="pick(0)">
                    <div class="pic">+</div>
                    <div class="txt"><div class="t">New game</div><div class="s">Uses a place slot</div></div>
                </div>
            @endif
            @foreach ($places as $place)
                <div class="card" id="card-{{ $place['id'] }}" onclick="pick({{ $place['id'] }})">
                    <div class="pic"><img src="{{ $place['thumbnail'] }}" alt="" onerror="this.style.visibility='hidden'"></div>
                    <div class="txt"><div class="t">{{ $place['title'] }}</div><div class="s">{{ $place['updated'] }} <span class="tag">{{ $place['version'] }}</span></div></div>
                </div>
            @endforeach
        </div>
        @if (!$canCreate)
            <div class="muted" style="margin-bottom: 10px">You have used all of your place slots, so you can only overwrite one of your games.</div>
        @endif
        <div class="panel" id="form-new">
            <label for="title">Name</label>
            <input type="text" id="title" maxlength="255">
            <label for="description">Description</label>
            <textarea id="description" maxlength="8192"></textarea>
        </div>
        <div class="foot">
            <button class="btn" id="go" onclick="publish()" disabled>Publish</button>
            <button class="btn grey" id="close" onclick="window.close()" style="display: none">Close</button>
            <span id="busy" class="muted">Publishing...</span>
        </div>
    @endif
</div>
<script>
var BASE = 'https://www.{{ $domain }}';
var TOKEN = '{{ $token }}';
var picked = null;
function pick(id) {
    if (picked !== null) {
        var old = document.getElementById('card-' + (picked === 0 ? 'new' : picked));
        if (old) { old.className = old.className.replace(' on', ''); }
    }
    picked = id;
    var el = document.getElementById('card-' + (id === 0 ? 'new' : id));
    if (el) { el.className += ' on'; }
    document.getElementById('form-new').style.display = id === 0 ? 'block' : 'none';
    document.getElementById('go').disabled = false;
}
function fail(msg) {
    var e = document.getElementById('error');
    e.innerHTML = msg; e.style.display = 'block';
    document.getElementById('busy').style.display = 'none';
    document.getElementById('go').disabled = false;
}
function save(url) {
    if (!window.external || typeof window.external.SaveUrl === 'undefined') { fail('Open this page in Finobe Studio.'); return; }
    window.external.SaveUrl(url);
    document.getElementById('busy').innerHTML = 'Published.';
    document.getElementById('go').style.display = 'none';
    document.getElementById('close').style.display = 'inline-block';
}
function publish() {
    if (picked === null) { return; }
    document.getElementById('go').disabled = true;
    document.getElementById('busy').style.display = 'inline';
    if (picked !== 0) {
        save(BASE + '/data/upload.ashx?assetid=' + picked + '&token=' + TOKEN);
        return;
    }
    var x = new XMLHttpRequest();
    x.open('POST', '/ide/places/new', true);
    x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    x.setRequestHeader('X-CSRF-TOKEN', '{{ csrf_token() }}');
    x.onreadystatechange = function () {
        if (x.readyState !== 4) { return; }
        var r = null;
        try { r = JSON.parse(x.responseText); } catch (e) {}
        if (x.status !== 200 || !r || !r.upload) { fail((r && r.error) ? r.error : 'Could not make the game.'); return; }
        save(r.upload);
    };
    x.send('title=' + encodeURIComponent(document.getElementById('title').value) + '&description=' + encodeURIComponent(document.getElementById('description').value));
}
</script>
@endsection
