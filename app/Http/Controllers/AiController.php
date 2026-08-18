<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\HrAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The assistant endpoint.
 *
 * Rate limited per user: each call costs money at the provider, and a stuck
 * retry loop in a browser tab should not be able to run up a bill.
 */
class AiController extends Controller
{
    /** Answers one question against data the asker is allowed to see. */
    public function ask(Request $request, HrAssistant $assistant): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        $key = 'ai-ask:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, 30)) {
            return response()->json([
                'message' => 'Too many requests. Try again in a few minutes.',
            ], 429);
        }

        RateLimiter::hit($key, 3600);

        try {
            return response()->json(
                $assistant->ask($request->user(), $validated['question']),
            );
        } catch (AiUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'The assistant could not answer that right now.',
            ], 502);
        }
    }
}
