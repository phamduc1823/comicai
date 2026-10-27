<?php

namespace Database\Seeders;

use App\Models\Genre;
use Illuminate\Database\Seeder;

class GenreSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $genres = [
            [
                'slug' => 'action',
                'name' => 'Hành Động',
                'description' => 'Các bộ truyện kịch tính, chiến đấu quyết liệt và nghẹt thở.',
            ],
            [
                'slug' => 'romance',
                'name' => 'Tình Cảm',
                'description' => 'Câu chuyện tình yêu lãng mạn, ngọt ngào và sâu lắng.',
            ],
            [
                'slug' => 'fantasy',
                'name' => 'Huyền Huyễn',
                'description' => 'Thế giới phép thuật, thần thoại và năng lực siêu nhiên kỳ ảo.',
            ],
            [
                'slug' => 'comedy',
                'name' => 'Hài Hước',
                'description' => 'Những tình huống vui nhộn, dí dỏm đem lại tiếng cười.',
            ],
            [
                'slug' => 'slice-of-life',
                'name' => 'Đời Thường',
                'description' => 'Khắc họa cuộc sống thường nhật bình dị, chân thật và ấm áp.',
            ],
            [
                'slug' => 'drama',
                'name' => 'Kịch Tính',
                'description' => 'Diễn biến tâm lý phức tạp, xung đột nội tâm và kịch tính xã hội.',
            ],
            [
                'slug' => 'mystery',
                'name' => 'Bí Ẩn',
                'description' => 'Những vụ án ly kỳ, manh mối bí ẩn cần được khám phá.',
            ],
            [
                'slug' => 'sci-fi',
                'name' => 'Khoa Học Viễn Tưởng',
                'description' => 'Khám phá thế giới tương lai, công nghệ cao và vũ trụ bao la.',
            ],
        ];

        foreach ($genres as $genreData) {
            Genre::updateOrCreate(
                ['slug' => $genreData['slug']],
                [
                    'name' => $genreData['name'],
                    'description' => $genreData['description'],
                    'is_active' => true,
                ]
            );
        }
    }
}
