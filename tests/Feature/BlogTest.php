<?php
namespace Tests\Feature;
use App\Models\User;
use App\Models\Blog\{Post,Author,Tag};
use App\Blog\Publisher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Artisan,DB,Storage};
use Tests\TestCase;

class BlogTest extends TestCase {
    private User $admin;private Author $author;private Tag $tag;private string $host;
    protected function setUp():void {
        parent::setUp();
        config(['database.connections.finobe'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true],'app.key'=>'base64:'.base64_encode(str_repeat('b',32)),'cache.default'=>'array','session.driver'=>'array','admin.owner_ids'=>[1]]);DB::purge('finobe');
        Artisan::call('migrate',['--database'=>'finobe','--force'=>true]);
        foreach([[1,'Editor','admin'],[2,'Reader','member'],[3,'Delegate','member']] as [$id,$name,$status])DB::connection('finobe')->table('users')->insert(['id'=>$id,'username'=>$name,'email'=>strtolower($name).'@example.test','password'=>bcrypt('test-only'),'status'=>$status,'verified'=>'y','Dius'=>0,'friends'=>'[]','avatar'=>'[]','inventory'=>'[]','badges'=>'[]','created'=>now(),'lastlogin'=>now(),'lastdiu'=>now()->addDay()]);
        $this->admin=User::find(1);$this->author=Author::create(['name'=>'Local Editor','slug'=>'local-editor','bio'=>'A local author.']);$this->tag=Tag::create(['name'=>'Updates','slug'=>'updates']);$this->host=config('blog.url');
    }
    private function data(array $extra=[]):array {return array_replace(['title'=>'Local update','slug'=>'local-update','author_id'=>$this->author->id,'markdown'=>"## A heading\n\nA **local** update.",'status'=>'draft','version'=>0,'tags'=>[$this->tag->id]],$extra);}
    private function makePost(array $extra=[]):Post {return app(Publisher::class)->save($this->data($extra),$this->admin);}
    public function test_home_uses_captured_client_and_only_local_published_records():void {
        $p=$this->makePost(['status'=>'published']);$this->makePost(['slug'=>'secret-draft','title'=>'Secret draft']);
        $r=$this->get($this->host.'/');$r->assertOk()->assertSee('site-home-header')->assertSee('/s/blog/screen.css')->assertSee('Local update')->assertDontSee('Secret draft')->assertDontSee('google-analytics.com')->assertDontSee('blog.finobe.com');
        $this->assertMatchesRegularExpression('~<title>Finobe Blog</title>~',$r->getContent());
    }
    public function test_publication_from_admin_reaches_article_author_tag_and_rss():void {
        $r=$this->actingAs($this->admin)->post('/admin/blog/posts',$this->data(['status'=>'published']));$r->assertRedirect();$p=Post::firstOrFail();
        $this->get($p->url)->assertOk()->assertSee('<strong>local</strong>',false)->assertSee('Local Editor')->assertDontSee('Raymonf');
        $authorPage=$this->get($this->host.'/author/local-editor/')->assertOk()->assertSee('Local update');$this->assertEquals(1,substr_count($authorPage->getContent(),'class="outer site-nav-main"'));
        $this->get($this->host.'/tag/updates/')->assertOk()->assertSee('Local update');
        $rss=$this->get($this->host.'/rss/')->assertOk()->assertHeader('Content-Type','application/rss+xml; charset=UTF-8');$this->assertNotFalse(simplexml_load_string($rss->getContent()));$this->assertStringContainsString($p->url,$rss->getContent());
    }
    public function test_drafts_scheduled_and_archived_posts_do_not_leak():void {
        $draft=$this->makePost();$future=$this->makePost(['slug'=>'scheduled','status'=>'scheduled','published_at'=>now()->addDay()]);
        $this->get($draft->url)->assertNotFound();$this->get($future->url)->assertNotFound();$this->get($this->host.'/rss/')->assertDontSee('Local update');
        $this->travel(2)->days();$this->get($future->url)->assertOk();
        $future->delete();$this->get($future->url)->assertNotFound();$this->get($this->host.'/sitemap.xml')->assertDontSee('scheduled');
    }
    public function test_pagination_supplies_original_theme_next_link():void {
        for($i=0;$i<26;$i++)$this->makePost(['slug'=>'post-'.$i,'title'=>'Entry '.$i,'status'=>'published']);
        $r=$this->get($this->host.'/');$r->assertOk()->assertSee('rel="next"',false)->assertSee('/page/2/');
        $next=$this->get($this->host.'/page/2/')->assertOk();$this->assertEquals(1,substr_count($next->getContent(),'<article class="post-card'));
        $this->get($this->host.'/page/3/')->assertNotFound();
    }
    public function test_admin_checks_blog_permissions_and_anonymous_access():void {
        $this->get('/admin/blog')->assertForbidden();$this->actingAs(User::find(2))->post('/admin/blog/posts',$this->data())->assertForbidden();
        DB::connection('finobe')->table('admin_staff')->insert(['user_id'=>3,'scopes'=>json_encode(['Forum'])]);$this->actingAs(User::find(3))->get('/admin/blog')->assertForbidden();
        DB::connection('finobe')->table('admin_staff')->where('user_id',3)->update(['scopes'=>json_encode(['Blog'])]);$this->get('/admin/blog')->assertOk();
    }
    public function test_preview_is_private_and_never_publishes_a_draft():void {
        $p=$this->makePost();$this->get('/admin/blog/posts/'.$p->id.'/preview')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/blog/posts/'.$p->id.'/preview')->assertOk()->assertHeader('X-Robots-Tag','noindex, nofollow')->assertHeader('Cache-Control','no-store, private');
        $this->get($p->url)->assertNotFound();
    }
    public function test_changes_are_audited_and_stale_edits_are_rejected():void {
        $p=$this->makePost();$this->actingAs($this->admin)->post('/admin/blog/posts/'.$p->id,$this->data(['version'=>1,'title'=>'Updated title']))->assertRedirect();
        $this->post('/admin/blog/posts/'.$p->id,$this->data(['version'=>1,'title'=>'Stale overwrite']))->assertStatus(409);
        $this->assertEquals('Updated title',$p->fresh()->title);$this->assertEquals(2,DB::connection('finobe')->table('blog_revisions')->count());$this->assertEquals(2,DB::connection('finobe')->table('admin_audit')->where('target_type','blog')->count());
    }
    public function test_slug_changes_redirect_old_published_links_and_archiving_hides_them():void {
        $p=$this->makePost(['status'=>'published']);$old=$p->url;
        $admin=config('app.url').'/admin/blog/posts/'.$p->id;
        $this->actingAs($this->admin)->post($admin,$this->data(['version'=>1,'slug'=>'renamed','status'=>'published','published_at'=>$p->published_at->toDateTimeString()]))->assertRedirect();
        $this->get($old)->assertRedirect($p->fresh()->url)->assertStatus(301);
        $this->post($admin.'/archive',['version'=>2])->assertRedirect('/admin/blog');$this->get($old)->assertNotFound();
        $this->post($admin.'/archive',['version'=>3])->assertRedirect('/admin/blog');$this->assertEquals('draft',$p->fresh()->status);
    }
    public function test_revision_restore_is_a_new_draft_revision():void {
        $p=$this->makePost();$revision=DB::connection('finobe')->table('blog_revisions')->first();
        $this->actingAs($this->admin)->post('/admin/blog/posts/'.$p->id,$this->data(['title'=>'Changed','version'=>1]))->assertRedirect();
        $this->post('/admin/blog/posts/'.$p->id.'/revisions/'.$revision->id,['version'=>2])->assertRedirect();$this->assertEquals('Local update',$p->fresh()->title);$this->assertEquals('draft',$p->fresh()->status);$this->assertEquals(3,$p->fresh()->version);$this->assertEquals([$this->tag->id],$p->fresh()->tags->pluck('id')->all());
    }
    public function test_raw_html_and_unsafe_markdown_links_are_not_executable():void {
        $p=$this->makePost(['status'=>'published','markdown'=>"<script>alert(1)</script>\n\n[bad](javascript:alert%281%29)\n\n![bad](javascript:alert%281%29)"]);
        $this->assertStringNotContainsString('<script>',$p->html);$this->assertStringNotContainsString('href="javascript:',$p->html);$this->assertStringNotContainsString('src="javascript:',$p->html);
    }
    public function test_image_upload_is_local_and_rejects_nonimages():void {
        Storage::fake('public');$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQkAAAAASUVORK5CYII=');
        $r=$this->actingAs($this->admin)->post('/admin/blog/images',['image'=>UploadedFile::fake()->createWithContent('cover.png',$png)],['Accept'=>'application/json']);$r->assertOk()->assertJsonStructure(['url']);Storage::disk('public')->assertExists(substr($r->json('url'),9));
        $this->postJson('/admin/blog/images',['image'=>UploadedFile::fake()->createWithContent('bad.svg','<svg><script>bad()</script></svg>')])->assertUnprocessable();
    }
    public function test_blog_link_uses_configured_domain_and_editor_pages_render():void {
        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee($this->host.'/')->assertSee('href="/admin/blog"',false);
        foreach(['/admin/blog','/admin/blog/posts/new','/admin/blog/authors','/admin/blog/tags','/admin/blog/settings'] as $url)$this->get($url)->assertOk();
        $p=$this->makePost();$this->get('/admin/blog/posts/'.$p->id.'/edit')->assertOk()->assertSee('Revision history');
    }
    public function test_author_and_tag_feeds_only_include_matching_public_posts():void {
        $p=$this->makePost(['status'=>'published']);
        $this->get($this->host.'/author/local-editor/rss/')->assertOk()->assertSee($p->title);
        $this->get($this->host.'/tag/updates/rss/')->assertOk()->assertSee($p->title);
        $this->get($this->host.'/tag/missing/rss/')->assertNotFound();
    }
    public function test_authors_with_posts_are_protected_and_tag_deletion_detaches_posts():void {
        $p=$this->makePost();
        $this->actingAs($this->admin)->post('/admin/blog/authors/'.$this->author->id.'/delete')->assertUnprocessable();
        $this->post('/admin/blog/tags/'.$this->tag->id.'/delete')->assertRedirect();$this->assertCount(0,$p->fresh()->tags);
        $unused=Author::create(['name'=>'Unused','slug'=>'unused']);$this->post('/admin/blog/authors/'.$unused->id.'/delete')->assertRedirect();$this->assertNull(Author::find($unused->id));
    }
    public function test_feature_image_paths_are_validated_before_publishing():void {
        $this->actingAs($this->admin)->post('/admin/blog/posts',$this->data(['slug'=>'image-post','feature_image'=>'https://example.test/cover.png?v=2&size=600']))->assertRedirect();
        $this->postJson('/admin/blog/posts',$this->data(['slug'=>'invalid-image','feature_image'=>"https://example.test/a');color:red"]))->assertUnprocessable();
        $this->postJson('/admin/blog/posts',$this->data(['slug'=>'invalid-scheme','feature_image'=>'javascript:alert(1)']))->assertUnprocessable();
    }
    public function test_blog_assets_and_markup_match_the_recorded_capture():void {
        $manifest=json_decode(file_get_contents(base_path('docs/blog-capture.json')),true);
        foreach($manifest['assets'] as $file=>$hash)$this->assertSame($hash,hash_file('sha256',public_path('s/blog/'.$file)));
    }
}
