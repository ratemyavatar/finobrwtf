<?php
namespace App\Models\Blog;
use Illuminate\Database\Eloquent\Model;
class Author extends Model {
    protected $connection='finobe';protected $table='blog_authors';protected $guarded=['id'];
    public function posts(){return $this->hasMany(Post::class,'author_id');}
    public function getUrlAttribute():string{return config('blog.url').'/author/'.$this->slug.'/';}
}
