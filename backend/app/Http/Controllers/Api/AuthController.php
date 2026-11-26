<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Contracts\WalletServiceInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            try {
                app(WalletServiceInterface::class)->getOrCreateWallet($user);
            } catch (QueryException $e) {
                // If wallets table is not yet migrated in database environment, report warning
                // but do not crash the user registration if the table is physically missing.
                if ($e->getCode() === '42S02' || str_contains($e->getMessage(), "doesn't exist")) {
                    report($e);
                } else {
                    throw $e;
                }
            }

            return $user;
        });

        Auth::guard('web')->login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

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

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

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

    /**
     * Send email verification notification to current user.
     */
    public function sendVerificationNotification(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Email đã được xác minh trước đó.',
            ]);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json([
            'status' => 'success',
            'message' => 'Đã gửi liên kết xác minh vào email của bạn. Vui lòng kiểm tra hộp thư.',
        ]);
    }

    /**
     * Mark user's email as verified via signed link.
     */
    public function verifyEmail(Request $request, int $id, string $hash)
    {
        $user = User::findOrFail($id);

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Liên kết xác minh không hợp lệ.',
            ], 403);
        }

        if ($user->hasVerifiedEmail()) {
            if ($request->wantsJson()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Email đã được xác minh trước đó.',
                ]);
            }
            $frontendUrl = env('FRONTEND_URL', 'http://127.0.0.1:5173');
            return redirect()->to($frontendUrl . '/become-creator?verified=1');
        }

        if ($user->markEmailAsVerified()) {
            event(new \Illuminate\Auth\Events\Verified($user));
        }

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Xác minh email thành công.',
            ]);
        }

        $frontendUrl = env('FRONTEND_URL', 'http://127.0.0.1:5173');
        return redirect()->to($frontendUrl . '/become-creator?verified=1');
    }
}
