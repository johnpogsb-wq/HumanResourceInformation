<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    // --- Response headers --------------------------------------------------

    public function test_screens_cannot_be_framed_by_another_site(): void
    {
        $response = $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk();

        $this->assertSame('DENY', $response->headers->get('x-frame-options'));
        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            $response->headers->get('content-security-policy'),
        );
    }

    public function test_the_hardening_headers_are_present_on_a_guest_page_too(): void
    {
        $response = $this->get('/login')->assertOk();

        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
        $this->assertSame(
            'strict-origin-when-cross-origin',
            $response->headers->get('referrer-policy'),
        );
        $this->assertNotNull($response->headers->get('permissions-policy'));
    }

    /** Biometric devices talk to /api/v1; nosniff matters there as well. */
    public function test_api_responses_carry_the_headers(): void
    {
        $user = User::factory()->hrStaff()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/employees')
            ->assertOk();

        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
    }

    /**
     * HSTS over plain HTTP would pin http://core2.test to TLS it does not
     * serve, locking development out for the max-age.
     */
    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeaderMissing('strict-transport-security');
    }

    public function test_hsts_is_sent_over_https(): void
    {
        Config::set('app.url', 'https://core2.test');

        $response = $this->get('https://core2.test/login')->assertOk();

        $this->assertStringContainsString(
            'max-age=',
            (string) $response->headers->get('strict-transport-security'),
        );
    }

    // --- Password policy ---------------------------------------------------

    /**
     * `Password::defaults()` unconfigured means `min:8` and nothing more. Every
     * password entry point in the app defers to it, so this is the one place
     * the floor is set.
     */
    #[DataProvider('weakPasswords')]
    public function test_weak_passwords_are_rejected(string $password, string $why): void
    {
        $this->assertTrue($this->failsPolicy($password), $why);
    }

    public static function weakPasswords(): array
    {
        return [
            'too short' => ['Sh0rt1', 'Six characters is not a password.'],
            'eleven characters' => ['Elevenchar1', 'The floor is twelve.'],
            'no digit' => ['NoDigitsHereAtAll', 'A digit is required.'],
            'no uppercase' => ['nouppercase123', 'Mixed case is required.'],
            'no lowercase' => ['NOLOWERCASE123', 'Mixed case is required.'],
        ];
    }

    public function test_a_conforming_password_is_accepted(): void
    {
        $this->assertFalse($this->failsPolicy('Correct-Horse-9'));
    }

    /**
     * HR is handed a generated password when it provisions a login, and an
     * admin when it creates or resets a user. If the generator could produce
     * something the policy rejects, the account holder would be given a
     * password they are not allowed to keep.
     *
     * `Str::password(12)` — what all three call sites used before — fails this
     * roughly one run in twenty, because its pool contains every character
     * class but guarantees none.
     */
    public function test_every_generated_password_satisfies_the_policy(): void
    {
        foreach (range(1, 200) as $ignored) {
            $generated = User::generatePassword();

            $this->assertFalse(
                $this->failsPolicy($generated),
                "generatePassword() produced '{$generated}', which the policy rejects.",
            );
        }
    }

    /** Regression guard for the call sites, not just the generator. */
    public function test_provisioning_an_employee_login_yields_a_conforming_password(): void
    {
        $service = app(EmployeeService::class);

        $service->create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'email' => 'ana.reyes@primepower.test',
            'date_hired' => '2026-01-05',
            'employment_status' => 'probationary',
            'status' => 'active',
            'create_user_account' => true,
        ], null);

        $this->assertNotNull($service->generatedPassword);
        $this->assertFalse($this->failsPolicy($service->generatedPassword));
    }

    // --- API token expiry --------------------------------------------------

    public function test_issued_tokens_expire(): void
    {
        $this->assertNotNull(
            config('sanctum.expiration'),
            'A machine credential that never expires outlives the device it was copied onto.',
        );
    }

    public function test_an_expired_token_is_refused(): void
    {
        Config::set('sanctum.expiration', 60);

        $user = User::factory()->hrStaff()->create();
        $token = $user->createToken('depot-scanner');

        // Sanctum measures expiry from the token's own created_at.
        $token->accessToken->forceFill([
            'created_at' => now()->subMinutes(120),
        ])->save();

        $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/employees')
            ->assertUnauthorized();
    }

    public function test_a_token_inside_its_window_still_works(): void
    {
        Config::set('sanctum.expiration', 60);

        $user = User::factory()->hrStaff()->create();
        $token = $user->createToken('depot-scanner');

        $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/employees')
            ->assertOk();
    }

    private function failsPolicy(string $password): bool
    {
        try {
            Validator::validate(
                ['password' => $password],
                ['password' => Password::defaults()],
            );
        } catch (ValidationException) {
            return true;
        }

        return false;
    }
}
