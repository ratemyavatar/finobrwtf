@extends('admin.blog.layout')
@section('title','Blog settings')
@section('content')
<form class="card card-body mb-4" method="post" action="/admin/blog/settings">@csrf @foreach(['title'=>'Blog title','description'=>'Description','logo'=>'Logo image URL','cover'=>'Cover image URL','twitter_url'=>'Twitter link (optional)'] as $key=>$label)<div class="form-group"><label>{{ $label }}<input class="form-control" name="{{ $key }}" value="{{ old($key,$settings[$key]) }}" @required($key!=='twitter_url')></label></div>@endforeach<button class="btn btn-primary align-self-start">Save settings</button></form>
@endsection
