<?php
namespace Tests\Feature;

use App\Services\Matchmaking\PlaceLauncher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServerReaperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.finobe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'app.key' => 'base64:' . base64_encode(str_repeat('t', 32)),
            'session.driver' => 'array',
            'cache.default' => 'array',

            'finobe.arbiter.host' => '127.0.0.1',
            'finobe.arbiter.port' => 1,
        ]);
        DB::purge('finobe');
        Artisan::call('migrate', ['--database' => 'finobe', '--force' => true]);

        $db = DB::connection('finobe');

        $db->table('assets')->insert([
            'id' => 15, 'asset_type' => '9', 'title' => 'Test place', 'author' => 1,
            'file' => 'reaper-test.bin', 'visibility' => 'r', 'additional' => json_encode(['version' => '2016']),
        ]);
    }

    private function server(array $overrides = []): void
    {
        DB::connection('finobe')->table('servers')->insert(array_merge([
            'ip' => '13.140.232.195', 'port' => 53640, 'placeid' => 15, 'players' => '[1]',
            'jobId' => 'job-' . uniqid(), 'status' => '2', 'started_at' => now(), 'last_alive' => now(),
        ], $overrides));
    }

    public function test_reaps_a_server_that_stopped_heartbeating(): void
    {
        $this->server(['jobId' => 'dead-heartbeat', 'last_alive' => now()->subSeconds(PlaceLauncher::ALIVE_TIMEOUT + 60), 'started_at' => now()->subMinutes(10)]);

        $this->assertSame(1, PlaceLauncher::cleanup());
        $this->assertSame(0, DB::connection('finobe')->table('servers')->where('jobId', 'dead-heartbeat')->count());
    }

    public function test_keeps_a_server_that_is_still_heartbeating(): void
    {
        $this->server(['jobId' => 'still-alive']);

        $this->assertSame(0, PlaceLauncher::cleanup());
        $this->assertSame(1, DB::connection('finobe')->table('servers')->where('jobId', 'still-alive')->count());
    }

    public function test_reaps_a_server_that_never_finished_starting(): void
    {
        $this->server(['jobId' => 'never-started', 'status' => '1', 'started_at' => now()->subSeconds(PlaceLauncher::START_TIMEOUT + 60), 'last_alive' => null]);

        $this->assertSame(1, PlaceLauncher::cleanup());
        $this->assertSame(0, DB::connection('finobe')->table('servers')->where('jobId', 'never-started')->count());
    }

    public function test_keeps_a_server_that_is_still_starting(): void
    {
        $this->server(['jobId' => 'starting', 'status' => '1', 'started_at' => now(), 'last_alive' => null]);

        $this->assertSame(0, PlaceLauncher::cleanup());
        $this->assertSame(1, DB::connection('finobe')->table('servers')->where('jobId', 'starting')->count());
    }

    public function test_reap_servers_command_removes_stale_rows(): void
    {
        $this->server(['jobId' => 'stale-1', 'last_alive' => now()->subMinutes(10), 'started_at' => now()->subMinutes(10)]);
        $this->server(['jobId' => 'live-1']);

        Artisan::call('finobe:reap-servers');

        $db = DB::connection('finobe');
        $this->assertSame(0, $db->table('servers')->where('jobId', 'stale-1')->count());
        $this->assertSame(1, $db->table('servers')->where('jobId', 'live-1')->count());
    }
}
