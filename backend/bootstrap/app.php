<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireOtp;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // After HandleInertiaRequests, so the redirect it issues is still
            // an Inertia response rather than a full page load.
            RequirePasswordChange::class,
            /*
             * After RequirePasswordChange, and the order is the argument.
             *
             * A provisioned password is known to somebody else by
             * construction, so the code would be a second factor guarding a
             * first one that is already shared. Replacing the password comes
             * first; proving the inbox comes second.
             */
            RequireOtp::class,
        ]);

        // Both stacks: the API serves JSON to biometric devices and
        // integrations, and nosniff matters as much there as on a screen.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
