<?php

use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\PasswordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| What is left after Fortify
|--------------------------------------------------------------------------
| Signing in and signing out are Fortify's. See
| App\Providers\FortifyServiceProvider for the views.
|
| There is no forgot-password link and no email verification: login accounts
| are a username and a role, with no email address behind them. A forgotten
| password is reset by an administrator on Settings > Users & Access.
|
| PUT /password stays hand-written — changing your own password.
|
| Self-registration stays deliberately absent. This is an internal HRIS:
| accounts are provisioned by HR from the employee record, which links the
| login to a 201 file and assigns the correct role. `Features::registration()`
| is switched off in config/fortify.php for the same reason — installing
| Fortify briefly reopened /register, which is exactly the door this comment
| has always been about.
*/

/*
 * Fallback for non-GET/POST HTTP verbs on login.
 *
 * When a session expires and an AJAX or Inertia PUT/PATCH/DELETE request is
 * redirected to /login, some browsers follow the 302 keeping the original
 * request method. Redirecting with 303 forces the browser to use GET.
 */
Route::match(['put', 'patch', 'delete'], 'login', function () {
    return redirect()->route('login', [], 303);
});

Route::middleware('auth')->group(function () {

    /*
     * Named `password.change`, not `password.update`.
     *
     * Fortify names its reset-password POST `password.update`, and two routes
     * cannot share a name: `route('password.update')` silently resolved to
     * Fortify's /reset-password once both existed. Renaming this one is the
     * safe half of the fix — nothing in the app looked the name up, only the
     * URL, which is unchanged.
     */
    Route::put('password', [PasswordController::class, 'update'])->name('password.change');
});

/*
|--------------------------------------------------------------------------
| The sign-in code
|--------------------------------------------------------------------------
| Inside `auth`, because the code is the *second* factor: the password has
| already been accepted and `RequireOtp` is holding the session here.
|
| Both actions are throttled on top of the service's own limits. The service
| burns a code after five wrong answers and paces resends, which stops the
| code being brute-forced; the throttles stop the *endpoint* being hammered
| by a script that does not care about codes at all.
*/
Route::middleware('auth')->group(function () {
    Route::get('otp', [OtpController::class, 'show'])->name('otp.challenge');

    Route::post('otp', [OtpController::class, 'verify'])
        ->middleware('throttle:12,1')
        ->name('otp.verify');

    Route::post('otp/resend', [OtpController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('otp.resend');
});

/*
|--------------------------------------------------------------------------
| Idle sign-out
|--------------------------------------------------------------------------
| The countdown lives in the browser; the enforcement lives in
| `config/session.php`. These two routes are what keep the two in step.
*/
Route::middleware('auth')->group(function () {
    /*
     * Signs out and lands on the login screen *saying why*.
     *
     * A separate route from Fortify's /logout rather than a flag on it: this
     * one has a message to leave behind, and Fortify's redirects to `/`. An
     * unexplained login screen reads as the system having crashed, which is
     * the reaction that gets a timeout switched off.
     */
    Route::post('logout/idle', function (Request $request) {
        $minutes = (int) config('session.lifetime');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('status', "You were signed out after {$minutes} minutes of inactivity.");
    })->name('logout.idle');

    /*
     * Touched while somebody is active but not navigating.
     *
     * Reading a long report is activity to the person doing it and silence to
     * the server, so without this the browser would keep its countdown alive
     * while the session behind it expired anyway — and the sign-out would
     * arrive on the next click, from a screen that said nothing was wrong.
     * Laravel writes `last_activity` on every request, so an empty 204 is the
     * whole job.
     */
    Route::get('session/keepalive', fn () => response()->noContent())
        ->name('session.keepalive');
});
