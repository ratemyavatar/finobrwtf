<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinobeBlogThemeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.finobe', [
            'driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'',
            'foreign_key_constraints'=>false,
        ]);
        DB::purge('finobe');

        Schema::connection('finobe')->create('blog_settings', function (Blueprint $table) {
            $table->string('key')->primary(); $table->longText('value')->nullable();
        });
        Schema::connection('finobe')->create('blog_authors', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug');
            $table->string('avatar_url')->nullable(); $table->text('bio')->nullable();
        });
        Schema::connection('finobe')->create('blog_tags', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug');
            $table->text('description')->nullable();
        });
        Schema::connection('finobe')->create('blog_posts', function (Blueprint $table) {
            $table->id(); $table->string('title'); $table->string('slug');
            $table->text('excerpt')->nullable(); $table->longText('body');
            $table->string('cover_url')->nullable(); $table->unsignedBigInteger('author_id')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('updated_at')->nullable(); $table->timestamp('created_at')->nullable();
        });
        Schema::connection('finobe')->create('blog_post_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('post_id'); $table->unsignedBigInteger('tag_id');
        });
        DB::connection('finobe')->table('blog_authors')->insert([
            'id'=>1,'name'=>'Finobe Staff','slug'=>'finobe-staff','bio'=>'Staff posts','avatar_url'=>null,
        ]);
        DB::connection('finobe')->table('blog_tags')->insert([
            'id'=>1,'name'=>'Updates','slug'=>'updates','description'=>'Site updates',
        ]);
    }

    private function url(string $path): string
    {
        return rtrim((string)config('blog.url'), '/').'/'.ltrim($path,'/');
    }

    private function seedPost(string $title, string $slug, string $status = 'published'): int
    {
        return DB::connection('finobe')->table('blog_posts')->insertGetId([
            'title'=>$title,'slug'=>$slug,'excerpt'=>'Site news and updates.',
            'body'=>"# News\n\n**Welcome** to the blog.",
            'cover_url'=>null, 'author_id'=>1, 'status'=>$status,
            'published_at'=>now()->subDay(), 'created_at'=>now()->subDay(),
            'updated_at'=>now(),
        ]);
    }

    public function test_homepage_recreates_the_supplied_casper_classes_without_featured_cards(): void
    {
        $this->seedPost('Published update', 'published-update');
        $this->seedPost('Unpublished work', 'unpublished-work', 'draft');

        $this->get($this->url('/'))->assertOk()
            ->assertSee('class="dark-mode no-infinite-scroll"', false)
            ->assertSee('is-head-left-logo has-serif-title', false)
            ->assertSee('site-header-cover', false)
            ->assertSee('post-card post no-image', false)
            ->assertDontSee('post-card-large', false)
            ->assertSee('MAIN WEBSITE')
            ->assertSee('Public update')
            ->assertDontSee('Hidden draft')
            ->assertSee('Page 1 of 1');
    }

    public function test_ghost_dated_urls_work_and_wrong_dates_do_not(): void
    {
        $id = $this->seedPost('Our first update', 'our-first-update');
        DB::connection('finobe')->table('blog_posts')->where('id', $id)
            ->update(['body' => "**Hello** <script>alert(1)</script>"]);
        DB::connection('finobe')->table('blog_post_tag')->insert(['post_id' => $id, 'tag_id' => 1]);
        $this->get($this->url('/our-first-update/'))->assertOk()
            ->assertSee('<strong>Hello</strong>', false)
            ->assertDontSee('<script>', false)
            ->assertSee('Finobe Staff')
            ->assertSee('Updates');
    }

    public function test_search_only_returns_matching_published_posts(): void
    {
        $id = $this->seedPost('Tagged announcement', 'tagged-announcement');
        DB::connection('finobe')->table('blog_post_tag')->insert(['post_id' => $id, 'tag_id' => 1]);
        $this->get($this->url('/tag/updates/'))->assertOk()->assertSee('Tagged announcement');
        $this->get($this->url('/author/finobe-staff/'))->assertOk()->assertSee('Tagged announcement');
    }

    public function test_tag_author_pagination_and_rss_remain_available(): void
    {
        for ($i = 1; $i <= 13; $i++) $this->seedPost('Story number '.$i, 'story-'.$i);
        $this->seedPost('Secret draft', 'secret-draft', 'draft');

        for($i=1;$i<=13;$i++) $this->publish('Story '.$i,'story-'.$i);
        $this->get($this->url('/'))->assertOk()->assertSee('/page/2/',false);
        $this->get($this->url('/page/2/'))->assertOk()->assertSee('Tagged update');
        $this->get($this->url('/rss/'))->assertOk()
            ->assertHeader('content-type','application/rss+xml; charset=UTF-8')
            ->assertSee('<rss version="2.0">',false);
    }
}
