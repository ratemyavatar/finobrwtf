@extends('studio.layout')

@section('title', 'Publish selection - Finobe Studio')

@section('body')
<div class="top">
    <div class="brand"><img src="/s/img/studio/logo.png" alt=""><span>Publish selection to Finobe</span></div>
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
        <div id="done" class="note" style="display: none"></div>
        <div class="panel" id="form">
            <div class="muted">Your selection is published as a model. A moderator checks it before it shows up in the toolbox.</div>
            <label for="name">Name</label>
            <input type="text" id="name" maxlength="255">
            <label for="description">Description</label>
            <textarea id="description" maxlength="8192"></textarea>
            <div style="margin-top: 14px; overflow: hidden">
                <button class="btn" id="go" style="float: right" onclick="publish()">Publish</button>
            </div>
        </div>
    @endif
</div>
<script>
var BASE = 'https://www.{{ $domain }}';
var TOKEN = '{{ $token }}';
function show(id, msg) { var e = document.getElementById(id); e.innerHTML = msg; e.style.display = 'block'; }
function publish() {
    var name = document.getElementById('name').value;
    var description = document.getElementById('description').value;
    if (name.length < 3) { show('error', 'The name must be at least 3 characters.'); return; }
    if (!window.external || typeof window.external.WriteSelection === 'undefined') { show('error', 'Open this page in Finobe Studio.'); return; }
    document.getElementById('go').disabled = true;
    var content = window.external.WriteSelection();
    if (!content) { show('error', 'Select something to publish first.'); document.getElementById('go').disabled = false; return; }
    var result = content.Upload(BASE + '/data/newasset.ashx?type=10&name=' + encodeURIComponent(name) + '&description=' + encodeURIComponent(description) + '&token=' + TOKEN);
    if (/^\d+$/.test(String(result))) {
        document.getElementById('form').style.display = 'none';
        document.getElementById('error').style.display = 'none';
        show('done', 'Published as model ' + result + '. It shows up in My Models now and in the toolbox once a moderator approves it. <a href="#" onclick="window.close(); return false">Close</a>');
    } else {
        show('error', String(result || 'Could not publish your selection.'));
        document.getElementById('go').disabled = false;
    }
}
</script>
@endsection
