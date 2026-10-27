<?php

namespace Database\Seeders;

use App\Models\Comic;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class ComicSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::where('email', 'admin@bluetoon.local')->first()
            ?? User::where('role', 'admin')->first();
        $creator = User::where('email', 'creator@bluetoon.local')->first()
            ?? User::where('role', 'creator')->first();

        if (! $admin || ! $creator) {
            throw new RuntimeException('Admin or Creator user not found. Ensure UserSeeder runs before ComicSeeder.');
        }

        $comics = [
            [
                'slug' => 'huyen-thoai-kiem-vuong',
                'title' => 'Huyền Thoại Kiếm Vương',
                'alternative_titles' => 'The Legend of Sword King / Kiếm Vương Tái Sinh',
                'author_name' => 'Họa Sĩ Minh Trí',
                'description' => 'Một kiếm sĩ thiên tài bị phản bội, tái sinh sau 300 năm để đoạt lại đỉnh cao võ đạo và bảo vệ những người thân yêu.',
                'cover_path' => 'placeholders/comics/huyen-thoai-kiem-vuong-cover.webp',
                'banner_path' => 'placeholders/comics/huyen-thoai-kiem-vuong-banner.webp',
                'age_rating' => '16+',
                'status' => 'ongoing',
                'is_featured' => true,
                'views_count' => 12500,
                'follows_count' => 840,
                'genres' => ['action', 'fantasy'],
                'tags' => ['kiem-thuat', 'trong-sinh', 'he-thong', 'quai-vat'],
            ],
            [
                'slug' => 'anh-sang-ban-mai',
                'title' => 'Ánh Sáng Ban Mai',
                'alternative_titles' => 'Morning Light / Khúc Ca Rực Rỡ',
                'author_name' => 'Họa Sĩ Minh Trí',
                'description' => 'Cuộc gặp gỡ định mệnh giữa nữ sinh nhạc viện trầm lặng và chàng nghệ sĩ piano đường phố tài hoa.',
                'cover_path' => 'placeholders/comics/anh-sang-ban-mai-cover.webp',
                'banner_path' => 'placeholders/comics/anh-sang-ban-mai-banner.webp',
                'age_rating' => '13+',
                'status' => 'ongoing',
                'is_featured' => true,
                'views_count' => 8900,
                'follows_count' => 620,
                'genres' => ['romance', 'slice-of-life', 'drama'],
                'tags' => ['hoc-duong', 'tinh-ban', 'do-thi'],
            ],
            [
                'slug' => 'tiem-tra-mua-ha',
                'title' => 'Tiệm Trà Mùa Hạ',
                'alternative_titles' => 'Summer Tea House / Chuyện Góc Phố Cổ',
                'author_name' => 'Họa Sĩ Minh Trí',
                'description' => 'Những mẩu chuyện ấm lòng và hài hước xoay quanh một quán trà gia truyền nép mình giữa lòng thành phố sôi động.',
                'cover_path' => 'placeholders/comics/tiem-tra-mua-ha-cover.webp',
                'banner_path' => null,
                'age_rating' => '13+',
                'status' => 'ongoing',
                'is_featured' => false,
                'views_count' => 5400,
                'follows_count' => 310,
                'genres' => ['comedy', 'slice-of-life'],
                'tags' => ['do-thi', 'tinh-ban'],
            ],
        ];

        foreach ($comics as $comicData) {
            $comic = Comic::firstOrNew(['slug' => $comicData['slug']]);

            // Assign fillable attributes
            $comic->title = $comicData['title'];
            $comic->alternative_titles = $comicData['alternative_titles'];
            $comic->author_name = $comicData['author_name'];
            $comic->user_id = $creator->id;
            $comic->description = $comicData['description'];
            $comic->cover_path = $comicData['cover_path'];
            $comic->banner_path = $comicData['banner_path'];
            $comic->age_rating = $comicData['age_rating'];
            $comic->status = $comicData['status'];

            // Explicitly assign protected attributes (guarded against mass-assignment)
            $comic->approval_status = 'approved';
            $comic->approved_by = $admin->id;
            if (! $comic->approved_at) {
                $comic->approved_at = now();
            }
            $comic->is_featured = $comicData['is_featured'];
            $comic->views_count = $comicData['views_count'];
            $comic->follows_count = $comicData['follows_count'];

            $comic->save();

            // Sync genre and tag relations
            $genreIds = Genre::whereIn('slug', $comicData['genres'])->pluck('id');
            $comic->genres()->sync($genreIds);

            $tagIds = Tag::whereIn('slug', $comicData['tags'])->pluck('id');
            $comic->tags()->sync($tagIds);
        }
    }
}
