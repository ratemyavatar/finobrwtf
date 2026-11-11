<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        $schema = Schema::connection('finobe');

        $fields = ['status'=>['string','member'],'Dius'=>['bigInteger',0],'verified'=>['string','n'],'diubanned'=>['string','n'],'slots'=>['integer',1],'blurb'=>['text',''],'pfp'=>['string','avatar/682b8bd6cbc48.png'],'version'=>['string','v2'],'branding'=>['string','finobe'],'logo'=>['string','v3'],'theme'=>['string','0'],'gary'=>['integer',0],'lastlogin'=>['timestamp',null],'lastdiu'=>['timestamp',null],'created'=>['timestamp',null],'ip'=>['string',null],'render_cooldown'=>['timestamp',null]];
        foreach ($fields as $name => [$type,$default]) {
            if (!$schema->hasColumn('users',$name)) $schema->table('users', function(Blueprint $t) use($name,$type,$default) {
                $column=$t->$type($name);
                $default === null ? $column->nullable() : $column->default($default);
            });
        }
        $schema->create('admin_audit', function(Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('actor_id')->index(); $t->string('actor_name');
            $t->string('action')->index(); $t->string('label'); $t->string('target_type');
            $t->string('target_id')->nullable()->index(); $t->text('reason');
            $t->longText('before')->nullable(); $t->longText('after')->nullable();
            $t->uuid('request_id')->unique(); $t->timestamp('created_at')->useCurrent()->index();
        });
        $schema->create('admin_notes', function(Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id')->index(); $t->unsignedBigInteger('actor_id');
            $t->text('body'); $t->timestamp('created_at')->useCurrent();
        });
        $schema->create('admin_restrictions', function(Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('feature'); $t->boolean('blocked');
            $t->unique(['user_id','feature']);
        });
        $schema->create('admin_settings', function(Blueprint $t) {
            $t->string('key')->primary(); $t->text('value');
        });
        $schema->create('admin_staff', function(Blueprint $t) {
            $t->unsignedBigInteger('user_id')->primary(); $t->text('scopes');
        });
        $schema->create('admin_flair', function(Blueprint $t) {
            $t->unsignedBigInteger('user_id')->primary(); $t->string('title')->nullable();
            $t->string('effect')->nullable(); $t->string('accent')->nullable();
        });
    }
    public function down(): void {
        foreach (['admin_flair','admin_staff','admin_settings','admin_restrictions','admin_notes','admin_audit'] as $table) Schema::connection('finobe')->dropIfExists($table);
    }
};
