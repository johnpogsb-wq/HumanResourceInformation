<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * People sign in with a username, and the two second factors are gone.
 *
 * A company login should not depend on somebody's personal inbox, and the
 * role already lives on the account — so a username and a password are the
 * whole of signing in. These tests pin that down, including that the removed
 * OTP and two-factor routes are really unreachable rather than merely hidden.
 */
class UsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_signs_in_with_their_username(): void
    {
        $user = User::factory()->create(['username' => 'mariasantos']);

        $this->post('/login', [
            'username' => 'mariasantos',
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    /**
     * Typing the email into the username box does not sign anybody in. There
     * is one way in, not two.
     */
    public function test_an_email_address_is_not_accepted_as_the_username(): void
    {
        $user = User::factory()->create(['username' => 'mariasantos']);

        $this->post('/login', [
            'username' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_the_username_is_not_case_sensitive(): void
    {
        $user = User::factory()->create(['username' => 'mariasantos']);

        $this->post('/login', [
            'username' => 'MariaSantos',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_does_not_sign_in(): void
    {
        User::factory()->create(['username' => 'mariasantos']);

        $this->post('/login', [
            'username' => 'mariasantos',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    /**
     * Each role signs in the same way and lands on the same dashboard; what
     * they can open afterwards is decided by the role on the account.
     */
    public function test_every_role_signs_in_with_a_username(): void
    {
        foreach (User::ROLES as $role) {
            $user = User::factory()->create(['username' => "demo_{$role}", 'role' => $role]);

            $this->post('/login', ['username' => "demo_{$role}", 'password' => 'password'])
                ->assertRedirect(route('dashboard', absolute: false));

            $this->assertAuthenticatedAs($user);
            $this->post('/logout');
        }
    }

    /**
     * Every account gets a username however it was created, so none of the
     * four creation paths can make a login that cannot sign in.
     */
    public function test_a_new_account_is_given_a_username_from_its_email(): void
    {
        $user = User::factory()->create([
            'username' => null,
            'email' => 'hr@primepower.test',
        ]);

        $this->assertSame('hr', $user->username);
    }

    public function test_a_taken_username_gets_a_number_rather_than_colliding(): void
    {
        User::factory()->create(['username' => null, 'email' => 'hr@primepower.test']);
        $second = User::factory()->create(['username' => null, 'email' => 'hr@another.test']);

        $this->assertSame('hr2', $second->username);
    }

    public function test_a_username_given_explicitly_is_kept(): void
    {
        $user = User::factory()->create(['username' => 'boss', 'email' => 'someone@primepower.test']);

        $this->assertSame('boss', $user->username);
    }

    /**
     * Removed, not hidden. A route that still answered would be a second
     * factor half-present — the screen gone but the door still open.
     */
    public function test_the_otp_and_two_factor_routes_no_longer_exist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/otp')->assertNotFound();
        $this->actingAs($user)->post('/otp')->assertNotFound();
        $this->actingAs($user)->put('/settings/security/otp')->assertNotFound();
        $this->actingAs($user)->post('/user/two-factor-authentication')->assertNotFound();
        $this->get('/two-factor-challenge')->assertNotFound();
    }

    /**
     * Signing in goes straight to the dashboard. Nothing holds the session on
     * a code screen afterwards.
     */
    public function test_nothing_holds_a_signed_in_session_on_a_code_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }
}
