@extends('studio.layout')

@section('title', 'Publish Selection - Finobe Studio')

@section('head')
<style>
body { background: #fff; }
.dialog { padding: 16px 20px; max-width: 560px; }
.dialog h1 { font-size: 24px; margin-bottom: 4px; }
.dialog .sub { font-size: 14px; margin-bottom: 10px; }
.footer { border-top: 1px solid #e3e3e3; margin-top: 16px; padding-top: 14px; overflow: hidden; }
.footer .btn-primary, .footer .btn-secondary { float: right; margin-left: 8px; min-width: 110px; }
</style>
@endsection

@section('body')
<div class="dialog">
    <h1>Publish Selection to Finobe</h1>
    @if (!$user)
        <p class="sub text-secondary">You need to be logged in to publish.</p>
        <a class="btn-primary" href="/ide/login">Log In</a>
    @else
        <p class="sub text-secondary">Your selection is saved as a model. A moderator reviews it before it shows up in the toolbox.</p>
        <div id="error" class="alert alert-warning" style="display: none"></div>
        <div id="done" class="alert alert-success" style="display: none"></div>
        <div id="form">
            <label class="form-label" for="name">Name</label>
            <input class="input-field" type="text" id="name" maxlength="255">
            <label class="form-label" for="description">Description</label>
            <textarea class="input-field" id="description" maxlength="8192"></textarea>
        </div>
        <div class="footer">
            <button class="btn-secondary" id="close" onclick="window.close()">Cancel</button>
            <button class="btn-primary" id="go" onclick="publish()">Publish</button>
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
        document.getElementById('go').style.display = 'none';
        document.getElementById('close').innerHTML = 'Close';
        show('done', 'Published as model ' + result + '. It shows up in My Models now and in the toolbox once a moderator approves it.');
    } else {
        show('error', String(result || 'Could not publish your selection.'));
        document.getElementById('go').disabled = false;
    }
}
</script>
@endsection
