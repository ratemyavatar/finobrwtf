@extends('studio.layout')

@section('title', 'Two step check - Finobe Studio')

@section('head')
<style>
.box { width: 360px; margin: 50px auto 0 auto; }
.box .logo { text-align: center; margin-bottom: 18px; }
.box .logo img { height: 80px; }
</style>
@endsection

@section('body')
<div class="box">
    <div class="logo"><img src="/s/img/studio/wordmark.png" alt="Finobe"></div>
    <div class="panel">
        <h2>Two step check</h2>
        @if ($error)
            <div class="error">{{ $error }}</div>
        @endif
        <div class="muted">Type the code from your authenticator app, or one of your backup codes.</div>
        <form method="post" action="/ide/login/2fa">
            @csrf
            <label for="code">Code</label>
            <input type="text" name="code" id="code" autocomplete="off" autofocus>
            <div style="margin-top: 16px; overflow: hidden">
                <button class="btn" type="submit" style="float: right">Continue</button>
                <a class="btn grey" href="/ide/login">Back</a>
            </div>
        </form>
    </div>
</div>
@endsection
