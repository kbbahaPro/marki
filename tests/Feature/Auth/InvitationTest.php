<?php

namespace Tests\Feature\Auth;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();

        // "/register" now falls into the public /{username} route, which is GET-only.
        $this->post('/register', [
            'name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertMethodNotAllowed();
        $this->assertDatabaseMissing('users', ['email' => 'eve@example.com']);
    }

    public function test_admin_can_invite_a_team_member(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/members/invitations', ['email' => 'new@example.com'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $invitation = Invitation::sole();
        $this->assertSame('new@example.com', $invitation->email);
        $this->assertFalse($invitation->is_admin);
        $this->assertSame($admin->id, $invitation->invited_by);

        Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use ($invitation) {
            // The emailed token must match the stored hash, and the raw token must not be stored.
            return $mail->hasTo('new@example.com')
                && hash('sha256', $mail->token) === $invitation->token_hash;
        });
    }

    public function test_reinviting_replaces_the_pending_invitation(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/members/invitations', ['email' => 'new@example.com']);
        $this->actingAs($admin)->post('/admin/members/invitations', ['email' => 'new@example.com']);

        $this->assertSame(1, Invitation::count());
    }

    public function test_cannot_invite_an_existing_member(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($admin)
            ->post('/admin/members/invitations', ['email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');
    }

    public function test_non_admins_cannot_manage_the_team(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/members')->assertForbidden();
        $this->actingAs($user)->post('/admin/members/invitations', ['email' => 'x@example.com'])->assertForbidden();
    }

    public function test_admin_can_view_team_page_and_revoke_invitation(): void
    {
        $admin = User::factory()->admin()->create();
        [$invitation] = Invitation::issue('pending@example.com', $admin);

        $this->actingAs($admin)->get('/admin/members')->assertOk()->assertSee('pending@example.com');

        $this->actingAs($admin)
            ->delete("/admin/members/invitations/{$invitation->id}")
            ->assertRedirect();

        $this->assertModelMissing($invitation);
    }

    public function test_invitation_page_is_shown_for_a_valid_token(): void
    {
        [, $token] = Invitation::issue('new@example.com', null);

        $this->get("/invitations/{$token}")->assertOk()->assertSee('new@example.com');
    }

    public function test_invited_user_can_create_an_account(): void
    {
        [$invitation, $token] = Invitation::issue('new@example.com', null, isAdmin: true);

        $this->post("/invitations/{$token}", $this->validForm())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'new@example.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('jane-doe', $user->username);
        $this->assertSame('America/New_York', $user->timezone);
        $this->assertTrue($user->is_admin);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_invitation_cannot_be_used_twice(): void
    {
        [, $token] = Invitation::issue('new@example.com', null);

        $this->post("/invitations/{$token}", $this->validForm());
        auth()->logout();

        $this->get("/invitations/{$token}")->assertNotFound();
        $this->post("/invitations/{$token}", $this->validForm(['username' => 'other-name']))->assertNotFound();
        $this->assertSame(1, User::count());
    }

    public function test_expired_invitation_is_rejected(): void
    {
        [, $token] = Invitation::issue('new@example.com', null);
        $this->travel(config('booking.invitations.expire_days') + 1)->days();

        $this->get("/invitations/{$token}")->assertNotFound();
        $this->post("/invitations/{$token}", $this->validForm())->assertNotFound();
        $this->assertGuest();
    }

    public function test_unknown_token_is_rejected(): void
    {
        $this->get('/invitations/not-a-real-token')->assertNotFound();
    }

    public function test_reserved_or_malformed_usernames_are_rejected(): void
    {
        [, $token] = Invitation::issue('new@example.com', null);

        foreach (['dashboard', 'login', 'Jane', 'a', 'jane--doe', '-jane', 'jane_doe'] as $username) {
            $this->post("/invitations/{$token}", $this->validForm(['username' => $username]))
                ->assertSessionHasErrors('username');
        }

        $this->assertGuest();
    }

    public function test_invalid_timezone_is_rejected(): void
    {
        [, $token] = Invitation::issue('new@example.com', null);

        $this->post("/invitations/{$token}", $this->validForm(['timezone' => 'Mars/Olympus']))
            ->assertSessionHasErrors('timezone');
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Doe',
            'username' => 'jane-doe',
            'timezone' => 'America/New_York',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides);
    }
}
