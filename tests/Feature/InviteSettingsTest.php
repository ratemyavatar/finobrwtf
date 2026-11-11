<?php

namespace Tests\Feature;

use App\Admin\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
    The "Require invite keys" toggle on /admin/invite-keys decides whether
    registration demands a valid key. When it is off the invite field is hidden
    and anyone may sign up without one.

    The register endpoint talks to Cloudflare Turnstile, the Discord webhook and
    the avatar renderer, so external HTTP and queued jobs are faked here to keep
    the test off the network. Everything else runs against the real code.
*/
class InviteSettingsTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.finobe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'app.key' => 'base64:' . base64_encode(str_repeat('t', 32)),
            'session.driver' => 'array', 'cache.default' => 'array',
            'admin.owner_ids' => [1],
            'admin.invites_enabled' => true,
            // register() hashes with password_hash() at PHP's default cost (12), so match it
            'hashing.bcrypt.rounds' => 12,
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
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true]), '*' => Http::response([], 204)]);
        Bus::fake();
        $this->admin = User::find(1);
    }

    private function signup(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/auth/register', array_merge([
            'username' => 'newbie', 'email' => 'newbie@example.test', 'email_confirmation' => 'newbie@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123', 'cf-turnstile-response' => 'dummy',
        ], $extra));
    }

    public function test_invite_keys_are_required_by_default(): void
    {
        $this->assertTrue(Settings::invitesRequired());
    }

    public function test_admin_toggle_flips_the_requirement(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/invite-keys', ['action' => 'toggle_required'])
            ->assertRedirect('/admin/invite-keys');

        $this->assertFalse(Settings::invitesRequired());

        $this->actingAs($this->admin)
            ->post('/admin/invite-keys', ['action' => 'toggle_required'])
            ->assertRedirect('/admin/invite-keys');

        $this->assertTrue(Settings::invitesRequired());
    }

    public function test_register_form_hides_the_invite_field_when_optional(): void
    {
        $this->get('/auth/form')->assertOk()->assertSee('name="invite_key"', false)->assertSee('Invite Key');

        Settings::put(Settings::REQUIRE_INVITES, false);

        $this->get('/auth/form')->assertOk()->assertDontSee('name="invite_key"', false)->assertDontSee('Invite Key');
    }

    public function test_signup_without_a_key_is_rejected_when_required(): void
    {
        Settings::put(Settings::REQUIRE_INVITES, true);

        $this->signup()->assertRedirect('/auth/form');
        $this->assertDatabaseMissing('users', ['username' => 'newbie'], 'finobe');
    }

    public function test_signup_without_a_key_is_allowed_when_optional(): void
    {
        Settings::put(Settings::REQUIRE_INVITES, false);

        $this->signup()->assertRedirect('/legal/welcome');
        $this->assertDatabaseHas('users', ['username' => 'newbie'], 'finobe');
    }

    public function test_a_supplied_key_is_still_consumed_when_optional(): void
    {
        Settings::put(Settings::REQUIRE_INVITES, false);
        DB::connection('finobe')->table('invitekeys')->insert(['author' => 'Owner', 'IID' => 'GOODKEY123', 'creation' => now(), 'used' => 'n']);

        $this->signup(['invite_key' => 'GOODKEY123'])->assertRedirect('/legal/welcome');

        $this->assertDatabaseHas('users', ['username' => 'newbie'], 'finobe');
        $this->assertDatabaseHas('invitekeys', ['IID' => 'GOODKEY123', 'used' => 'y', 'usedBy' => 'newbie'], 'finobe');
    }

    public function test_a_bogus_key_is_rejected_even_when_optional(): void
    {
        Settings::put(Settings::REQUIRE_INVITES, false);

        $this->signup(['invite_key' => 'NOPE'])->assertRedirect('/auth/form');
        $this->assertDatabaseMissing('users', ['username' => 'newbie'], 'finobe');
    }
}
