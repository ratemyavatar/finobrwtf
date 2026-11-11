<?php

use App\Http\Controllers\Blog\PublicController;
use Illuminate\Support\Facades\Route;
Route::get('/',[PublicController::class,'index'])->name('blog.home');
Route::get('/page/{page}',[PublicController::class,'index'])->whereNumber('page')->name('blog.page');
Route::get('/author/{slug}/page/{page}',[PublicController::class,'author'])->whereNumber('page')->name('blog.author.page');
Route::get('/author/{slug}/rss',[PublicController::class,'authorRss'])->name('blog.author.rss');
Route::get('/author/{slug}',[PublicController::class,'author'])->name('blog.author');
Route::get('/tag/{slug}/page/{page}',[PublicController::class,'tag'])->whereNumber('page')->name('blog.tag.page');
Route::get('/tag/{slug}/rss',[PublicController::class,'tagRss'])->name('blog.tag.rss');
Route::get('/tag/{slug}',[PublicController::class,'tag'])->name('blog.tag');
Route::get('/rss',[PublicController::class,'rss'])->name('blog.rss');
Route::get('/sitemap.xml',[PublicController::class,'sitemap'])->name('blog.sitemap');
Route::get('/{year}/{month}/{day}/{slug}',[PublicController::class,'post'])->where(['year'=>'[0-9]{4}','month'=>'[0-9]{2}','day'=>'[0-9]{2}'])->name('blog.post');
Route::fallback(fn()=>response()->view('blog.not-found',['kind'=>'error','canonical'=>config('blog.url'),'blogSettings'=>\App\Blog\Settings::all()],404))->name('blog.missing');
