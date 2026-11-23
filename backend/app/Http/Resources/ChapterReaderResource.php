<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChapterReaderResource extends JsonResource
{
    /**
     * The navigation context (prev/next chapter).
     *
     * @var array{prev_chapter: ?array, next_chapter: ?array}|null
     */
    protected ?array $navigation;

    /**
     * VIP and access context.
     *
     * @var array<string, mixed>
     */
    protected array $vipContext;

    /**
     * Create a new resource instance.
     *
     * @param mixed $resource
     * @param array{prev_chapter: ?array, next_chapter: ?array}|null $navigation
     * @param array<string, mixed> $vipContext
     */
    public function __construct($resource, ?array $navigation = null, array $vipContext = [])
    {
        parent::__construct($resource);
        $this->navigation = $navigation;
        $this->vipContext = $vipContext;
    }

    /**
     * Transform the resource into an array.
     * Whitelists chapter reader view including comic context, pages, and navigation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isVip = (bool) $this->is_vip;
        $isUnlocked = $this->vipContext['is_unlocked'] ?? (! $isVip);

        $data = [
            'id' => $this->id,
            'chapter_number' => (string) $this->chapter_number,
            'title' => $this->title,
            'slug' => $this->slug,
            'is_vip' => $isVip,
            'is_unlocked' => $isUnlocked,
            'price' => (int) ($this->price ?? 0),
            'views_count' => (int) $this->views_count,
            'comic' => $this->whenLoaded('comic', function () {
                return [
                    'id' => $this->comic->id,
                    'title' => $this->comic->title,
                    'slug' => $this->comic->slug,
                ];
            }),
            'images' => $isUnlocked
                ? ChapterImageResource::collection($this->whenLoaded('images'))
                : [],
            'prev_chapter' => $this->navigation['prev_chapter'] ?? null,
            'next_chapter' => $this->navigation['next_chapter'] ?? null,
            'created_at' => $this->created_at?->toISOString(),
        ];

        if (isset($this->vipContext['bypass_reason'])) {
            $data['bypass_reason'] = $this->vipContext['bypass_reason'];
        }

        if (array_key_exists('user_balance', $this->vipContext)) {
            $data['user_balance'] = $this->vipContext['user_balance'];
        }

        return $data;
    }
}
