<?php

namespace Tests\Feature;

use App\Models\Comic;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessageAttachmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }
        Storage::fake('public');
    }

    private function createConversationBetween(User $userA, User $userB): Conversation
    {
        $conv = Conversation::create([
            'type' => 'direct',
            'user_one_id' => min($userA->id, $userB->id),
            'user_two_id' => max($userA->id, $userB->id),
            'status' => 'accepted',
        ]);
        ConversationParticipant::create(['conversation_id' => $conv->id, 'user_id' => $userA->id]);
        ConversationParticipant::create(['conversation_id' => $conv->id, 'user_id' => $userB->id]);
        return $conv;
    }

    private function createComic(array $attributes = []): Comic
    {
        $creator = User::factory()->create();
        return Comic::create(array_merge([
            'title' => 'Võ Thần Đại Lục',
            'slug' => 'vo-than-dai-luc-' . uniqid(),
            'author_name' => 'Tác giả A',
            'user_id' => $creator->id,
            'description' => 'Mô tả bộ truyện',
            'cover_path' => 'comics/covers/test.webp',
        ], $attributes));
    }

    public function test_can_send_message_with_image_attachments(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $conv = $this->createConversationBetween($userA, $userB);

        $image1 = UploadedFile::fake()->image('test1.jpg', 600, 600)->size(500);
        $image2 = UploadedFile::fake()->image('test2.png', 400, 400)->size(300);

        $res = $this->actingAs($userA, 'sanctum')
            ->post("/api/chat/conversations/{$conv->id}/messages", [
                'body' => 'Xem ảnh này nè!',
                'images' => [$image1, $image2],
            ], [
                'Accept' => 'application/json',
            ]);

        $res->assertCreated()
            ->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.body', 'Xem ảnh này nè!')
            ->assertJsonCount(2, 'data.attachments.images');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'sender_id' => $userA->id,
            'type' => 'image',
        ]);
    }

    public function test_can_send_message_with_comic_attachment(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $conv = $this->createConversationBetween($userA, $userB);

        $comic = $this->createComic([
            'title' => 'Võ Thần Đại Lục',
            'slug' => 'vo-than-dai-luc',
            'author_name' => 'Tác giả A',
        ]);

        $res = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conv->id}/messages", [
                'body' => 'Bộ này hay cực kỳ luôn!',
                'comic_id' => $comic->id,
            ]);

        $res->assertCreated()
            ->assertJsonPath('data.type', 'comic')
            ->assertJsonPath('data.attachments.comic.id', $comic->id)
            ->assertJsonPath('data.attachments.comic.title', 'Võ Thần Đại Lục')
            ->assertJsonPath('data.attachments.comic.slug', 'vo-than-dai-luc')
            ->assertJsonPath('data.attachments.comic.author_name', 'Tác giả A')
            ->assertJsonPath('data.attachments.comic.is_unavailable', false);
    }

    public function test_can_send_message_with_post_attachment(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $conv = $this->createConversationBetween($userA, $userB);

        $author = User::factory()->create(['name' => 'Người Viết']);
        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'Thảo luận về chương mới nhất của bộ truyện!',
        ]);

        $res = $this->actingAs($userA, 'sanctum')
            ->postJson("/api/chat/conversations/{$conv->id}/messages", [
                'body' => 'Bài viết này thú vị lắm:',
                'post_id' => $post->id,
            ]);

        $res->assertCreated()
            ->assertJsonPath('data.type', 'post')
            ->assertJsonPath('data.attachments.post.id', $post->id)
            ->assertJsonPath('data.attachments.post.content', 'Thảo luận về chương mới nhất của bộ truyện!')
            ->assertJsonPath('data.attachments.post.user.name', 'Người Viết')
            ->assertJsonPath('data.attachments.post.is_unavailable', false);
    }

    public function test_validates_image_file_count_and_size(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $conv = $this->createConversationBetween($userA, $userB);

        // Exceed max 5 images
        $images = [];
        for ($i = 0; $i < 6; $i++) {
            $images[] = UploadedFile::fake()->image("img_{$i}.png")->size(100);
        }

        $resCount = $this->actingAs($userA, 'sanctum')
            ->post("/api/chat/conversations/{$conv->id}/messages", [
                'images' => $images,
            ], [
                'Accept' => 'application/json',
            ]);
        $resCount->assertUnprocessable()
            ->assertJsonValidationErrors(['images']);

        // Exceed 5MB per file (5121 KB)
        $largeImage = UploadedFile::fake()->image('big.png')->size(5200);
        $resSize = $this->actingAs($userA, 'sanctum')
            ->post("/api/chat/conversations/{$conv->id}/messages", [
                'images' => [$largeImage],
            ], [
                'Accept' => 'application/json',
            ]);
        $resSize->assertUnprocessable()
            ->assertJsonValidationErrors(['images.0']);
    }

    public function test_deleted_comic_or_post_is_handled_gracefully(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $conv = $this->createConversationBetween($userA, $userB);

        $comic = $this->createComic();
        $comicId = $comic->id;

        Message::create([
            'conversation_id' => $conv->id,
            'sender_id' => $userA->id,
            'type' => 'comic',
            'body' => '',
            'comic_id' => $comicId,
        ]);

        // Delete the comic
        $comic->delete();

        // Query conversation messages
        $res = $this->actingAs($userB, 'sanctum')
            ->getJson("/api/chat/conversations/{$conv->id}/messages");

        $res->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attachments.comic.is_unavailable', true);
    }
}
