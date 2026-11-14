@extends('studio.layout')

@section('title', 'Published Projects - Finobe Studio')

@section('head')
<style>
body { background: #fff; }
.dialog { padding: 16px 20px; }
.dialog h1 { font-size: 24px; margin-bottom: 4px; }
.dialog .sub { font-size: 14px; margin-bottom: 14px; }
.tile .meta a { font-size: 12px; }
</style>
@endsection

@section('body')
<div class="dialog">
    <h1>Published Projects</h1>
    @if (!$user)
        <p class="sub text-secondary">You need to be logged in to see your games.</p>
        <a class="btn-primary" href="/ide/login">Log In</a>
    @elseif (!$places)
        <p class="sub text-secondary">You haven't published any games yet.</p>
    @else
        <p class="sub text-secondary">Click a game to open it.</p>
        <div class="tiles">
            @foreach ($places as $place)
                <div class="tile" onclick="editPlace({{ $place['id'] }})" title="{{ $place['title'] }}">
                    <div class="thumb"><img src="{{ $place['thumbnail'] }}" alt="" onerror="this.style.visibility='hidden'"></div>
                    <div class="info">
                        <div class="title">{{ $place['title'] }}</div>
                        <div class="meta">Updated {{ $place['updated'] }} &middot; <a href="https://www.{{ $domain }}/place/{{ $place['id'] }}" target="_blank" onclick="event.stopPropagation()">Page</a></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
<script>
var BASE = 'https://www.{{ $domain }}';
function editPlace(id) {
    if (!window.external || typeof window.external.StartGame === 'undefined') { alert('Open this page in Finobe Studio.'); return; }
    window.external.StartGame('', '', BASE + '/game/edit.ashx?placeid=' + id);
    window.close();
}
</script>
@endsection
