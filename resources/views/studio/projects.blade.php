@extends('studio.layout')

@section('title', 'Published projects - Finobe Studio')

@section('body')
<div class="top">
    <div class="brand"><img src="/s/img/studio/logo.png" alt=""><span>Published projects</span></div>
</div>
<div class="wrap">
    @if (!$user)
        <div class="panel">
            <b>Log in to see your games.</b>
            <div class="muted">Open the Start Page and log in with your Finobe account, then try again.</div>
            <div style="margin-top: 10px"><a class="btn" href="/ide/login">Log in</a></div>
        </div>
    @elseif (!$places)
        <div class="panel muted">You haven't made any games yet.</div>
    @else
        <div class="muted" style="margin-bottom: 12px">Click a game to open it.</div>
        <div class="grid">
            @foreach ($places as $place)
                <div class="card" onclick="editPlace({{ $place['id'] }})" title="Edit {{ $place['title'] }}">
                    <div class="pic"><img src="{{ $place['thumbnail'] }}" alt="" onerror="this.style.visibility='hidden'"></div>
                    <div class="txt">
                        <div class="t">{{ $place['title'] }}</div>
                        <div class="s">{{ $place['updated'] }} <span class="tag">{{ $place['version'] }}</span> <a href="https://www.{{ $domain }}/place/{{ $place['id'] }}" onclick="event.stopPropagation()">Page</a></div>
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
