<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        $s=Schema::connection('finobe');
        $s->create('blog_authors',function(Blueprint $t){
            $t->id();$t->string('name',100);$t->string('slug',180)->unique();
            $t->text('bio')->nullable();$t->string('avatar',1000)->nullable();$t->string('website',1000)->nullable();$t->string('location',100)->nullable();$t->timestamps();
        });
        $s->create('blog_posts',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('author_id')->index();$t->foreign('author_id')->references('id')->on('blog_authors')->restrictOnDelete();$t->string('title',200);$t->string('slug',180)->unique();
            $t->string('status',20)->default('draft');$t->longText('markdown');$t->longText('html');$t->text('excerpt')->nullable();
            $t->string('feature_image',1000)->nullable();$t->string('image_alt',200)->nullable();
            $t->timestamp('published_at')->nullable();$t->unsignedInteger('version')->default(1);$t->timestamps();$t->softDeletes();
            $t->index(['status','published_at']);
        });
        $s->create('blog_tags',function(Blueprint $t){$t->id();$t->string('name',80);$t->string('slug',100)->unique();$t->text('description')->nullable();$t->timestamps();});
        $s->create('blog_post_tags',function(Blueprint $t){$t->unsignedBigInteger('post_id');$t->unsignedBigInteger('tag_id');$t->primary(['post_id','tag_id']);$t->foreign('post_id')->references('id')->on('blog_posts')->cascadeOnDelete();$t->foreign('tag_id')->references('id')->on('blog_tags')->cascadeOnDelete();});
        $s->create('blog_revisions',function(Blueprint $t){$t->id();$t->unsignedBigInteger('post_id')->index();$t->unsignedBigInteger('actor_id');$t->unsignedInteger('version');$t->longText('snapshot');$t->timestamp('created_at')->useCurrent();});
        $s->create('blog_redirects',function(Blueprint $t){$t->string('path',255)->primary();$t->unsignedBigInteger('post_id')->index();});
        $s->create('blog_settings',function(Blueprint $t){$t->string('key')->primary();$t->text('value')->nullable();});
    }
    public function down(): void {foreach(['blog_settings','blog_redirects','blog_revisions','blog_post_tags','blog_tags','blog_posts','blog_authors'] as $t)Schema::connection('finobe')->dropIfExists($t);}
};
