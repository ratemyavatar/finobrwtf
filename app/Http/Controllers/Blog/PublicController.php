<?php

namespace App\Http\Controllers\Blog;
use App\Blog\Settings;
use App\Models\Blog\{Post,Author,Tag};
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PublicController extends Controller {
    private function posts(){return Post::visible()->with(['author'=>fn($q)=>$q->withCount(['posts'=>fn($q)=>$q->visible()]),'tags']);}
    public function index(Request $request,int $page=1){return $this->listing($this->posts(),$page,'home');}
    public function author(Request $request,string $slug,int $page=1){$author=Author::where('slug',$slug)->firstOrFail();return $this->listing($this->posts()->where('author_id',$author->id),$page,'author',$author);}
    public function tag(Request $request,string $slug,int $page=1){$tag=Tag::where('slug',$slug)->firstOrFail();return $this->listing($this->posts()->whereHas('tags',fn($q)=>$q->where('blog_tags.id',$tag->id)),$page,'tag',$tag);}
    private function listing($query,int $page,string $kind,$archive=null){
        abort_if($page<1||$page>100000,404);
        $posts=$query->orderByDesc('published_at')->orderByDesc('id')->paginate(config('blog.posts_per_page'),['*'],'page',$page);
        abort_if($page>1&&$posts->isEmpty(),404);
        $base=$kind==='home'?'/':'/'.$kind.'/'.$archive->slug.'/';
        $pagePath=$page===1?$base:$base.'page/'.$page.'/';
        return response()->view('blog.index',['posts'=>$posts,'kind'=>$kind,'archive'=>$archive,'blogSettings'=>Settings::all(),'canonical'=>config('blog.url').$pagePath,'base'=>$base,'pageNumber'=>$page,'feedUrl'=>config('blog.url').$base.'rss/',
            'next'=>$posts->hasMorePages()?config('blog.url').$base.'page/'.($page+1).'/':null,'previous'=>$page>1?config('blog.url').($page===2?$base:$base.'page/'.($page-1).'/'):null]);
    }
    public function post(Request $request,string $year,string $month,string $day,string $slug){
        $path='/'.$year.'/'.$month.'/'.$day.'/'.$slug.'/';
        $post=$this->posts()->where('slug',$slug)->first();
        if(!$post||$post->path!==$path){
            $redirect=DB::connection('finobe')->table('blog_redirects')->where('path',$path)->first();
            if($redirect&&$target=$this->posts()->find($redirect->post_id))return redirect()->away($target->url,301);
            abort(404);
        }
        return $this->article($post);
    }
    public function article(Post $post,bool $preview=false){
        $post->load(['author'=>fn($q)=>$q->withCount(['posts'=>fn($q)=>$q->visible()]),'tags']);
        return response()->view('blog.post',['entry'=>$post,'kind'=>'post','canonical'=>$post->url,'blogSettings'=>Settings::all(),'related'=>$this->posts()->where('id','!=',$post->id)->orderByDesc('published_at')->limit(3)->get(),'preview'=>$preview])
            ->header('Cache-Control',$preview?'private, no-store':'no-cache')->header('X-Robots-Tag',$preview?'noindex, nofollow':'all');
    }
    public function rss(){return $this->feed($this->posts());}
    public function authorRss(string $slug){$author=Author::where('slug',$slug)->firstOrFail();return $this->feed($this->posts()->where('author_id',$author->id));}
    public function tagRss(string $slug){$tag=Tag::where('slug',$slug)->firstOrFail();return $this->feed($this->posts()->whereHas('tags',fn($q)=>$q->where('blog_tags.id',$tag->id)));}
    private function feed($query){
        $settings=Settings::all();$doc=new \DOMDocument('1.0','UTF-8');$doc->formatOutput=true;
        $rss=$doc->appendChild($doc->createElement('rss'));$rss->setAttribute('version','2.0');$rss->setAttribute('xmlns:content','http://purl.org/rss/1.0/modules/content/');$rss->setAttribute('xmlns:dc','http://purl.org/dc/elements/1.1/');
        $channel=$rss->appendChild($doc->createElement('channel'));
        $text=function($parent,$key,$value)use($doc){$node=$parent->appendChild($doc->createElement($key));$node->appendChild($doc->createTextNode((string)$value));return $node;};
        $text($channel,'title',$settings['title']);$text($channel,'link',config('blog.url').'/');$text($channel,'description',$settings['description']);
        foreach($query->orderByDesc('published_at')->limit(25)->get() as $post){
            $item=$channel->appendChild($doc->createElement('item'));$text($item,'title',$post->title);$text($item,'link',$post->url);$guid=$text($item,'guid',$post->url);$guid->setAttribute('isPermaLink','true');
            $text($item,'pubDate',$post->published_at->toRssString());$text($item,'dc:creator',$post->author->name);$text($item,'description',$post->summary);$text($item,'content:encoded',$post->html);
            foreach($post->tags as $tag)$text($item,'category',$tag->name);
        }
        return response($doc->saveXML(),200,['Content-Type'=>'application/rss+xml; charset=UTF-8']);
    }
    public function sitemap(){
        $doc=new \DOMDocument('1.0','UTF-8');$root=$doc->appendChild($doc->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9','urlset'));
        $paths=[config('blog.url').'/'];foreach($this->posts()->orderBy('id')->cursor() as $p)$paths[]=$p->url;
        foreach($paths as $path){$url=$root->appendChild($doc->createElement('url'));$loc=$url->appendChild($doc->createElement('loc'));$loc->appendChild($doc->createTextNode($path));}
        return response($doc->saveXML(),200,['Content-Type'=>'application/xml; charset=UTF-8']);
    }
}
