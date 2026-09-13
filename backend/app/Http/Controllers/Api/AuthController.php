<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Exchange credentials for a Sanctum personal access token.
     *
     * Takes a `username`, like the web sign-in. `email` is still accepted for
     * the other ISMERS systems already calling this with one — an account that
     * has no email simply cannot be reached that way.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required_without:email', 'nullable', 'string'],
            'email' => ['required_without:username', 'nullable', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $field = filled($credentials['username'] ?? null) ? 'username' : 'email';
        $value = $field === 'username' ? strtolower($credentials['username']) : $credentials['email'];

        $user = User::where($field, $value)->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                $field => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                $field => ['This account has been deactivated.'],
            ]);
        }

        $token = $user->createToken(
            $credentials['device_name'] ?? 'api-token',
            ["role:{$user->role}"],
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $user->only(['id', 'name', 'username', 'email', 'role']),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->only(['id', 'name', 'username', 'email', 'role', 'is_active']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
