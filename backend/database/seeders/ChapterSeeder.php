<?php

namespace Database\Seeders;

use App\Models\Chapter;
use App\Models\ChapterImage;
use App\Models\Comic;
use Illuminate\Database\Seeder;
use RuntimeException;

class ChapterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $chaptersData = [
            'huyen-thoai-kiem-vuong' => [
                [
                    'chapter_number' => '1.00',
                    'title' => 'Chương 1: Thức tỉnh trong tro tàn',
                    'slug' => 'chuong-1-thuc-tinh-trong-tro-tan',
                    'status' => 'published',
                    'views_count' => 6800,
                ],
                [
                    'chapter_number' => '2.00',
                    'title' => 'Chương 2: Lưỡi kiếm đầu tiên',
                    'slug' => 'chuong-2-luoi-kiem-dau-tien',
                    'status' => 'published',
                    'views_count' => 5700,
                ],
            ],
            'anh-sang-ban-mai' => [
                [
                    'chapter_number' => '1.00',
                    'title' => 'Chương 1: Giai điệu dưới mưa',
                    'slug' => 'chuong-1-giai-dieu-duoi-mua',
                    'status' => 'published',
                    'views_count' => 4800,
                ],
                [
                    'chapter_number' => '2.00',
                    'title' => 'Chương 2: Phím đàn rung động',
                    'slug' => 'chuong-2-phim-dan-rung-dong',
                    'status' => 'published',
                    'views_count' => 4100,
                ],
            ],
            'tiem-tra-mua-ha' => [
                [
                    'chapter_number' => '1.00',
                    'title' => 'Chương 1: Mùi hương sen sớm',
                    'slug' => 'chuong-1-mui-huong-sen-som',
                    'status' => 'published',
                    'views_count' => 3100,
                ],
                [
                    'chapter_number' => '2.00',
                    'title' => 'Chương 2: Vị khách bất ngờ',
                    'slug' => 'chuong-2-vi-khach-bat-ngo',
                    'status' => 'published',
                    'views_count' => 2700,
                ],
            ],
        ];

        foreach ($chaptersData as $comicSlug => $chapters) {
            $comic = Comic::where('slug', $comicSlug)->first();

            if (! $comic) {
                throw new RuntimeException("Comic '{$comicSlug}' not found. Ensure ComicSeeder runs before ChapterSeeder.");
            }

            foreach ($chapters as $cData) {
                $chapter = Chapter::firstOrNew([
                    'comic_id' => $comic->id,
                    'chapter_number' => $cData['chapter_number'],
                ]);

                $chapter->title = $cData['title'];
                $chapter->slug = $cData['slug'];
                $chapter->status = $cData['status'];

                // Explicitly set protected attribute
                $chapter->views_count = $cData['views_count'];

                $chapter->save();

                // Create 4 webtoon page images per chapter
                for ($page = 1; $page <= 4; $page++) {
                    ChapterImage::updateOrCreate(
                        [
                            'chapter_id' => $chapter->id,
                            'page_number' => $page,
                        ],
                        [
                            'image_path' => sprintf(
                                'placeholders/chapters/%s/c%d_p%02d.webp',
                                $comic->slug,
                                (int) $chapter->chapter_number,
                                $page
                            ),
                            'width' => 800,
                            'height' => 1280,
                        ]
                    );
                }
            }
        }
    }
}
