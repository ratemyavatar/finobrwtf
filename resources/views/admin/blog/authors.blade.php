@extends('admin.blog.layout')
@section('title','Blog authors')
@section('content')
@foreach($authors->prepend(new \App\Models\Blog\Author()) as $author)
<details class="card mb-3" @if(!$author->exists) open @endif><summary class="card-header">{{ $author->exists?$author->name:'New author' }}</summary><div class="card-body"><form method="post" action="/admin/blog/authors{{ $author->exists?'/'.$author->id:'' }}">@csrf
@foreach(['name'=>'Name','slug'=>'Slug','bio'=>'Bio','avatar'=>'Avatar image URL','website'=>'Website','location'=>'Location'] as $key=>$label)<div class="form-group"><label>{{ $label }}<input class="form-control" name="{{ $key }}" value="{{ $author->{$key} }}" @required(in_array($key,['name','slug'])) @if($key==='slug') pattern="[a-z0-9]+(-[a-z0-9]+)*" @endif></label></div>@endforeach<button class="btn btn-primary">Save author</button></form>@if($author->exists)<form class="mt-3" method="post" action="/admin/blog/authors/{{ $author->id }}/delete" data-confirm="Delete this author? Authors with posts cannot be deleted.">@csrf<button class="btn btn-danger btn-sm">Delete author</button></form>@endif</div></details>
@endforeach
@endsection
