<?php
namespace App\Http\Controllers\Blog;
use App\Blog\{Publisher,Settings};
use App\Models\Blog\{Post,Author,Tag};
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Storage,Validator};
use Illuminate\Validation\Rule;

final class AdminController extends Controller {
    private function page(Request $request,string $view,array $data=[]){
        $nav=(new \App\Http\Controllers\frontEnd($request))->getData();$nav['data']['admin_panel']=true;$nav['data']['notifications']['ads']=false;
        return view('admin.blog.'.$view,$data+['actor'=>$request->user(),'navbarHtml'=>view('v2/Modules/nav',$nav)->render()]);
    }
    public function index(Request $request){
        $status=$request->query('status','all');abort_unless(in_array($status,['all','draft','published','scheduled','archived']),422);
        $posts=Post::with('author')->withCount('tags');if($status==='archived')$posts->onlyTrashed();elseif($status!=='all')$posts->where('status',$status);
        if($q=$request->query('q'))$posts->where('title','like','%'.mb_substr($q,0,120).'%');
        return $this->page($request,'index',['posts'=>$posts->orderByDesc('updated_at')->paginate(25)->withQueryString(),'status'=>$status]);
    }
    public function edit(Request $request,?int $id=null){return $this->page($request,'edit',['post'=>$id?Post::with('tags')->findOrFail($id):new Post(['status'=>'draft','version'=>0]),'authors'=>Author::orderBy('name')->get(),'tags'=>Tag::orderBy('name')->get(),'revisions'=>$id?DB::connection('finobe')->table('blog_revisions')->where('post_id',$id)->orderByDesc('version')->limit(20)->get():collect()]);}
    private function assetRule():string{return '~^(https://[A-Za-z0-9._\~:/?#\[\]@!$&()*+,;=%-]+|/(storage/blog|s/blog)/[A-Za-z0-9/_.-]+)$~D';}
    private function validateImageUrl($attribute,$value,$fail){if($value&&(!preg_match($this->assetRule(),$value)||str_contains($value,'..')))$fail('Use an HTTPS image URL or a blog image upload path.');}
    public function save(Request $request,Publisher $publisher,?int $id=null){
        $data=$request->validate([
            'title'=>'required|string|max:200','slug'=>['required','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/','max:180',Rule::unique('finobe.blog_posts','slug')->ignore($id)],
            'author_id'=>['required',Rule::exists('finobe.blog_authors','id')],'status'=>'required|in:draft,published,scheduled','markdown'=>'required|string|max:200000',
            'excerpt'=>'nullable|string|max:500','feature_image'=>['nullable','string','max:1000',fn($a,$v,$f)=>$this->validateImageUrl($a,$v,$f)],'image_alt'=>'nullable|string|max:200',
            'published_at'=>'nullable|date','version'=>($id?'required':'nullable').'|integer|min:0','tags'=>'sometimes|array|max:20','tags.*'=>['integer',Rule::exists('finobe.blog_tags','id')],
        ]);
        $data['tags']=$data['tags']??[];
        $post=$publisher->save($data,$request->user(),$id);
        return redirect('/admin/blog/posts/'.$post->id.'/edit')->with('blog_success','Post saved.');
    }
    public function archive(Request $request,int $id){
        $request->validate(['version'=>'required|integer']);
        DB::connection('finobe')->transaction(function()use($request,$id){$p=Post::withTrashed()->lockForUpdate()->findOrFail($id);abort_unless((int)$request->input('version')===$p->version,409,'This post changed. Reload before continuing.');$old=$p->toArray();$p->version++;$p->save();if($p->trashed()){$p->status='draft';$p->restore();}else $p->delete();Publisher::audit($request->user(),'blog.archive',$id,'Archive or restore blog post',$old,$p->toArray());});
        return redirect('/admin/blog')->with('blog_success','Post updated.');
    }
    public function preview(Request $request,int $id,PublicController $public){return $public->article(Post::findOrFail($id),true);}
    public function revision(Request $request,int $id,int $revision,Publisher $publisher){
        $request->validate(['version'=>'required|integer']);$r=DB::connection('finobe')->table('blog_revisions')->where('post_id',$id)->where('id',$revision)->first();abort_unless($r,404);
        $data=collect(json_decode($r->snapshot,true))->only(['title','slug','author_id','markdown','excerpt','feature_image','image_alt','published_at'])->all();$snapshot=json_decode($r->snapshot,true);$tagIds=array_column($snapshot['tags']??[],'id');$data['tags']=Tag::whereIn('id',$tagIds)->pluck('id')->all();$data['status']='draft';$data['version']=$request->integer('version');
        $publisher->save($data,$request->user(),$id);return redirect('/admin/blog/posts/'.$id.'/edit')->with('blog_success','Revision restored as a draft.');
    }
    public function authors(Request $request){return $this->page($request,'authors',['authors'=>Author::withCount('posts')->orderBy('name')->get()]);}
    public function authorSave(Request $request,?int $id=null){
        $data=$request->validate(['name'=>'required|string|max:100','slug'=>['required','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/','max:180',Rule::unique('finobe.blog_authors','slug')->ignore($id)],'bio'=>'nullable|string|max:1000','avatar'=>['nullable','string','max:1000',fn($a,$v,$f)=>$this->validateImageUrl($a,$v,$f)],'website'=>'nullable|url:https|max:1000','location'=>'nullable|string|max:100']);
        DB::connection('finobe')->transaction(function()use($data,$request,$id){$a=$id?Author::lockForUpdate()->findOrFail($id):new Author();$old=$a->toArray();$a->fill($data)->save();Publisher::audit($request->user(),'blog.author',$a->id,'Save blog author',$old,$a->toArray());});
        return redirect('/admin/blog/authors')->with('blog_success','Author saved.');
    }
    public function authorDelete(Request $request,int $id){
        DB::connection('finobe')->transaction(function()use($request,$id){$a=Author::lockForUpdate()->findOrFail($id);abort_if(Post::withTrashed()->where('author_id',$id)->exists(),422,'Reassign this author’s posts before deleting the author.');Publisher::audit($request->user(),'blog.author.delete',$id,'Delete unused blog author',$a->toArray(),null);$a->delete();});
        return redirect('/admin/blog/authors')->with('blog_success','Author deleted.');
    }
    public function tagDelete(Request $request,int $id){
        DB::connection('finobe')->transaction(function()use($request,$id){$tag=Tag::lockForUpdate()->findOrFail($id);Publisher::audit($request->user(),'blog.tag.delete',$id,'Delete blog tag',$tag->toArray(),null);$tag->posts()->detach();$tag->delete();});
        return redirect('/admin/blog/tags')->with('blog_success','Tag deleted.');
    }
    public function tags(Request $request){return $this->page($request,'tags',['tags'=>Tag::withCount('posts')->orderBy('name')->get()]);}
    public function tagSave(Request $request,?int $id=null){
        $data=$request->validate(['name'=>'required|string|max:80','slug'=>['required','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/','max:100',Rule::unique('finobe.blog_tags','slug')->ignore($id)],'description'=>'nullable|string|max:1000']);
        DB::connection('finobe')->transaction(function()use($data,$request,$id){$t=$id?Tag::lockForUpdate()->findOrFail($id):new Tag();$old=$t->toArray();$t->fill($data)->save();Publisher::audit($request->user(),'blog.tag',$t->id,'Save blog tag',$old,$t->toArray());});
        return redirect('/admin/blog/tags')->with('blog_success','Tag saved.');
    }
    public function settings(Request $request){return $this->page($request,'settings',['settings'=>Settings::all()]);}
    public function settingsSave(Request $request){
        $data=$request->validate(['title'=>'required|string|max:60','description'=>'required|string|max:160','logo'=>['required','string','max:1000',fn($a,$v,$f)=>$this->validateImageUrl($a,$v,$f)],'cover'=>['required','string','max:1000',fn($a,$v,$f)=>$this->validateImageUrl($a,$v,$f)],'twitter_url'=>'nullable|url:https|max:255']);
        DB::connection('finobe')->transaction(function()use($data,$request){$old=Settings::all();foreach($data as $key=>$value)DB::connection('finobe')->table('blog_settings')->updateOrInsert(['key'=>$key],['value'=>$value]);Publisher::audit($request->user(),'blog.settings',null,'Save blog settings',$old,$data);});return redirect('/admin/blog/settings')->with('blog_success','Settings saved.');
    }
    public function upload(Request $request){
        $request->validate(['image'=>'required|image|mimes:jpeg,png,gif,webp|max:5120|dimensions:max_width=6000,max_height=6000']);
        $path=Storage::disk('public')->putFile('blog/'.now()->format('Y/m'),$request->file('image'));
        abort_unless($path,500,'Image could not be saved.');
        Publisher::audit($request->user(),'blog.upload',null,'Upload blog image',null,['path'=>$path]);
        return response()->json(['url'=>'/storage/'.$path]);
    }
}
