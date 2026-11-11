<?php
namespace App\Models\Blog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
class Post extends Model {
    use SoftDeletes;
    protected $connection='finobe';protected $table='blog_posts';protected $guarded=['id'];
    protected function casts():array{return ['published_at'=>'datetime'];}
    public function author(){return $this->belongsTo(Author::class,'author_id');}
    public function tags(){return $this->belongsToMany(Tag::class,'blog_post_tags','post_id','tag_id');}
    public function scopeVisible($q){return $q->whereIn('status',['published','scheduled'])->whereNotNull('published_at')->where('published_at','<=',now());}
    public function getPathAttribute():string{return '/'.($this->published_at??$this->created_at??now())->format('Y/m/d').'/'.$this->slug.'/';}
    public function getUrlAttribute():string{return config('blog.url').$this->path;}
    public function getSummaryAttribute():string{return $this->excerpt?:Str::words(html_entity_decode(strip_tags($this->html)),50,'');}
    public function getReadingTimeAttribute():int{return max(1,(int)ceil(str_word_count(strip_tags($this->html))/200));}
}
