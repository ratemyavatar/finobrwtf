<?php
use App\Http\Controllers\Blog\AdminController;
use Illuminate\Support\Facades\Route;
Route::get('/',[AdminController::class,'index'])->name('admin.blog.index');
Route::get('/posts/new',[AdminController::class,'edit']);
Route::post('/posts',[AdminController::class,'save'])->middleware('throttle:60,1');
Route::get('/posts/{id}/edit',[AdminController::class,'edit'])->whereNumber('id');
Route::post('/posts/{id}',[AdminController::class,'save'])->whereNumber('id')->middleware('throttle:60,1');
Route::post('/posts/{id}/archive',[AdminController::class,'archive'])->whereNumber('id');
Route::get('/posts/{id}/preview',[AdminController::class,'preview'])->whereNumber('id');
Route::post('/posts/{id}/revisions/{revision}',[AdminController::class,'revision'])->whereNumber(['id','revision']);
Route::get('/authors',[AdminController::class,'authors']);
Route::post('/authors',[AdminController::class,'authorSave']);
Route::post('/authors/{id}',[AdminController::class,'authorSave'])->whereNumber('id');
Route::get('/tags',[AdminController::class,'tags']);
Route::post('/tags',[AdminController::class,'tagSave']);
Route::post('/tags/{id}',[AdminController::class,'tagSave'])->whereNumber('id');
Route::get('/settings',[AdminController::class,'settings']);
Route::post('/settings',[AdminController::class,'settingsSave']);
Route::post('/images',[AdminController::class,'upload'])->middleware('throttle:20,1');

Route::post('/authors/{id}/delete',[AdminController::class,'authorDelete'])->whereNumber('id');
Route::post('/tags/{id}/delete',[AdminController::class,'tagDelete'])->whereNumber('id');
