<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Personal security: password, active API tokens, and — for HR — the audit log.
 *
 * This is what replaced the starter kit's profile page.
 */
class SecurityController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('managePersonal', Setting::class);

        $user = $request->user();
        $canViewAudit = $request->user()->can('viewAuditLog', Setting::class);

        return Inertia::render('Settings/Security', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'email_verified' => $user->email_verified_at !== null,
                'created_at' => $user->created_at?->toDateString(),
            ],

            'tokens' => $user->tokens()
                ->latest('id')
                ->get()
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ]),

            // Recent activity on this account, so a user can spot what they did
            // not do. HR sees the whole log.
            'auditLog' => $canViewAudit
                ? AuditLog::with('user:id,name')
                    ->latest('id')
                    ->limit(50)
                    ->get()
                    ->map(fn (AuditLog $entry) => [
                        'id' => $entry->id,
                        'event' => $entry->event,
                        'subject' => class_basename($entry->auditable_type),
                        'subject_id' => $entry->auditable_id,
                        'user' => $entry->user?->name ?? 'System',
                        'changed' => array_keys($entry->new_values ?? []),
                        'ip_address' => $entry->ip_address,
                        'created_at' => $entry->created_at?->toIso8601String(),
                    ])
                : [],

            'canViewAudit' => $canViewAudit,
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        Gate::authorize('managePersonal', Setting::class);

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ], [
            'current_password.current_password' => 'That is not your current password.',
        ]);

        $request->user()->update(['password' => $validated['password']]);

        return back()->with('success', 'Password updated.');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        Gate::authorize('managePersonal', Setting::class);

        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                'unique:users,email,'.$user->id,
            ],
        ]);

        $emailChanged = $validated['email'] !== $user->email;

        $user->fill($validated);

        // A changed address has to be proven again before it is trusted. Set
        // outside the fillable payload — email_verified_at is guarded, so mass
        // assignment would drop it silently.
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        return back()->with('success', 'Profile updated.');
    }

    /** Signs every other session out — the "I lost my laptop" button. */
    public function revokeTokens(Request $request): RedirectResponse
    {
        Gate::authorize('managePersonal', Setting::class);

        $count = $request->user()->tokens()->count();

        $request->user()->tokens()->delete();

        return back()->with('success', "Revoked {$count} API token(s).");
    }

    public function revokeToken(Request $request, int $tokenId): RedirectResponse
    {
        Gate::authorize('managePersonal', Setting::class);

        $request->user()->tokens()->whereKey($tokenId)->delete();

        return back()->with('success', 'Token revoked.');
    }

    public function destroyAccount(Request $request): RedirectResponse
    {
        Gate::authorize('managePersonal', Setting::class);

        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();

        // The last administrator cannot leave — nobody would be able to
        // configure the application or approve payroll.
        if ($user->isAdmin() && User::where('role', User::ROLE_ADMIN)->where('is_active', true)->count() <= 1) {
            return back()->with('error', 'You are the only active administrator — promote someone else first.');
        }

        Auth::logout();
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
