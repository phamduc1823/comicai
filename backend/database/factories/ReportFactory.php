<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Report>
     */
    protected $model = Report::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reporter_id' => User::factory(),
            'reportable_type' => 'post',
            'reportable_id' => Post::factory(),
            'reason' => 'spam',
            'description' => fake()->sentence(),
            'status' => 'pending',
            'resolved_by' => null,
            'resolved_at' => null,
            'admin_notes' => null,
        ];
    }

    /**
     * Mark the report as resolved by an admin.
     */
    public function resolved(User $admin, ?string $notes = 'Đã xử lý vi phạm.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'resolved',
            'resolved_by' => $admin->id,
            'resolved_at' => now(),
            'admin_notes' => $notes,
        ]);
    }

    /**
     * Mark the report as dismissed by an admin.
     */
    public function dismissed(User $admin, ?string $notes = 'Không vi phạm quy chuẩn.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'dismissed',
            'resolved_by' => $admin->id,
            'resolved_at' => now(),
            'admin_notes' => $notes,
        ]);
    }
}
