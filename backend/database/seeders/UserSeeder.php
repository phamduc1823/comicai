<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Safety guard: Never seed demo accounts in production environment
        if (app()->environment('production')) {
            $this->command?->warn('UserSeeder skipped: Demo accounts cannot be created in production.');

            return;
        }

        $demoPassword = env('SEEDER_DEMO_PASSWORD', 'DemoPass123!@#');
        $hashedPassword = Hash::make($demoPassword);

        $users = [
            [
                'email' => 'admin@bluetoon.local',
                'name' => 'Quản Trị Viên',
                'role' => 'admin',
                'avatar_path' => 'placeholders/avatars/admin.webp',
                'is_active' => true,
            ],
            [
                'email' => 'creator@bluetoon.local',
                'name' => 'Họa Sĩ Minh Trí',
                'role' => 'creator',
                'avatar_path' => 'placeholders/avatars/creator.webp',
                'is_active' => true,
            ],
            [
                'email' => 'reader@bluetoon.local',
                'name' => 'Độc Giả Thân Thiết',
                'role' => 'reader',
                'avatar_path' => 'placeholders/avatars/reader.webp',
                'is_active' => true,
            ],
        ];

        foreach ($users as $userData) {
            $user = User::firstOrNew(['email' => $userData['email']]);
            $user->name = $userData['name'];
            $user->password = $hashedPassword;
            $user->avatar_path = $userData['avatar_path'];

            // 'role' and 'is_active' are guarded against mass-assignment; set directly on model
            $user->role = $userData['role'];
            $user->is_active = $userData['is_active'];

            if (! $user->email_verified_at) {
                $user->email_verified_at = now();
            }

            $user->save();
        }
    }
}
