<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => 'Ada Admin',
            '--email' => 'ada@example.com',
            '--username' => 'ada',
            '--timezone' => 'Europe/London',
            '--password' => 'secret-password',
        ])->assertSuccessful();

        $user = User::where('email', 'ada@example.com')->sole();
        $this->assertTrue($user->is_admin);
        $this->assertSame('ada', $user->username);
        $this->assertSame('Europe/London', $user->timezone);
        $this->assertTrue(Hash::check('secret-password', $user->password));
    }

    public function test_it_rejects_invalid_input(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => 'Ada Admin',
            '--email' => 'not-an-email',
            '--username' => 'login',
            '--timezone' => 'Nowhere/Land',
            '--password' => 'secret-password',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
