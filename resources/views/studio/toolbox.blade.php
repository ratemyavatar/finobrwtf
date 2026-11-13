@extends('studio.layout')

@section('title', 'Toolbox')

@section('head')
<style>
body { background: #fff; font-size: 13px; }
.bar { padding: 8px; background: #f3f4f7; border-bottom: 1px solid #dcdfe5; }
.bar select { margin-bottom: 6px; }
.bar .row { overflow: hidden; }
.bar .row input { float: left; width: 75%; }
.bar .row .btn { float: right; width: 23%; padding: 7px 0; text-align: center; }
#items { padding: 8px 4px 8px 8px; overflow: hidden; }
.item { float: left; width: 76px; margin: 0 4px 8px 0; cursor: pointer; text-align: center; }
.item .pic { width: 72px; height: 72px; border: 1px solid #dcdfe5; border-radius: 4px; background: #eceef2; overflow: hidden; margin: 0 auto; }
.item .pic img { width: 70px; height: 70px; }
.item .n { font-size: 11px; height: 28px; overflow: hidden; line-height: 14px; margin-top: 2px; word-wrap: break-word; }
.item:hover .pic { border-color: #f82860; }
.item.off { cursor: default; opacity: 0.5; }
.item.off:hover .pic { border-color: #dcdfe5; }
.pager { text-align: center; padding: 4px 0 10px 0; clear: both; }
.pager span { margin: 0 8px; color: #5b5e66; }
.empty { padding: 20px 10px; text-align: center; color: #7b7f88; }
.hint { padding: 6px 8px 0 8px; font-size: 12px; color: #7b7f88; display: none; }
.status { padding: 6px 8px; font-size: 12px; color: #2a7a3a; display: none; }
</style>
@endsection

@section('body')
<div class="bar">
    <select id="category" onchange="load(1)">
        @foreach ($categories as $category)
            <option value="{{ $category['key'] }}">{{ $category['name'] }}</option>
        @endforeach
    </select>
    <div class="row">
        <input type="text" id="q" placeholder="Search" onkeydown="if (event.keyCode === 13) { load(1); }">
        <button class="btn small" onclick="load(1)">Go</button>
    </div>
</div>
<div class="status" id="status"></div>
<div class="hint" id="hint">Select a part first, then click a decal to put it on the part.</div>
<div id="items"><div class="empty">Loading...</div></div>
<div class="pager" id="pager"></div>
<script>
var page = 1, pages = 1, current = [];
function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function load(p) {
    page = p;
    var cat = document.getElementById('category').value;
    document.getElementById('hint').style.display = /decals$/.test(cat) ? 'block' : 'none';
    var q = document.getElementById('q').value;
    var x = new XMLHttpRequest();
    x.open('GET', '/ide/toolbox/items?category=' + encodeURIComponent(cat) + '&q=' + encodeURIComponent(q) + '&page=' + p + '&r=' + Math.random(), true);
    x.onreadystatechange = function () {
        if (x.readyState !== 4) { return; }
        if (x.status !== 200) { document.getElementById('items').innerHTML = '<div class="empty">Could not load the toolbox.</div>'; return; }
        render(JSON.parse(x.responseText));
    };
    x.send(null);
}
function render(data) {
    current = data.items; page = data.page; pages = data.pages;
    var html = '';
    for (var i = 0; i < current.length; i++) {
        var it = current[i];
        var label = it.pending ? 'Waiting for review' : (it.usable ? 'Insert ' + it.name : 'Buy this on the site to use it');
        html += '<div class="item' + (it.usable ? '' : ' off') + '" title="' + esc(label) + '" ondragstart="return drag(' + i + ')" onclick="insert(' + i + ')">'
            + '<div class="pic"><img src="' + esc(it.thumbnail) + '" alt="" onerror="this.style.visibility=\'hidden\'"></div>'
            + '<div class="n">' + esc(it.name) + '</div></div>';
    }
    document.getElementById('items').innerHTML = html || '<div class="empty">Nothing here.</div>';
    document.getElementById('pager').innerHTML = pages > 1
        ? '<button class="btn grey small" onclick="load(' + Math.max(1, page - 1) + ')"' + (page <= 1 ? ' disabled' : '') + '>&lt;</button>'
          + '<span>' + page + ' / ' + pages + '</span>'
          + '<button class="btn grey small" onclick="load(' + Math.min(pages, page + 1) + ')"' + (page >= pages ? ' disabled' : '') + '>&gt;</button>'
        : '';
}
function drag(i) {
    var it = current[i];
    if (it && it.usable && window.external && typeof window.external.StartDrag !== 'undefined') { window.external.StartDrag(it.insert); }
    return false;
}
function insert(i) {
    var it = current[i];
    if (!it || !it.usable) { return; }
    if (!window.external || typeof window.external.Insert === 'undefined') { alert('Open the toolbox in Finobe Studio.'); return; }
    window.external.Insert(it.insert);
    var s = document.getElementById('status');
    s.innerHTML = 'Inserted ' + esc(it.name);
    s.style.display = 'block';
    setTimeout(function () { s.style.display = 'none'; }, 2500);
}
load(1);
</script>
@endsection
