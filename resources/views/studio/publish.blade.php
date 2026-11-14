@extends('studio.layout')

@section('title', 'Publish - Finobe Studio')

@section('head')
<style>
body { background: #fff; }
.dialog { padding: 16px 20px; }
.dialog h1 { font-size: 24px; margin-bottom: 4px; }
.dialog .sub { font-size: 14px; margin-bottom: 14px; }
.tile .thumb.new { background: #fff; border-bottom: 1px solid #e3e3e3; text-align: center; line-height: 113px; font-size: 56px; font-weight: 300; color: #00a2ff; }
#form-new { display: none; max-width: 520px; }
.footer { border-top: 1px solid #e3e3e3; margin-top: 8px; padding-top: 14px; overflow: hidden; }
.footer .btn-primary, .footer .btn-secondary { float: right; margin-left: 8px; min-width: 110px; }
.footer .msg { float: left; line-height: 36px; font-size: 14px; }
</style>
@endsection

@section('body')
<div class="dialog">
    <h1>Publish to Finobe</h1>
    @if (!$user)
        <p class="sub text-secondary">You need to be logged in to publish.</p>
        <a class="btn-primary" href="/ide/login">Log In</a>
    @else
        <p class="sub text-secondary">Choose a game to overwrite, or create a new one. Publishing sets the game to the 2016 client.</p>
        <div id="error" class="alert alert-warning" style="display: none"></div>
        <div class="tiles">
            @if ($canCreate)
                <div class="tile" id="card-new" onclick="pick(0)">
                    <div class="thumb new">+</div>
                    <div class="info"><div class="title">Create New Game</div><div class="meta">Uses a place slot</div></div>
                </div>
            @endif
            @foreach ($places as $place)
                <div class="tile" id="card-{{ $place['id'] }}" onclick="pick({{ $place['id'] }})" title="{{ $place['title'] }}">
                    <div class="thumb"><img src="{{ $place['thumbnail'] }}" alt="" onerror="this.style.visibility='hidden'"></div>
                    <div class="info"><div class="title">{{ $place['title'] }}</div><div class="meta">Updated {{ $place['updated'] }}</div></div>
                </div>
            @endforeach
        </div>
        @if (!$canCreate)
            <p class="text-secondary text-small">All of your place slots are used, so you can only overwrite one of your games.</p>
        @endif
        <div id="form-new">
            <h3>Basic Settings</h3>
            <label class="form-label" for="title">Name</label>
            <input class="input-field" type="text" id="title" maxlength="255">
            <label class="form-label" for="description">Description</label>
            <textarea class="input-field" id="description" maxlength="8192"></textarea>
        </div>
        <div class="footer">
            <button class="btn-secondary" id="close" onclick="window.close()">Cancel</button>
            <button class="btn-primary" id="go" onclick="publish()" disabled>Publish</button>
            <span class="msg text-secondary" id="busy"></span>
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
        if (old) { old.className = 'tile'; }
    }
    picked = id;
    var el = document.getElementById('card-' + (id === 0 ? 'new' : id));
    if (el) { el.className = 'tile selected'; }
    document.getElementById('form-new').style.display = id === 0 ? 'block' : 'none';
    document.getElementById('go').innerHTML = id === 0 ? 'Create' : 'Overwrite';
    document.getElementById('go').disabled = false;
}
function fail(msg) {
    var e = document.getElementById('error');
    e.innerHTML = msg; e.style.display = 'block';
    document.getElementById('busy').innerHTML = '';
    document.getElementById('go').disabled = false;
}
function save(url) {
    if (!window.external || typeof window.external.SaveUrl === 'undefined') { fail('Open this page in Finobe Studio.'); return; }
    window.external.SaveUrl(url);
    document.getElementById('busy').innerHTML = 'Your game was published.';
    document.getElementById('go').style.display = 'none';
    document.getElementById('close').innerHTML = 'Close';
}
function publish() {
    if (picked === null) { return; }
    document.getElementById('go').disabled = true;
    document.getElementById('busy').innerHTML = 'Publishing...';
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
        if (x.status !== 200 || !r || !r.upload) { fail((r && r.error) ? r.error : 'Could not create the game.'); return; }
        save(r.upload);
    };
    x.send('title=' + encodeURIComponent(document.getElementById('title').value) + '&description=' + encodeURIComponent(document.getElementById('description').value));
}
</script>
@endsection
