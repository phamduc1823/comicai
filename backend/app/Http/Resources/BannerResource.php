<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class BannerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Whitelists public banner attributes and linked comic summary.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title_override' => $this->title_override,
            'desktop_banner_path' => $this->desktop_banner_path,
            'desktop_banner_url' => $this->desktop_banner_path
                ? Storage::disk('public')->url($this->desktop_banner_path)
                : null,
            'mobile_banner_path' => $this->mobile_banner_path,
            'mobile_banner_url' => $this->mobile_banner_path
                ? Storage::disk('public')->url($this->mobile_banner_path)
                : null,
            'sort_order' => (int) $this->sort_order,
            'comic' => $this->whenLoaded('comic', function () {
                return [
                    'id' => $this->comic->id,
                    'title' => $this->comic->title,
                    'slug' => $this->comic->slug,
                    'cover_path' => $this->comic->cover_path,
                    'cover_url' => $this->comic->cover_path
                        ? Storage::disk('public')->url($this->comic->cover_path)
                        : null,
                ];
            }),
        ];
    }
}
