<?php

namespace Database\Factories;

use App\Models\Comic;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Post>
     */
    protected $model = Post::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'comic_id' => null,
            'content' => fake()->paragraph(),
            'image_path' => null,
            'likes_count' => 0,
            'comments_count' => 0,
        ];
    }

    /**
     * Associate post with a specific comic.
     */
    public function forComic(Comic $comic): static
    {
        return $this->state(fn (array $attributes) => [
            'comic_id' => $comic->id,
        ]);
    }
}
