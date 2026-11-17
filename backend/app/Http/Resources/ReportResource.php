<?php

namespace App\Http\Resources;

use App\Models\Chapter;
use App\Models\Comic;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reporter' => $this->reporter ? [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
                'avatar_url' => $this->reporter->avatar_path ? Storage::disk('public')->url($this->reporter->avatar_path) : null,
                'role' => $this->reporter->role,
            ] : null,
            'reportable_type' => $this->reportable_type,
            'reportable_id' => (int) $this->reportable_id,
            'target' => $this->formatReportableTarget($this->reportable, $this->reportable_type, (int) $this->reportable_id),
            'reason' => $this->reason,
            'description' => $this->description,
            'status' => $this->status,
            'resolver' => $this->resolver ? [
                'id' => $this->resolver->id,
                'name' => $this->resolver->name,
                'avatar_url' => $this->resolver->avatar_path ? Storage::disk('public')->url($this->resolver->avatar_path) : null,
                'role' => $this->resolver->role,
            ] : null,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'admin_notes' => $this->admin_notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Safely format the reported polymorphic entity.
     * Handles deleted or non-existent records gracefully without 500 error.
     * Never exposes sensitive user credentials or internal secrets.
     */
    private function formatReportableTarget(?Model $target, string $type, int $id): ?array
    {
        if (! $target) {
            return [
                'id' => $id,
                'type' => $type,
                'exists' => false,
                'summary' => 'Nội dung đã bị xóa hoặc không tồn tại.',
            ];
        }

        return match ($type) {
            'post' => [
                'id' => $target->id,
                'type' => 'post',
                'exists' => true,
                'content' => Str::limit($target->content, 200),
                'user_id' => $target->user_id,
                'has_image' => ! empty($target->image_path),
            ],
            'comment' => [
                'id' => $target->id,
                'type' => 'comment',
                'exists' => true,
                'content' => Str::limit($target->content, 200),
                'user_id' => $target->user_id,
                'parent_id' => $target->parent_id,
            ],
            'comic' => [
                'id' => $target->id,
                'type' => 'comic',
                'exists' => true,
                'title' => $target->title,
                'slug' => $target->slug,
                'approval_status' => $target->approval_status,
            ],
            'chapter' => [
                'id' => $target->id,
                'type' => 'chapter',
                'exists' => true,
                'comic_id' => $target->comic_id,
                'chapter_number' => $target->chapter_number,
                'title' => $target->title,
                'status' => $target->status,
            ],
            'user' => [
                'id' => $target->id,
                'type' => 'user',
                'exists' => true,
                'name' => $target->name,
                'role' => $target->role,
                'avatar_url' => $target->avatar_path ? Storage::disk('public')->url($target->avatar_path) : null,
            ],
            default => [
                'id' => $id,
                'type' => $type,
                'exists' => true,
            ],
        };
    }
}
