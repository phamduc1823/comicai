<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\UploadAvatarRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountProfileController extends Controller
{
    /**
     * Display the authenticated user's profile and account settings.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'user' => new UserResource($request->user()),
        ]);
    }

    /**
     * Update the authenticated user's profile information (name and optionally email).
     * Prevents mass assignment of role, is_active, user ID, or permissions.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $user->name = $validated['name'];

        if (isset($validated['email']) && $validated['email'] !== $user->email) {
            if ($user->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    'email' => ['Địa chỉ email đã được xác minh và không thể thay đổi.'],
                ]);
            }

            $user->email = $validated['email'];
            // If email changed, reset verified state to enforce secure re-verification
            $user->email_verified_at = null;
        }

        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Cập nhật thông tin cá nhân thành công.',
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Upload and update user avatar image.
     * Enforces file format, size limits, and securely removes old custom avatar.
     */
    public function uploadAvatar(UploadAvatarRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = $request->file('avatar');

        $extension = $file->guessExtension() ?: 'webp';
        $filename = Str::random(40) . '.' . $extension;
        $newPath = $file->storeAs('avatars', $filename, 'public');

        $oldPath = $user->avatar_path;

        try {
            DB::transaction(function () use ($user, $newPath) {
                $user->avatar_path = $newPath;
                $user->save();
            });

            // Clean up old avatar file if it belonged to user and was not a default placeholder
            if ($oldPath && $oldPath !== $newPath && ! str_starts_with($oldPath, 'placeholders/')) {
                if (Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            $user->refresh();

            return response()->json([
                'status' => 'success',
                'message' => 'Cập nhật ảnh đại diện thành công.',
                'user' => new UserResource($user),
                'avatar_url' => Storage::disk('public')->url($newPath),
            ]);
        } catch (\Throwable $e) {
            // Delete newly uploaded file if saving to database failed
            if (Storage::disk('public')->exists($newPath)) {
                Storage::disk('public')->delete($newPath);
            }

            throw $e;
        }
    }

    /**
     * Remove the authenticated user's custom avatar.
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $oldPath = $user->avatar_path;

        if ($oldPath && ! str_starts_with($oldPath, 'placeholders/')) {
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $user->avatar_path = null;
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Đã xóa ảnh đại diện thành công.',
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Update the authenticated user's password.
     * Strictly verifies current password and enforces new password requirements.
     * Never returns password hash or sensitive data.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Mật khẩu hiện tại không chính xác.'],
            ]);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Đổi mật khẩu thành công.',
        ]);
    }
}
