<?php
namespace Tests\Feature;

use App\Admin\Catalog;
use App\Admin\Workbench;
use App\Models\User;
use App\Services\Restrictions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RestrictionsTest extends TestCase
{
    private User $admin;
    private User $member;

    protected function setUp(): void {
        parent::setUp();
        config([
            'database.connections.finobe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'app.key' => 'base64:' . base64_encode(str_repeat('t', 32)),
            'session.driver' => 'array', 'cache.default' => 'array',
            'admin.owner_ids' => [1],
        ]);
        DB::purge('finobe');
        Artisan::call('migrate', ['--database' => 'finobe', '--force' => true]);
        $db = DB::connection('finobe');
        foreach ([[1, 'Owner', 'admin'], [2, 'Member', 'member']] as [$id, $name, $status]) {
            $db->table('users')->insert([
                'id' => $id, 'username' => $name, 'email' => strtolower($name) . '@example.test', 'password' => bcrypt('test-only-password'),
                'status' => $status, 'verified' => 'y', 'Dius' => 100, 'avatar' => json_encode([['equippedGearVersionIds' => [], 'bodyColors' => []]]),
                'created' => now(), 'lastlogin' => now(), 'friends' => '[]', 'inventory' => '[]', 'badges' => '[]',
            ]);
        }
        $this->admin = User::find(1);
        $this->member = User::find(2);
    }

    private function block(string $controlId, string $reason = 'Verified test case'): void {
        app(Workbench::class)->run(Catalog::find($controlId), [
            'target' => '2', 'reason' => $reason, 'request_id' => (string) Str::uuid(), 'confirm' => true,
        ], $this->admin);
    }

    public function test_panel_block_applies_and_shows_the_message_with_the_reason(): void {
        $this->block('access-restrictions-block-forum-posting', 'Posting spam threads');
        $this->assertNotNull(Restrictions::active(2, 'forum'));
        $message = Restrictions::message(2, 'forum');
        $this->assertStringContainsString("You're restricted from posting on the forum.", $message);
        $this->assertStringContainsString('Reason: Posting spam threads', $message);
        $this->assertStringNotContainsString('until', $message);
    }

    public function test_block_and_restore_toggle_the_same_feature(): void {
        $this->block('access-restrictions-block-private-messages');
        $this->assertNotNull(Restrictions::active(2, 'chat'));
        $this->block('access-restrictions-restore-private-messages');
        $this->assertNull(Restrictions::active(2, 'chat'));
    }

    public function test_play_restriction_maps_to_the_play_feature(): void {
        $this->block('access-restrictions-block-playing', 'Exploiting in games');
        $this->assertNotNull(Restrictions::active(2, 'play'));
        $this->assertStringContainsString('playing games', Restrictions::message(2, 'play'));
    }

    public function test_restricted_member_cannot_post_and_message_but_others_can(): void {
        $this->block('access-restrictions-block-forum-posting');

        $this->assertNotNull(Restrictions::message(2, 'forum'));
        $this->assertNull(Restrictions::message(1, 'forum'));
    }

    public function test_active_ignores_rows_that_are_not_blocked(): void {
        DB::connection('finobe')->table('admin_restrictions')->insert(['user_id' => 2, 'feature' => 'forum_posts', 'blocked' => false]);
        $this->assertNull(Restrictions::active(2, 'forum'));
        DB::connection('finobe')->table('admin_restrictions')->where('user_id', 2)->update(['blocked' => true]);
        $this->assertNotNull(Restrictions::active(2, 'forum'));
    }
    public function test_security_page_shows_the_restriction(): void {
        $this->block('access-restrictions-block-forum-posting', 'Posting spam threads');
        $this->actingAs($this->member)->get('/app/security')->assertOk()->assertSee('Posting spam threads');
    }
}
