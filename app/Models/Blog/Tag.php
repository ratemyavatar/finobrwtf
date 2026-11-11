<?php
namespace App\Models\Blog;
use Illuminate\Database\Eloquent\Model;
class Tag extends Model {
    protected $connection='finobe';protected $table='blog_tags';protected $guarded=['id'];
    public function posts(){return $this->belongsToMany(Post::class,'blog_post_tags','tag_id','post_id');}
    public function getUrlAttribute():string{return config('blog.url').'/tag/'.$this->slug.'/';}
}
