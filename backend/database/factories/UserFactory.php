<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName().'@'.User::USERNAME_DOMAIN,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            // Acknowledged by default, so a test about something else is not
            // redirected to the notice. See withoutPrivacyAcknowledgement().
            'privacy_notice_version' => config('privacy.notice_version'),
            'privacy_acknowledged_at' => now(),
        ];
    }

    public function role(string $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function admin(): static
    {
        return $this->role(User::ROLE_ADMIN);
    }

    public function hrStaff(): static
    {
        return $this->role(User::ROLE_HR_STAFF);
    }

    public function supervisor(): static
    {
        return $this->role(User::ROLE_SUPERVISOR);
    }

    public function withoutPrivacyAcknowledgement(): static
    {
        return $this->state(fn () => [
            'privacy_notice_version' => null,
            'privacy_acknowledged_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
