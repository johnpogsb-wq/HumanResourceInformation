<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Notifications\LoginOtp;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The emailed second factor.
 *
 * This system already had one — Fortify's TOTP, a code from an authenticator
 * app — and **zero accounts were enrolled in it**, which is the whole argument
 * for this existing beside it rather than instead of it: TOTP asks somebody to
 * install an app, scan a QR code and keep recovery codes safe before it
 * protects anything, and a control nobody finishes setting up is a control
 * that is off. An emailed code asks for an inbox they already have open.
 *
 * The tests below are grouped by what could actually defeat it: walking past
 * the hold, guessing the code, replaying one, and reading one out of the
 * database.
 */
class LoginOtpTest extends TestCase
{
    use RefreshDatabase;

    // --- The hold ----------------------------------------------------------

    public function test_an_account_without_the_factor_signs_in_as_before(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_an_enrolled_account_is_held_on_the_code_screen(): void
    {
        Notification::fake();

        $this->actingAs($this->enrolled())
            ->get('/dashboard')
            ->assertRedirect(route('otp.challenge'));
    }

    /**
     * The first held request is what sends the code, not the login controller.
     *
     * One rule then covers every way into a session — the login form, a
     * remembered cookie being honoured, a session that was authenticated
     * before the factor was switched on. Hooking the login event instead would
     * have let the last two walk straight past a screen never shown.
     */
    public function test_being_held_is_what_sends_the_code(): void
    {
        Notification::fake();

        $user = $this->enrolled();

        $this->actingAs($user)->get('/dashboard');

        Notification::assertSentTo($user, LoginOtp::class);
        $this->assertNotNull($user->fresh()->otp_code_hash);
    }

    /**
     * Signing out has to stay reachable, or an inbox nobody can read is a
     * permanent lockout rather than an inconvenience.
     *
     * The same reasoning puts `logout` on `RequirePasswordChange`'s allow-list:
     * trapping somebody in a session they cannot leave is worse than the risk
     * being managed, and signing out reduces exposure rather than adding to it.
     */
    public function test_a_held_session_can_still_sign_out(): void
    {
        Notification::fake();

        $this->actingAs($this->enrolled())
            ->post('/logout')
            ->assertRedirect();

        $this->assertGuest();
    }

    /**
     * The API stack is outside this, like `RequirePasswordChange`.
     *
     * A Sanctum token is a machine credential on a biometric device: there is
     * no inbox and nobody at the other end to read a code. Holding it would
     * take the timeclock down rather than secure it.
     */
    public function test_an_api_token_is_not_held(): void
    {
        Notification::fake();

        $token = $this->enrolled()->createToken('device')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/employees')
            ->assertOk();
    }

    // --- Answering it ------------------------------------------------------

    public function test_the_right_code_releases_the_session(): void
    {
        [$user, $code] = $this->issued();

        $this->actingAs($user)
            ->post(route('otp.verify'), ['code' => $code])
            ->assertRedirect();

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    /** Spaces are what people paste out of an email; they are not a wrong answer. */
    public function test_a_pasted_code_with_spaces_is_accepted(): void
    {
        [$user, $code] = $this->issued();

        $this->actingAs($user)
            ->post(route('otp.verify'), ['code' => ' '.implode(' ', str_split($code)).' '])
            ->assertSessionHasNoErrors();
    }

    public function test_a_wrong_code_is_refused_and_costs_an_attempt(): void
    {
        [$user, $code] = $this->issued();

        $this->actingAs($user)
            ->post(route('otp.verify'), ['code' => $this->wrongCode($code)])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, $user->fresh()->otp_attempts);
        $this->assertGuestOfTheDashboard($user);
    }

    /**
     * The rule that makes six digits a factor rather than a formality.
     *
     * A million combinations is a lot for a person and nothing for a script:
     * with unlimited guesses inside the five-minute window, the code is
     * decoration. Five wrong answers burn it.
     */
    public function test_the_code_is_burned_after_too_many_wrong_answers(): void
    {
        [$user, $code] = $this->issued();

        for ($i = 0; $i < config('otp.max_attempts'); $i++) {
            $this->actingAs($user)->post(route('otp.verify'), ['code' => $this->wrongCode($code)]);
        }

        $this->assertNull($user->fresh()->otp_code_hash, 'The code survived being exhausted.');

        // And the correct code no longer works, which is the point: the
        // attacker who guesses on the last attempt does not then get a free
        // retry with the real one.
        $this->actingAs($user)
            ->post(route('otp.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');
    }

    /**
     * **Burned, not locked.** Locking the account would hand anybody who knows
     * an email address a way to keep its owner out — a denial of service
     * dressed as a security control.
     */
    public function test_exhausting_a_code_does_not_lock_the_account(): void
    {
        Notification::fake();

        [$user, $code] = $this->issued();

        for ($i = 0; $i < config('otp.max_attempts'); $i++) {
            $this->actingAs($user)->post(route('otp.verify'), ['code' => $this->wrongCode($code)]);
        }

        $this->assertTrue($user->fresh()->is_active);

        // A fresh code can be asked for, and it works.
        $this->travel(config('otp.resend_after_seconds') + 1)->seconds();
        $this->actingAs($user)->post(route('otp.resend'))->assertSessionHasNoErrors();

        $fresh = $this->codeFromMail($user);
        $this->actingAs($user)
            ->post(route('otp.verify'), ['code' => $fresh])
            ->assertSessionHasNoErrors();
    }

    public function test_an_expired_code_is_refused(): void
    {
        [$user, $code] = $this->issued();

        $this->travel(config('otp.ttl_seconds') + 1)->seconds();

        $this->actingAs($user)
            ->post(route('otp.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertGuestOfTheDashboard($user);
    }

    /**
     * A code works once.
     *
     * Cleared on success, so it cannot be replayed out of a browser history
     * entry, a second tab, or a proxy log.
     */
    public function test_a_used_code_cannot_be_replayed(): void
    {
        [$user, $code] = $this->issued();

        $this->actingAs($user)->post(route('otp.verify'), ['code' => $code]);

        $this->assertNull($user->fresh()->otp_code_hash);
    }

    // --- Pacing ------------------------------------------------------------

    /**
     * One number paces two abuses: filling somebody's inbox, and requesting
     * codes in bulk to learn which addresses exist.
     */
    public function test_a_resend_is_refused_inside_the_window(): void
    {
        Notification::fake();

        [$user] = $this->issued();

        $this->actingAs($user)
            ->post(route('otp.resend'))
            ->assertSessionHasErrors('code');
    }

    public function test_a_resend_is_allowed_once_the_window_passes(): void
    {
        Notification::fake();

        [$user] = $this->issued();

        $this->travel(config('otp.resend_after_seconds') + 1)->seconds();

        $this->actingAs($user)
            ->post(route('otp.resend'))
            ->assertSessionHasNoErrors();
    }

    // --- What a database dump would hold -----------------------------------

    /**
     * The code is stored as a hash, and that is not decoration.
     *
     * A live six-digit code in plaintext would make this column a better target
     * than the password hash beside it: a password hash cannot be replayed and
     * a plaintext OTP can. Asserted against the raw column, because reading it
     * through the model would prove nothing about what is on disk.
     */
    public function test_the_code_is_never_stored_in_plaintext(): void
    {
        [$user, $code] = $this->issued();

        $raw = DB::table('users')->where('id', $user->id)->first();

        $this->assertNotSame($code, $raw->otp_code_hash);
        $this->assertTrue(Hash::check($code, $raw->otp_code_hash), 'It is not even the hash.');
    }

    /**
     * And it never reaches a page payload.
     *
     * The Settings screen serialises the signed-in user's own account, which is
     * exactly how `two_factor_secret` would have leaked before it was added to
     * `$hidden`. This is the same class of thing in the same list.
     */
    public function test_the_code_hash_is_never_serialised(): void
    {
        [$user] = $this->issued();

        $this->assertArrayNotHasKey('otp_code_hash', $user->fresh()->toArray());
    }

    // --- The switch --------------------------------------------------------

    /**
     * Turning it *off* re-asks for the password, which is the half people
     * forget.
     *
     * Without it the cheapest way past this factor is an unlocked machine and
     * one click: the attacker never needs the inbox, they remove the
     * requirement.
     */
    public function test_turning_the_factor_off_needs_the_password(): void
    {
        $user = $this->enrolled();

        $this->actingAs($user)
            ->withSession([OtpService::SESSION_KEY => now()->toIso8601String()])
            ->put(route('settings.security.otp'), ['enabled' => false, 'current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($user->fresh()->otp_enabled);
    }

    public function test_turning_it_off_forgets_any_outstanding_code(): void
    {
        [$user] = $this->issued();

        $this->actingAs($user)
            ->withSession([OtpService::SESSION_KEY => now()->toIso8601String()])
            ->put(route('settings.security.otp'), [
                'enabled' => false,
                'current_password' => 'correct-horse-battery',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertFalse($user->otp_enabled);
        $this->assertNull($user->otp_code_hash, 'A code nobody expects to work was left behind.');
    }

    // --- Helpers -----------------------------------------------------------

    /** An account with the emailed factor switched on. */
    private function enrolled(): User
    {
        return User::factory()->hrStaff()->create([
            'password' => bcrypt('correct-horse-battery'),
            'otp_enabled' => true,
        ]);
    }

    /**
     * An enrolled account holding a live code, and the code itself.
     *
     * Read back out of the faked notification rather than generated here, so
     * the tests exercise the code the service actually issued.
     *
     * @return array{0: User, 1: string}
     */
    private function issued(): array
    {
        Notification::fake();

        $user = $this->enrolled();

        // The hold is what issues it — the same path a real sign-in takes.
        $this->actingAs($user)->get('/dashboard');

        return [$user, $this->codeFromMail($user)];
    }

    /** Digs the code out of the notification that was sent. */
    private function codeFromMail(User $user): string
    {
        $code = null;

        Notification::assertSentTo($user, LoginOtp::class, function (LoginOtp $notification) use ($user, &$code) {
            foreach ($notification->toMail($user)->introLines as $line) {
                if (preg_match('/^\*\*(\d+)\*\*$/', $line, $matches)) {
                    $code = $matches[1];
                }
            }

            return true;
        });

        $this->assertNotNull($code, 'No code was found in the email.');

        return $code;
    }

    /** Any code of the right shape that is not the right code. */
    private function wrongCode(string $code): string
    {
        $wrong = str_pad((string) (((int) $code + 1) % (10 ** strlen($code))), strlen($code), '0', STR_PAD_LEFT);

        $this->assertNotSame($code, $wrong);

        return $wrong;
    }

    /** Still held: the dashboard bounces back to the code screen. */
    private function assertGuestOfTheDashboard(User $user): void
    {
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('otp.challenge'));
    }
}
