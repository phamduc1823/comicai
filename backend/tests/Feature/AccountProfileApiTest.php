<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_own_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Nguyễn Văn A',
            'email' => 'user_a@bluetoon.local',
            'role' => 'reader',
        ]);

        $response = $this->actingAs($user)->getJson('/api/user/profile');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', 'Nguyễn Văn A')
            ->assertJsonPath('user.email', 'user_a@bluetoon.local')
            ->assertJsonPath('user.role', 'reader');
    }

    public function test_unauthenticated_user_cannot_access_profile_endpoints(): void
    {
        $this->getJson('/api/user/profile')->assertStatus(401);
        $this->putJson('/api/user/profile', ['name' => 'Hacker'])->assertStatus(401);
        $this->postJson('/api/user/avatar')->assertStatus(401);
        $this->deleteJson('/api/user/avatar')->assertStatus(401);
        $this->putJson('/api/user/password', ['password' => 'newpassword123'])->assertStatus(401);
    }

    public function test_user_can_update_name_successfully(): void
    {
        $user = User::factory()->create([
            'name' => 'Tên Cũ',
            'email' => 'reader@bluetoon.local',
        ]);

        $response = $this->actingAs($user)->putJson('/api/user/profile', [
            'name' => 'Tên Mới Được Cập Nhật',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.name', 'Tên Mới Được Cập Nhật');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Tên Mới Được Cập Nhật',
        ]);
    }

    public function test_user_cannot_privilege_escalate_role_or_active_status(): void
    {
        $user = User::factory()->create([
            'name' => 'Độc Giả Thường',
            'role' => 'reader',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->putJson('/api/user/profile', [
            'name' => 'Độc Giả',
            'role' => 'admin',
            'is_active' => false,
            'id' => 9999,
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals('reader', $user->role);
        $this->assertTrue((bool) $user->is_active);
    }

    public function test_update_profile_validation_rejects_empty_name_or_invalid_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/user/profile', [
            'name' => '',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['name']);

        $this->actingAs($user)->putJson('/api/user/profile', [
            'name' => 'Valid Name',
            'email' => 'invalid-email-format',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['email']);
    }

    public function test_update_profile_validation_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@bluetoon.local']);
        $user = User::factory()->create(['email' => 'myemail@bluetoon.local']);

        $this->actingAs($user)->putJson('/api/user/profile', [
            'name' => $user->name,
            'email' => 'existing@bluetoon.local',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['email']);
    }

    public function test_updating_email_allowed_for_unverified_user(): void
    {
        $user = User::factory()->create([
            'email' => 'original@bluetoon.local',
            'email_verified_at' => null,
        ]);

        $this->actingAs($user)->putJson('/api/user/profile', [
            'name' => $user->name,
            'email' => 'new_email@bluetoon.local',
        ])->assertStatus(200);

        $user->refresh();
        $this->assertEquals('new_email@bluetoon.local', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_cannot_update_email_when_already_verified(): void
    {
        $user = User::factory()->create([
            'email' => 'verified@bluetoon.local',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->putJson('/api/user/profile', [
            'name' => $user->name,
            'email' => 'hacker_email@bluetoon.local',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $user->refresh();
        $this->assertEquals('verified@bluetoon.local', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_upload_valid_avatar_stores_file_and_updates_user(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'avatar_path' => null,
        ]);

        $file = UploadedFile::fake()->image('my-avatar.png', 400, 400)->size(500); // 500KB

        $response = $this->actingAs($user)->postJson('/api/user/avatar', [
            'avatar' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_upload_avatar_replaces_and_deletes_old_custom_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        // 1. Upload first avatar
        $file1 = UploadedFile::fake()->image('avatar1.jpg', 200, 200)->size(300);
        $this->actingAs($user)->postJson('/api/user/avatar', ['avatar' => $file1])->assertStatus(200);

        $user->refresh();
        $firstPath = $user->avatar_path;
        Storage::disk('public')->assertExists($firstPath);

        // 2. Upload second avatar
        $file2 = UploadedFile::fake()->image('avatar2.png', 300, 300)->size(400);
        $this->actingAs($user)->postJson('/api/user/avatar', ['avatar' => $file2])->assertStatus(200);

        $user->refresh();
        $secondPath = $user->avatar_path;
        $this->assertNotEquals($firstPath, $secondPath);
        Storage::disk('public')->assertExists($secondPath);
        Storage::disk('public')->assertMissing($firstPath);
    }

    public function test_upload_avatar_rejects_non_image_files(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $file = UploadedFile::fake()->create('malicious.php', 10, 'text/x-php');

        $this->actingAs($user)->postJson('/api/user/avatar', [
            'avatar' => $file,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['avatar']);
    }

    public function test_upload_avatar_rejects_oversized_files(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $largeFile = UploadedFile::fake()->image('huge.jpg', 2000, 2000)->size(3000); // 3MB > 2MB limit

        $this->actingAs($user)->postJson('/api/user/avatar', [
            'avatar' => $largeFile,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['avatar']);
    }

    public function test_delete_avatar_removes_file_and_clears_path(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $file = UploadedFile::fake()->image('avatar.webp', 200, 200);
        $this->actingAs($user)->postJson('/api/user/avatar', ['avatar' => $file])->assertStatus(200);

        $user->refresh();
        $path = $user->avatar_path;
        Storage::disk('public')->assertExists($path);

        $response = $this->actingAs($user)->deleteJson('/api/user/avatar');
        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $user->refresh();
        $this->assertNull($user->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_user_can_update_password_with_correct_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!'),
        ]);

        $response = $this->actingAs($user)->putJson('/api/user/password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword456!',
            'password_confirmation' => 'NewPassword456!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword456!', $user->password));
    }

    public function test_update_password_fails_with_incorrect_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123!'),
        ]);

        $response = $this->actingAs($user)->putJson('/api/user/password', [
            'current_password' => 'WrongPassword!',
            'password' => 'BrandNewPassword123!',
            'password_confirmation' => 'BrandNewPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_update_password_fails_when_new_password_same_as_current(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CurrentPassword123!'),
        ]);

        $response = $this->actingAs($user)->putJson('/api/user/password', [
            'current_password' => 'CurrentPassword123!',
            'password' => 'CurrentPassword123!',
            'password_confirmation' => 'CurrentPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_update_password_fails_when_confirmation_mismatched(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!'),
        ]);

        $response = $this->actingAs($user)->putJson('/api/user/password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'DifferentConfirmation123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}
