@extends('studio.layout')

@section('title', '2-Step Verification - Finobe Studio')

@section('head')
<style>
.login { width: 340px; margin: 40px auto 0 auto; }
.login .brand { text-align: center; margin-bottom: 20px; }
.login .brand img { height: 70px; }
.login h1 { text-align: center; margin-bottom: 12px; }
.login p { text-align: center; font-size: 14px; margin: 0 0 16px 0; }
.login .links { text-align: center; font-size: 14px; margin-top: 14px; }
</style>
@endsection

@section('body')
<div class="login">
    <div class="brand"><img src="/s/img/studio/wordmark.png" alt="Finobe"></div>
    <h1>2-Step Verification</h1>
    <p class="text-secondary">Enter the code from your authenticator app, or one of your backup codes.</p>
    @if ($error)
        <div class="alert alert-warning">{{ $error }}</div>
    @endif
    <form method="post" action="/ide/login/2fa">
        @csrf
        <div style="margin-bottom: 12px"><input class="input-field" type="text" name="code" id="code" placeholder="Enter Code" autocomplete="off" autofocus></div>
        <button class="btn-primary btn-full" type="submit">Verify</button>
    </form>
    <div class="links"><a href="/ide/login">Cancel</a></div>
</div>
@endsection
