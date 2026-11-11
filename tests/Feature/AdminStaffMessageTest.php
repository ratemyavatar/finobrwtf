<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminStaffMessageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.finobe', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('finobe');
        Schema::connection('finobe')->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('status')->default('member');
            $table->string('password')->nullable();
            $table->string('remember_token')->nullable();
        });
        Schema::connection('finobe')->create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('author');
            $table->unsignedBigInteger('touser');
            $table->string('subject');
            $table->text('message');
            $table->timestamp('date')->nullable();
            $table->boolean('readed')->default(false);
            $table->boolean('archived')->default(false);
        });
        Schema::connection('finobe')->create('admin_user_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('moderator_id')->nullable();
            $table->string('action', 100);
            $table->text('details')->nullable();
            $table->timestamps();
        });
        DB::connection('finobe')->table('users')->insert([
            ['id' => 1, 'username' => 'StaffTester', 'status' => 'admin'],
            ['id' => 2, 'username' => 'RecipientTester', 'status' => 'member'],
        ]);
    }

    private function endpoint(): string
    {
        $host = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';
        return 'http://'.$host.'/admin/users/2/messages';
    }

    public function test_staff_message_sends_the_typed_body_not_the_submit_button_label(): void
    {
        $this->actingAs(User::findOrFail(1));

        $this->post($this->endpoint(), [
            'subject' => 'Please review your account',
            'body' => "Your account details need updating.\nContact us if you need help.",
            'message' => 'Send staff message',
        ])->assertRedirect('/admin/users/2/messages');

        $saved = DB::connection('finobe')->table('messages')->first();
        $this->assertNotNull($saved);
        $this->assertSame(1, (int) $saved->author);
        $this->assertSame(2, (int) $saved->touser);
        $this->assertSame('Please review your account', $saved->subject);
        $this->assertSame("Your account details need updating.\nContact us if you need help.", $saved->message);
        $this->assertNotSame('Send staff message', $saved->message);
        $this->assertSame(1, DB::connection('finobe')->table('admin_user_log')->where('action', 'staff_message')->count());
    }

    public function test_button_label_alone_cannot_be_sent_as_a_message(): void
    {
        $this->actingAs(User::findOrFail(1));
        $this->post($this->endpoint(), [
            'subject' => 'Account notice',
            'message' => 'Send staff message',
        ])->assertSessionHasErrors('body');

        $this->assertSame(0, DB::connection('finobe')->table('messages')->count());
    }

    public function test_non_staff_cannot_send_a_staff_message(): void
    {
        $this->actingAs(User::findOrFail(2));
        $this->post($this->endpoint(), [
            'subject' => 'Unauthorized',
            'body' => 'This should not arrive.',
        ])->assertForbidden();

        $this->assertSame(0, DB::connection('finobe')->table('messages')->count());
    }
}
