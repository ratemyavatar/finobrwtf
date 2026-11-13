<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>@yield('title', 'Finobe Studio')</title>
@if (config('session.secure'))
<script>if (window.location.protocol === 'http:') { window.location.replace('https:' + window.location.href.substring(5)); }</script>
@endif
<style>
@font-face { font-family: 'SSP'; src: url('/fonts/OTF/SourceSansPro-Regular.otf'); font-weight: normal; }
@font-face { font-family: 'SSP'; src: url('/fonts/OTF/SourceSansPro-Semibold.otf'); font-weight: bold; }
* { box-sizing: border-box; -webkit-box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { background: #eceef2; color: #2b2d31; font-family: 'SSP', 'Segoe UI', Arial, sans-serif; font-size: 14px; }
a { color: #f82860; text-decoration: none; }
a:hover { text-decoration: underline; }
.top { background: #2b2d31; height: 52px; padding: 0 18px; color: #fff; }
.top .brand { float: left; height: 52px; line-height: 52px; }
.top .brand img { height: 34px; vertical-align: middle; }
.top .brand span { font-size: 18px; font-weight: bold; margin-left: 8px; vertical-align: middle; color: #fff; }
.top .who { float: right; height: 52px; line-height: 52px; }
.top .who img { width: 32px; height: 32px; border-radius: 16px; vertical-align: middle; background: #555; margin-right: 8px; }
.top .who form { display: inline; margin-left: 4px; }
.top .who .name { color: #fff; font-weight: bold; margin-right: 12px; }
.btn { display: inline-block; border: 0; border-radius: 4px; padding: 7px 16px; font-family: inherit; font-size: 14px; font-weight: bold; cursor: pointer; background: #f82860; color: #fff; line-height: 18px; }
.btn:hover { background: #e01e52; text-decoration: none; }
.btn.grey { background: #5b5e66; }
.btn.grey:hover { background: #4a4d54; }
.btn.small { padding: 4px 10px; font-size: 12px; }
.btn[disabled] { background: #b9bcc3; cursor: default; }
.wrap { padding: 18px 22px; }
h2 { font-size: 20px; margin: 6px 0 12px 0; font-weight: bold; }
.panel { background: #fff; border-radius: 6px; padding: 16px; margin-bottom: 18px; border: 1px solid #dcdfe5; }
.muted { color: #7b7f88; }
.error { background: #fde4ea; color: #b0143e; border: 1px solid #f7b6c7; border-radius: 4px; padding: 8px 10px; margin-bottom: 12px; }
.note { background: #eef3ff; color: #2a4a8a; border: 1px solid #c9d7f5; border-radius: 4px; padding: 8px 10px; margin-bottom: 12px; }
input[type=text], input[type=email], input[type=password], textarea, select { font-family: inherit; font-size: 14px; border: 1px solid #c9ccd3; border-radius: 4px; padding: 7px 9px; width: 100%; background: #fff; color: #2b2d31; }
textarea { height: 70px; resize: vertical; }
label { display: block; font-weight: bold; margin: 10px 0 4px 0; }
.grid { overflow: hidden; }
.card { float: left; width: 170px; margin: 0 14px 14px 0; background: #fff; border-radius: 6px; border: 1px solid #dcdfe5; overflow: hidden; cursor: pointer; }
.card:hover { border-color: #f82860; }
.card .pic { width: 170px; height: 96px; background: #d7dae0; display: block; }
.card .pic img { width: 170px; height: 96px; display: block; }
.card .txt { padding: 7px 9px; }
.card .t { font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.card .s { font-size: 12px; color: #7b7f88; }
.tag { display: inline-block; font-size: 11px; background: #eceef2; border-radius: 3px; padding: 0 5px; color: #5b5e66; }
.clear { clear: both; }
</style>
@yield('head')
</head>
<body>
@yield('body')
<script>
window.onload = function () {
    var b = document.body;
    b.style.visibility = 'hidden';
    b.offsetHeight;
    b.style.visibility = '';
};
</script>
</body>
</html>
