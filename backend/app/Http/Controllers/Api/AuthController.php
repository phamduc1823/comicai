<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user account.
     * Role defaults to 'reader'; ignores client-provided role/is_active.
     * Automatically logs in the new user and regenerates session.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'status' => 'success',
            'message' => 'Đăng ký tài khoản thành công.',
            'user' => new UserResource($user),
        ], 201);
    }

    /**
     * Authenticate an existing user via Sanctum SPA cookie/session.
     * Uses a unified error message for wrong password, missing account, and inactive accounts.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::guard('web')->attempt(
            [
                'email' => $credentials['email'],
                'password' => $credentials['password'],
                'is_active' => true,
            ],
            $request->boolean('remember')
        )) {
            throw ValidationException::withMessages([
                'email' => ['Thông tin đăng nhập không chính xác hoặc tài khoản không khả dụng.'],
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        return response()->json([
            'status' => 'success',
            'message' => 'Đăng nhập thành công.',
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Log out the current user, invalidate session, and regenerate CSRF token.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Đăng xuất thành công.',
        ]);
    }

    /**
     * Return authenticated user profile.
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'user' => new UserResource($request->user()),
        ]);
    }
}
