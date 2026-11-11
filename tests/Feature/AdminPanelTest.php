<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    private User $admin;

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
        $db->table('users')->insert([
            'id' => 1, 'username' => 'Owner', 'email' => 'owner@example.test', 'password' => bcrypt('test-only-password'),
            'status' => 'admin', 'verified' => 'y', 'Dius' => 100, 'avatar' => json_encode([['equippedGearVersionIds' => [], 'bodyColors' => []]]),
            'created' => now(), 'lastlogin' => now(), 'friends' => '[]', 'inventory' => '[]', 'badges' => '[]',
        ]);
        $this->admin = User::find(1);
    }

    public function test_admin_root_serves_the_2020_panel_with_the_workbench_link(): void {
        $this->actingAs($this->admin)->get('/admin')
            ->assertOk()
            ->assertSee('Admin Panel')
            ->assertSee('/admin/workbench')
            ->assertSee('Modify User Permissions')
            ->assertSee('Invite Tree');
    }

    public function test_workbench_still_loads_at_its_own_path(): void {
        $this->actingAs($this->admin)->get('/admin/workbench')->assertOk()->assertSee('Manage Users');
    }

    public function test_every_tile_and_link_loads(): void {
        $paths = [
            '/admin/ipbans', '/admin/users', '/admin/assets', '/admin/bans', '/admin/decider',
            '/admin/force-regen', '/admin/prune-posts', '/admin/purge-games', '/admin/createxml',
            '/admin/rbxcreatexml', '/admin/purchase-log', '/admin/give_dius',
            '/admin/give-asset/non-dependent', '/admin/give-asset/dependent',
            '/admin/approval-log', '/admin/player-thumbnails',

            '/admin/rank', '/admin/clear-cache', '/admin/force-username', '/admin/invite-tree',

            '/admin/give_badges', '/admin/warn', '/admin/announcements', '/admin/servers',
            '/admin/changeversions', '/admin/elections', '/admin/blog', '/admin/workbench',
        ];
        $failures = [];
        foreach ($paths as $path) {
            $status = $this->actingAs($this->admin)->get($path)->getStatusCode();
            if ($status >= 500) {
                $failures[] = "$path -> $status";
            }
        }
        $this->assertSame([], $failures, "These admin pages returned a server error:\n" . implode("\n", $failures));
    }
    public function test_manage_user_page_renders_without_blue_accent_styling(): void {
        $this->actingAs($this->admin)->get('/admin/users/1')->assertOk()
            ->assertSee('Manage user')
            ->assertSee('classic-panels.css?v=', false);

        $css = file_get_contents(public_path('s/admin/classic-panels.css'));
        $this->assertStringNotContainsString('3,155,229', $css, 'classic-panels.css still has a blue accent');
        $this->assertStringNotContainsString('#039be5', $css, 'classic-panels.css still has a blue accent');
    }
}
