<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * The second factor.
 *
 * A password was the whole front door of a system holding salary, government
 * identifiers and bank details — and a password is the credential most likely
 * to be reused, phished, or read back off a chat message. These cover the
 * three states 2FA actually has, and the two places it would be easiest to
 * defeat.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_feature_is_registered(): void
    {
        // The routes exist only when the Fortify feature is enabled, so this
        // is what would catch somebody trimming config/fortify.php later.
        $this->assertTrue(Features::enabled(Features::twoFactorAuthentication()));
    }

    public function test_a_user_without_two_factor_signs_in_as_before(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery')]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticated();
    }

    /**
     * The point of the whole thing: a correct password is no longer a session.
     */
    public function test_an_enrolled_user_is_challenged_rather_than_signed_in(): void
    {
        $user = $this->enrolled();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/two-factor-challenge');

        $this->assertGuest();
    }

    /**
     * The middle state, and the reason the screen has three rather than a
     * toggle.
     *
     * Fortify writes the secret the moment somebody asks to enable 2FA and
     * only confirms it once they have typed a code. Somebody who closed the
     * tab halfway holds a secret and no protection — challenging them would
     * lock them out behind a factor they never finished setting up.
     */
    public function test_an_unconfirmed_secret_does_not_challenge(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('correct-horse-battery'),
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => null,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticated();
    }

    public function test_the_challenge_screen_renders(): void
    {
        $user = $this->enrolled();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ]);

        $this->get('/two-factor-challenge')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/TwoFactorChallenge'));
    }

    /**
     * A wrong code is not a session.
     *
     * Obvious, and worth a test anyway: this is the assertion that fails if
     * somebody ever "simplifies" the challenge into something that waves
     * people through on a malformed answer.
     */
    public function test_a_wrong_code_is_refused(): void
    {
        $user = $this->enrolled();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ]);

        $this->post('/two-factor-challenge', ['code' => '000000']);

        $this->assertGuest();
    }

    /**
     * Turning it *off* re-asks for the password, which is the half people
     * forget.
     *
     * Without it, the easiest way past a second factor is an unlocked machine
     * and one click — the attacker never needs the phone, they just remove the
     * requirement. `confirmPassword` on the Fortify feature is what closes it.
     */
    public function test_disabling_two_factor_needs_the_password_again(): void
    {
        $user = $this->enrolled();

        $this->actingAs($user)
            ->delete('/user/two-factor-authentication')
            ->assertRedirect(route('password.confirm'));

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_enabling_two_factor_needs_the_password_again(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/user/two-factor-authentication')
            ->assertRedirect(route('password.confirm'));

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    /**
     * The secret and the recovery codes must never reach a page payload.
     *
     * This failed when it was first written, which is the only reason it is
     * not still true: `TwoFactorAuthenticatable` brings the behaviour and not
     * the hiding — the starter kits add the two columns to `$hidden` and this
     * project has no starter kit. The Settings screen serialises the signed-in
     * user's own account, so the secret travelled to the browser, into the page
     * cache and into history, on every visit.
     */
    public function test_the_secret_is_never_serialised(): void
    {
        $user = $this->enrolled();

        $array = $user->toArray();

        $this->assertArrayNotHasKey('two_factor_secret', $array);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $array);
    }

    /**
     * The contract the Settings panel is built on.
     *
     * `RequirePassword` has two behaviours. On an ordinary visit it redirects
     * to the password screen and stores the interrupted URL as `url.intended`,
     * so confirming sends the browser back to it with **GET** — and six of the
     * seven 2FA routes are POST or DELETE, which is a 405 on the first click.
     * The panel therefore talks to all of them over axios, where the same
     * middleware answers **423 Locked** and the component can open its own
     * password box and re-run what was refused.
     *
     * If that ever stopped being 423, the screen would break in a way no
     * assertion about the routes themselves would notice.
     */
    public function test_a_json_request_is_locked_rather_than_redirected(): void
    {
        $user = $this->enrolled();

        // Every route the panel calls, in the method it calls it with.
        $calls = [
            ['postJson', '/user/two-factor-authentication'],
            ['deleteJson', '/user/two-factor-authentication'],
            ['getJson', '/user/two-factor-qr-code'],
            ['getJson', '/user/two-factor-recovery-codes'],
            ['postJson', '/user/confirmed-two-factor-authentication'],
        ];

        foreach ($calls as [$method, $uri]) {
            $this->actingAs($user)
                ->{$method}($uri)
                ->assertStatus(423, "{$method} {$uri} did not answer 423.");
        }
    }

    /**
     * And the far side of it: confirming the password over JSON answers 201
     * rather than redirecting, which is what lets the panel retry the held
     * action instead of navigating away from the QR code it is showing.
     */
    public function test_confirming_the_password_over_json_unlocks_without_a_redirect(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery')]);

        $this->actingAs($user)
            ->postJson('/user/confirm-password', ['password' => 'correct-horse-battery'])
            ->assertStatus(201);

        // The action that was refused a moment ago now goes through.
        $this->actingAs($user)
            ->postJson('/user/two-factor-authentication')
            ->assertSuccessful();

        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    /** An account with a confirmed second factor. */
    private function enrolled(): User
    {
        return User::factory()->create([
            'password' => bcrypt('correct-horse-battery'),
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(['aaaa-bbbb'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
