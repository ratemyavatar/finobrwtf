@extends('studio.layout')

@section('title', 'Toolbox')

@section('head')
<style>
body { background: #fff; font-size: 14px; }
.bar { padding: 8px; background: #f2f2f2; border-bottom: 1px solid #e3e3e3; }
.bar select { margin-bottom: 6px; height: 32px; font-size: 14px; }
.bar .search { position: relative; }
.bar .search input { height: 32px; font-size: 14px; padding-right: 64px; }
.bar .search .btn-control { position: absolute; right: 0; top: 0; height: 32px; border-radius: 0 3px 3px 0; }
#items { padding: 8px 2px 4px 8px; overflow: hidden; }
.item { float: left; width: 78px; margin: 0 4px 8px 0; cursor: pointer; text-align: center; }
.item .pic { width: 74px; height: 74px; border: 1px solid #e3e3e3; background: #fff; overflow: hidden; margin: 0 auto; }
.item .pic img { width: 72px; height: 72px; }
.item .n { font-size: 12px; height: 30px; overflow: hidden; line-height: 15px; margin-top: 3px; word-wrap: break-word; color: #191919; }
.item:hover .pic { border-color: #00a2ff; }
.item.off { cursor: default; }
.item.off .pic img { opacity: 0.4; }
.item.off:hover .pic { border-color: #e3e3e3; }
.pager { text-align: center; padding: 4px 0 10px 0; clear: both; font-size: 14px; }
.pager span { margin: 0 10px; color: #757575; }
.empty { padding: 24px 10px; text-align: center; color: #757575; }
.hint { padding: 6px 8px 0 8px; font-size: 12px; color: #757575; display: none; }
.status { padding: 6px 8px; font-size: 12px; color: #fff; background: #02b757; display: none; }
</style>
@endsection

@section('body')
<div class="bar">
    <select class="input-field" id="category" onchange="load(1)">
        @foreach ($categories as $category)
            <option value="{{ $category['key'] }}">{{ $category['name'] }}</option>
        @endforeach
    </select>
    <div class="search">
        <input class="input-field" type="text" id="q" placeholder="Search" onkeydown="if (event.keyCode === 13) { load(1); }">
        <button class="btn-control" onclick="load(1)">Search</button>
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
        ? (page > 1 ? '<a href="#" onclick="load(' + (page - 1) + '); return false;">&lt; Previous</a>' : '<span>&lt; Previous</span>')
          + '<span>Page ' + page + ' of ' + pages + '</span>'
          + (page < pages ? '<a href="#" onclick="load(' + (page + 1) + '); return false;">Next &gt;</a>' : '<span>Next &gt;</span>')
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
