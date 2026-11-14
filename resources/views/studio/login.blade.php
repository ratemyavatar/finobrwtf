@extends('studio.layout')

@section('title', 'Log In - Finobe Studio')

@section('head')
<style>
.login { width: 340px; margin: 40px auto 0 auto; }
.login .brand { text-align: center; margin-bottom: 20px; }
.login .brand img { height: 70px; }
.login h1 { text-align: center; margin-bottom: 18px; }
.login .form-group { margin-bottom: 12px; }
.login .links { text-align: center; font-size: 14px; margin-top: 14px; }
.login .signup { text-align: center; font-size: 14px; margin-top: 24px; border-top: 1px solid #e3e3e3; padding-top: 14px; }
</style>
@endsection

@section('body')
<div class="login">
    <div class="brand"><img src="/s/img/studio/wordmark.png" alt="Finobe"></div>
    <h1>Login to Finobe</h1>
    @if ($error)
        <div class="alert alert-warning">{{ $error }}</div>
    @endif
    <form method="post" action="/ide/login">
        @csrf
        <div class="form-group"><input class="input-field" type="email" name="email" id="email" placeholder="Email" autofocus></div>
        <div class="form-group"><input class="input-field" type="password" name="password" id="password" placeholder="Password"></div>
        <button class="btn-primary btn-full" type="submit">Log In</button>
    </form>
    <div class="links"><a href="https://www.{{ $domain }}/password/reset" target="_blank">Forgot Password?</a> &middot; <a href="/ide/welcome">Cancel</a></div>
    <div class="signup text-secondary">Don't have an account? <a href="https://www.{{ $domain }}/register" target="_blank">Sign Up</a></div>
</div>
@endsection
