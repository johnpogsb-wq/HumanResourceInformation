<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers that close off attacks the application code cannot.
 *
 * Deliberately *not* a full Content-Security-Policy. A real script-src policy
 * needs a nonce threaded through the Vite tags and the Inertia root, and a
 * half-written one either breaks the payslip print view or is loose enough to
 * be decorative. The three CSP directives set here constrain framing, plugins,
 * and `<base>` — none of which can break a script or a stylesheet — so they
 * are safe to apply unconditionally. A scripted policy is a separate job.
 */
class SecurityHeaders
{
    /**
     * Static headers applied to every response.
     *
     * @var array<string, string>
     */
    private const HEADERS = [
        // The HRIS has no reason to be framed by anything, and being framed is
        // how a clickjack gets an HR user to approve a payroll run they cannot
        // see. X-Frame-Options for older browsers, frame-ancestors for current
        // ones — they mean the same thing to the two generations.
        'X-Frame-Options' => 'DENY',

        // Stops a browser second-guessing the Content-Type on a 201-file
        // download and running an uploaded file as script.
        'X-Content-Type-Options' => 'nosniff',

        // An employee ID is in the path of most screens here; a full referrer
        // would leak it to any external link a user follows.
        'Referrer-Policy' => 'strict-origin-when-cross-origin',

        // Nothing in this system uses a camera, a microphone, or location.
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',

        'Content-Security-Policy' => "frame-ancestors 'none'; object-src 'none'; base-uri 'self'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $header => $value) {
            $response->headers->set($header, $value);
        }

        // Only over TLS. Sent on a plain-HTTP response it is ignored by the
        // browser anyway, and this app is served over http://core2.test in
        // development — pinning that host to HTTPS would lock the developer
        // out of their own machine for the max-age.
        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
