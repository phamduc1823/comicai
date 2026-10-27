<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ChapterImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Whitelists page image fields for reader view.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'page_number' => (int) $this->page_number,
            'image_path' => $this->image_path,
            'image_url' => $this->image_path
                ? Storage::disk('public')->url($this->image_path)
                : null,
            'width' => $this->width !== null ? (int) $this->width : null,
            'height' => $this->height !== null ? (int) $this->height : null,
        ];
    }
}
