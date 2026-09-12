<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    /*
     * TwoFactorAuthenticatable brings the secret, the recovery codes, and the
     * `hasEnabledTwoFactorAuthentication()` the login flow checks. It does
     * *not* hide either column — see `$hidden` below, which is where that has
     * to be said, and which a test rather than a reading is what established.
     */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_HR_STAFF = 'hr_staff';

    public const ROLE_SUPERVISOR = 'supervisor';

    public const ROLE_EMPLOYEE = 'employee';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_HR_STAFF,
        self::ROLE_SUPERVISOR,
        self::ROLE_EMPLOYEE,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'must_change_password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',

        /*
         * The second factor itself.
         *
         * Listed here rather than assumed: `TwoFactorAuthenticatable` brings
         * the behaviour but not the hiding — the starter kits add these two,
         * and this project has no starter kit. Without them the secret and the
         * recovery codes serialise like any other column, and the Settings
         * screen puts the signed-in user's account into an Inertia payload —
         * so they would have travelled to the browser, into the page cache,
         * and into browser history, on every visit.
         *
         * Caught by a test rather than by review, which is the only reason it
         * is not still true.
         */
        'two_factor_secret',
        'two_factor_recovery_codes',

        /*
         * The emailed factor's outstanding code.
         *
         * A hash rather than the code itself, so this is the same class of
         * thing as `password` two lines up and belongs in the same list for
         * the same reason: the Settings screen serialises the signed-in user's
         * own account, and nothing about a live sign-in code needs to reach
         * the browser, the page cache, or browser history.
         */
        'otp_code_hash',
    ];

    /**
     * A temporary password for a login somebody else is provisioning — HR
     * creating a self-service account, an admin adding a user.
     *
     * `Str::password()` draws from a pool that contains every character class
     * but guarantees none of them: a 12-character draw comes out all-lowercase
     * often enough to matter, and that password then fails the very policy
     * AppServiceProvider enforces when the user tries to change it. This seeds
     * one character from each required class, fills the rest at random, and
     * shuffles, so what HR reads out is always a password the user can keep.
     */
    public static function generatePassword(int $length = 16): string
    {
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $symbols = '!@#$%^&*-_=+';

        $pool = $lower.$upper.$digits.$symbols;

        // One guaranteed character per class the policy requires.
        $characters = [
            $lower[random_int(0, strlen($lower) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];

        for ($i = count($characters); $i < $length; $i++) {
            $characters[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        // Without the shuffle the classes would always appear in a fixed
        // order, which is a pattern worth not handing out.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    /** The 201 file belonging to this login, when the user is also an employee. */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /** Admin and HR staff both administer HR records. */
    public function isHrAdmin(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN, self::ROLE_HR_STAFF);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN);
    }

    public function isSupervisor(): bool
    {
        return $this->hasRole(self::ROLE_SUPERVISOR);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'otp_enabled' => 'boolean',
            'otp_expires_at' => 'datetime',
            'otp_sent_at' => 'datetime',
        ];
    }
}
