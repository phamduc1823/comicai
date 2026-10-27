<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tags = [
            [
                'slug' => 'kiem-thuat',
                'name' => 'Kiếm thuật',
                'description' => 'Kỹ năng sử dụng kiếm pháp xuất chúng.',
            ],
            [
                'slug' => 'ma-phap',
                'name' => 'Ma pháp',
                'description' => 'Phép thuật, ma pháp trận và các nguyên tố huyền bí.',
            ],
            [
                'slug' => 'hoc-duong',
                'name' => 'Học đường',
                'description' => 'Bối cảnh trường học, thanh xuân học trò.',
            ],
            [
                'slug' => 'xuyen-khong',
                'name' => 'Xuyên không',
                'description' => 'Du hành vượt thời gian không gian tới thế giới khác.',
            ],
            [
                'slug' => 'he-thong',
                'name' => 'Hệ thống',
                'description' => 'Nhân vật sở hữu giao diện hệ thống hỗ trợ tăng cấp.',
            ],
            [
                'slug' => 'do-thi',
                'name' => 'Đô thị',
                'description' => 'Bối cảnh thành phố hiện đại với nhịp sống sôi động.',
            ],
            [
                'slug' => 'trong-sinh',
                'name' => 'Trọng sinh',
                'description' => 'Cơ hội làm lại cuộc đời từ một thời điểm trong quá khứ.',
            ],
            [
                'slug' => 'quai-vat',
                'name' => 'Quái vật',
                'description' => 'Đối đầu với dã thú hung hãn và sinh vật dị giới.',
            ],
            [
                'slug' => 'tinh-ban',
                'name' => 'Tình bạn',
                'description' => 'Tình đồng đội keo sơn, gắn bó qua gian khó.',
            ],
            [
                'slug' => 'bang-dang',
                'name' => 'Băng đảng',
                'description' => 'Thế giới ngầm, bang hội và tranh đoạt quyền lực.',
            ],
            [
                'slug' => 'sinh-ton',
                'name' => 'Sinh tồn',
                'description' => 'Thử thách khắc nghiệt nơi sự sống và cái chết cận kề.',
            ],
            [
                'slug' => 'co-trang',
                'name' => 'Cổ trang',
                'description' => 'Trang phục và bối cảnh thời kỳ phong kiến xưa.',
            ],
        ];

        foreach ($tags as $tagData) {
            Tag::updateOrCreate(
                ['slug' => $tagData['slug']],
                [
                    'name' => $tagData['name'],
                    'description' => $tagData['description'],
                ]
            );
        }
    }
}
