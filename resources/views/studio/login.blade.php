@extends('studio.layout')

@section('title', 'Log in - Finobe Studio')

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
        <h2>Log in to Finobe Studio</h2>
        @if ($error)
            <div class="error">{{ $error }}</div>
        @endif
        <form method="post" action="/ide/login">
            @csrf
            <label for="email">Email</label>
            <input type="email" name="email" id="email" autofocus>
            <label for="password">Password</label>
            <input type="password" name="password" id="password">
            <div style="margin-top: 16px; overflow: hidden">
                <button class="btn" type="submit" style="float: right">Log in</button>
                <a class="btn grey" href="/ide/welcome">Back</a>
            </div>
        </form>
    </div>
    <div class="muted" style="text-align: center">No account? Sign up at <a href="https://{{ $domain }}/register" target="_blank">{{ $domain }}</a>.</div>
</div>
@endsection
