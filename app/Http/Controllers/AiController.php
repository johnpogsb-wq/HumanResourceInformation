<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\CredentialExtractor;
use App\Services\Ai\HrAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The two AI endpoints.
 *
 * Both are rate limited per user: each call costs money at the provider, and
 * a stuck retry loop in a browser tab should not be able to run up a bill.
 */
class AiController extends Controller
{
    /** Reads a photographed credential and returns the fields for the form to prefill. */
    public function scanCredential(
        Request $request,
        Employee $employee,
        CredentialExtractor $extractor,
    ): JsonResponse {
        Gate::authorize('manageDocuments', $employee);

        $validated = $request->validate([
            'file' => ['required', 'file', 'image', 'max:10240'],
            'type' => ['required', 'string'],
        ]);

        if (! $extractor->scannable($validated['type'])) {
            return response()->json([
                'message' => 'That document type is not scannable.',
            ], 422);
        }

        if ($tooMany = $this->throttle($request, 'ai-scan', 20)) {
            return $tooMany;
        }

        try {
            return response()->json(
                $extractor->extract($request->file('file'), $validated['type']),
            );
        } catch (AiUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (\Throwable) {
            // The manual path still works; say so rather than showing a stack.
            return response()->json([
                'message' => 'The document could not be scanned. Enter the details by hand.',
            ], 502);
        }
    }

    /** Answers one question against data the asker is allowed to see. */
    public function ask(Request $request, HrAssistant $assistant): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        if ($tooMany = $this->throttle($request, 'ai-ask', 30)) {
            return $tooMany;
        }

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

    private function throttle(Request $request, string $action, int $perHour): ?JsonResponse
    {
        $key = "{$action}:{$request->user()->id}";

        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return response()->json([
                'message' => 'Too many requests. Try again in a few minutes.',
            ], 429);
        }

        RateLimiter::hit($key, 3600);

        return null;
    }
}
