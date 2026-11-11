<?php
namespace App\Blog;
use App\Models\Blog\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class Publisher {
    public static function html(string $markdown):string {
        return Str::markdown($markdown,['html_input'=>'strip','allow_unsafe_links'=>false]);
    }
    public function save(array $data,$actor,?int $id=null):Post {
        return DB::connection('finobe')->transaction(function()use($data,$actor,$id){
            $post=$id?Post::withTrashed()->lockForUpdate()->findOrFail($id):new Post();
            abort_if($post->trashed(),422,'Restore this post before editing it.');
            if($id)abort_unless((int)$data['version']===(int)$post->version,409,'This post changed since you opened it. Reload before saving.');
            $before=$id?$post->toArray():null;$oldPath=$id?$post->path:null;
            unset($data['version']);$tags=$data['tags']??[];unset($data['tags']);
            $post->fill($data);$post->html=self::html($post->markdown);$post->version=$id?$post->version+1:1;
            if($post->status==='published'&&!$post->published_at)$post->published_at=now();
            if($post->status==='scheduled')abort_unless($post->published_at,422,'Scheduled posts need a publication date.');
            $post->save();$post->tags()->sync($tags);$post->load('tags');
            if($id&&$oldPath!==$post->path)DB::connection('finobe')->table('blog_redirects')->updateOrInsert(['path'=>$oldPath],['post_id'=>$post->id]);
            DB::connection('finobe')->table('blog_revisions')->insert(['post_id'=>$post->id,'actor_id'=>$actor->id,'version'=>$post->version,'snapshot'=>json_encode($post->toArray(),JSON_THROW_ON_ERROR),'created_at'=>now()]);
            self::audit($actor,$id?'blog.update':'blog.create',$post->id,$id?'Update blog post':'Create blog post',$before,$post->toArray());
            return $post;
        });
    }
    public static function audit($actor,string $action,$id,string $label,$before,$after):void {
        DB::connection('finobe')->table('admin_audit')->insert(['actor_id'=>$actor->id,'actor_name'=>$actor->username,'action'=>$action,'label'=>$label,'target_type'=>'blog','target_id'=>$id,'reason'=>$label,'before'=>json_encode($before,JSON_THROW_ON_ERROR),'after'=>json_encode($after,JSON_THROW_ON_ERROR),'request_id'=>(string)Str::uuid(),'created_at'=>now()]);
    }
}
