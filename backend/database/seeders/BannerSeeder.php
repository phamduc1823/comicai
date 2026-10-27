<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\Comic;
use Illuminate\Database\Seeder;
use RuntimeException;

class BannerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $banners = [
            [
                'comic_slug' => 'huyen-thoai-kiem-vuong',
                'title_override' => 'Siêu phẩm Huyền Thoại Kiếm Vương chính thức ra mắt',
                'desktop_banner_path' => 'placeholders/banners/kiem-vuong-desktop.webp',
                'mobile_banner_path' => 'placeholders/banners/kiem-vuong-mobile.webp',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'comic_slug' => 'anh-sang-ban-mai',
                'title_override' => 'Khúc ca thanh xuân ngọt ngào cùng Ánh Sáng Ban Mai',
                'desktop_banner_path' => 'placeholders/banners/ban-mai-desktop.webp',
                'mobile_banner_path' => 'placeholders/banners/ban-mai-mobile.webp',
                'sort_order' => 2,
                'is_active' => true,
            ],
        ];

        foreach ($banners as $bData) {
            $comic = Comic::where('slug', $bData['comic_slug'])->first();

            if (! $comic) {
                throw new RuntimeException("Comic '{$bData['comic_slug']}' not found. Ensure ComicSeeder runs before BannerSeeder.");
            }

            Banner::updateOrCreate(
                [
                    'comic_id' => $comic->id,
                    'desktop_banner_path' => $bData['desktop_banner_path'],
                ],
                [
                    'title_override' => $bData['title_override'],
                    'mobile_banner_path' => $bData['mobile_banner_path'],
                    'sort_order' => $bData['sort_order'],
                    'is_active' => $bData['is_active'],
                ]
            );
        }
    }
}
