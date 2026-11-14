<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>@yield('title', 'Finobe Studio')</title>
@if (config('session.secure'))
<script>if (window.location.protocol === 'http:') { window.location.replace('https:' + window.location.href.substring(5)); }</script>
@endif
<style>
@font-face { font-family: 'SSP'; src: url('/fonts/TTF/SourceSansPro-Light.ttf'); font-weight: 300; }
@font-face { font-family: 'SSP'; src: url('/fonts/TTF/SourceSansPro-Regular.ttf'); font-weight: 400; }
@font-face { font-family: 'SSP'; src: url('/fonts/TTF/SourceSansPro-Semibold.ttf'); font-weight: 600; }
* { box-sizing: border-box; -webkit-box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { background: #f2f2f2; color: #191919; font-family: 'SSP', 'Segoe UI', Arial, sans-serif; font-size: 16px; font-weight: 400; line-height: 1.3; }
a { color: #00a2ff; text-decoration: none; }
a:hover { text-decoration: underline; }
h1, h2, h3 { font-weight: 300; margin: 0; color: #191919; }
h1 { font-size: 32px; }
h2 { font-size: 24px; margin-bottom: 12px; }
h3 { font-size: 20px; margin-bottom: 8px; }
.text-secondary { color: #757575; }
.text-small { font-size: 14px; }
.text-error { color: #d86868; }
.header { background: #fff; height: 50px; border-bottom: 1px solid #e3e3e3; padding: 0 16px; }
.header .logo { float: left; height: 50px; line-height: 50px; color: #191919; text-decoration: none; }
.header .logo img { height: 32px; vertical-align: middle; }
.header .logo span { font-size: 20px; font-weight: 300; margin-left: 8px; vertical-align: middle; }
.header .right { float: right; height: 50px; line-height: 50px; font-size: 14px; }
.header .right .avatar { width: 30px; height: 30px; border-radius: 15px; vertical-align: middle; margin-right: 6px; background: #e3e3e3; }
.header .right .name { font-weight: 600; margin-right: 12px; vertical-align: middle; }
.btn-primary, .btn-secondary, .btn-control { display: inline-block; font-family: inherit; font-size: 16px; font-weight: 400; line-height: 100%; padding: 9px 15px; border-radius: 3px; cursor: pointer; text-align: center; text-decoration: none; white-space: nowrap; }
.btn-primary { background: #00a2ff; border: 1px solid #00a2ff; color: #fff; }
.btn-primary:hover { background: #32b5ff; border-color: #32b5ff; text-decoration: none; }
.btn-secondary { background: #fff; border: 1px solid #b8b8b8; color: #191919; }
.btn-secondary:hover { border-color: #191919; text-decoration: none; }
.btn-control { background: #fff; border: 1px solid #b8b8b8; color: #191919; font-size: 14px; padding: 6px 10px; }
.btn-control:hover { border-color: #191919; text-decoration: none; }
.btn-primary[disabled], .btn-primary.disabled { background: #a3e3ff; border-color: #a3e3ff; cursor: default; }
.btn-full { display: block; width: 100%; }
.section { background: #fff; padding: 15px; margin-bottom: 18px; box-shadow: 0 1px 4px 0 rgba(25, 25, 25, 0.3); border-radius: 3px; }
.input-field { display: block; width: 100%; height: 38px; padding: 5px 12px; font-family: inherit; font-size: 16px; color: #191919; background: #fff; border: 1px solid #b8b8b8; border-radius: 3px; }
textarea.input-field { height: 80px; resize: vertical; padding-top: 8px; }
.input-field:focus { border-color: #00a2ff; outline: 0; }
select.input-field { padding: 5px 8px; }
.form-label { display: block; font-size: 14px; font-weight: 600; margin: 12px 0 5px 0; }
.alert { padding: 8px 12px; font-size: 14px; border-radius: 3px; margin-bottom: 12px; }
.alert-warning { background: #f68802; color: #fff; }
.alert-info { background: #fff; border: 1px solid #e3e3e3; color: #191919; }
.alert-success { background: #02b757; color: #fff; }
.content { padding: 18px 20px; }
.tiles { overflow: hidden; }
.tile { float: left; width: 200px; margin: 0 16px 16px 0; background: #fff; border-radius: 3px; box-shadow: 0 1px 4px 0 rgba(25, 25, 25, 0.3); cursor: pointer; overflow: hidden; }
.tile:hover { box-shadow: 0 1px 6px 0 rgba(25, 25, 25, 0.75); }
.tile .thumb { width: 200px; height: 113px; background: #e3e3e3; }
.tile .thumb img { width: 200px; height: 113px; display: block; }
.tile .info { padding: 6px 8px 8px 8px; }
.tile .title { font-size: 16px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tile .meta { font-size: 12px; color: #757575; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tile.selected { box-shadow: 0 0 0 2px #00a2ff; }
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
