<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Banner;
use App\Models\Comic;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

echo "=====================================================\n";
echo "  TESTING BACKEND ADMIN BANNER MANAGEMENT (CAI-QA)\n";
echo "=====================================================\n";

// Configure testing environment in-memory
Config::set('database.default', 'sqlite');
Config::set('database.connections.sqlite.database', ':memory:');
Config::set('cache.default', 'array');

DB::purge();
Artisan::call('migrate');
Storage::fake('public');

$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $msg, &$passed, &$failed) {
    if ($cond) {
        echo "✅ PASS: $msg\n";
        $passed++;
    } else {
        echo "❌ FAIL: $msg\n";
        $failed++;
    }
}

// Setup users
$admin = new User([
    'name' => 'Admin User',
    'email' => 'admin@test.com',
    'password' => bcrypt('password'),
]);
$admin->role = 'admin';
$admin->is_active = true;
$admin->save();

$reader = new User([
    'name' => 'Reader User',
    'email' => 'reader@test.com',
    'password' => bcrypt('password'),
]);
$reader->role = 'reader';
$reader->is_active = true;
$reader->save();

$creator = new User([
    'name' => 'Creator User',
    'email' => 'creator@test.com',
    'password' => bcrypt('password'),
]);
$creator->role = 'creator';
$creator->is_active = true;
$creator->save();

$approvedComic = new Comic([
    'title' => 'Truyện Đã Duyệt',
    'slug' => 'truyen-da-duyet',
    'author_name' => 'Tác Giả A',
    'user_id' => $creator->id,
    'cover_path' => 'covers/approved.webp',
    'status' => 'ongoing',
]);
$approvedComic->approval_status = 'approved';
$approvedComic->save();

$draftComic = new Comic([
    'title' => 'Truyện Bản Nháp',
    'slug' => 'truyen-ban-nhap',
    'author_name' => 'Tác Giả B',
    'user_id' => $creator->id,
    'cover_path' => 'covers/draft.webp',
    'status' => 'ongoing',
]);
$draftComic->approval_status = 'draft';
$draftComic->save();

// Helper to dispatch request through router
function dispatchRequest($method, $uri, $user = null, $data = [], $files = []) {
    $server = [
        'HTTP_ACCEPT' => 'application/json',
    ];
    $request = \Illuminate\Http\Request::create($uri, $method, $data, [], $files, $server);
    if ($user) {
        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
        $request->setUserResolver(fn () => $user);
        auth()->guard('sanctum')->setUser($user);
    } else {
        auth()->forgetGuards();
        $request->setUserResolver(fn () => null);
    }
    return app()->handle($request);
}

// 1. Guest access denied
$res = dispatchRequest('GET', '/api/admin/banners');
assertCondition($res->getStatusCode() === 401, '1. Guest is rejected with 401 Unauthorized', $passed, $failed);

// 2. Non-admin access denied
$res = dispatchRequest('GET', '/api/admin/banners', $reader);
assertCondition($res->getStatusCode() === 403, '2.1. Reader is rejected with 403 Forbidden', $passed, $failed);
$res = dispatchRequest('GET', '/api/admin/banners', $creator);
assertCondition($res->getStatusCode() === 403, '2.2. Creator is rejected with 403 Forbidden', $passed, $failed);

// 3. Admin can list all banners (active and inactive)
Banner::create([
    'comic_id' => $approvedComic->id,
    'title_override' => 'Banner Active Test',
    'desktop_banner_path' => 'banners/desktop/sample1.webp',
    'is_active' => true,
    'sort_order' => 1,
]);
Banner::create([
    'comic_id' => $approvedComic->id,
    'title_override' => 'Banner Inactive Test',
    'desktop_banner_path' => 'banners/desktop/sample2.webp',
    'is_active' => false,
    'sort_order' => 2,
]);
$res = dispatchRequest('GET', '/api/admin/banners', $admin);
$content = json_decode($res->getContent(), true);
if ($res->getStatusCode() !== 200) {
    echo "Test 3 status: " . $res->getStatusCode() . ", content: " . $res->getContent() . "\n";
}
assertCondition($res->getStatusCode() === 200 && count($content['data']) === 2, '3. Admin lists all banners (including inactive)', $passed, $failed);

// 4. Create banner with images
$desktopImg = UploadedFile::fake()->image('desktop.webp', 1920, 600);
$mobileImg = UploadedFile::fake()->image('mobile.webp', 640, 800);
$postData = [
    'comic_id' => $approvedComic->id,
    'title_override' => 'Siêu Phẩm 2026',
    'sort_order' => 5,
    'is_active' => '1',
];
$postFiles = [
    'desktop_banner' => $desktopImg,
    'mobile_banner' => $mobileImg,
];
$res = dispatchRequest('POST', '/api/admin/banners', $admin, $postData, $postFiles);
$content = json_decode($res->getContent(), true);
if ($res->getStatusCode() !== 201) {
    echo "Test 4 status: " . $res->getStatusCode() . ", content: " . $res->getContent() . "\n";
}
$createdBanner = Banner::where('title_override', 'Siêu Phẩm 2026')->first();
assertCondition(
    $res->getStatusCode() === 201 &&
    $createdBanner &&
    Storage::disk('public')->exists($createdBanner->desktop_banner_path) &&
    Storage::disk('public')->exists($createdBanner->mobile_banner_path),
    '4. Admin creates valid banner with desktop & mobile images',
    $passed,
    $failed
);

// 5. Cannot create banner for unapproved comic
$res = dispatchRequest('POST', '/api/admin/banners', $admin, [
    'comic_id' => $draftComic->id,
    'desktop_banner' => UploadedFile::fake()->image('desktop.webp'),
], ['desktop_banner' => UploadedFile::fake()->image('desktop.webp')]);
assertCondition($res->getStatusCode() === 422, '5. Banner creation rejects unapproved comic (status=draft)', $passed, $failed);

// 6. Cannot create with invalid file type
$res = dispatchRequest('POST', '/api/admin/banners', $admin, [
    'comic_id' => $approvedComic->id,
], ['desktop_banner' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')]);
assertCondition($res->getStatusCode() === 422, '6. Banner creation rejects non-image file formats', $passed, $failed);

// 7. Update banner and replace image
$oldDesktop = $createdBanner->desktop_banner_path;
$newDesktopImg = UploadedFile::fake()->image('new_desktop.webp');
$res = dispatchRequest('POST', "/api/admin/banners/{$createdBanner->id}", $admin, [
    '_method' => 'PUT',
    'title_override' => 'Tiêu Đề Đã Sửa',
    'sort_order' => 99,
], ['desktop_banner' => $newDesktopImg]);
$createdBanner->refresh();
assertCondition(
    $res->getStatusCode() === 200 &&
    $createdBanner->title_override === 'Tiêu Đề Đã Sửa' &&
    $createdBanner->sort_order === 99 &&
    Storage::disk('public')->exists($createdBanner->desktop_banner_path) &&
    !Storage::disk('public')->exists($oldDesktop),
    '7. Admin updates metadata and replaces image; old file deleted',
    $passed,
    $failed
);

// 8. Toggle active status
$res = dispatchRequest('PATCH', "/api/admin/banners/{$createdBanner->id}/status", $admin);
$createdBanner->refresh();
assertCondition($res->getStatusCode() === 200 && $createdBanner->is_active === false, '8. Admin toggles active status to false', $passed, $failed);

// 9. Reorder banners
$allBanners = Banner::all();
$reorderData = [
    'orders' => [
        ['id' => $allBanners[0]->id, 'sort_order' => 10],
        ['id' => $allBanners[1]->id, 'sort_order' => 20],
    ]
];
$res = dispatchRequest('PATCH', '/api/admin/banners/reorder', $admin, $reorderData);
assertCondition($res->getStatusCode() === 200 && Banner::find($allBanners[0]->id)->sort_order === 10, '9. Admin reorders banner sort_order', $passed, $failed);

// 10. Delete banner cleans up files
$fileToDelete = $createdBanner->desktop_banner_path;
$targetId = $createdBanner->id;
$res = dispatchRequest('DELETE', "/api/admin/banners/{$targetId}", $admin);
assertCondition(
    $res->getStatusCode() === 200 &&
    !Banner::find($targetId) &&
    !Storage::disk('public')->exists($fileToDelete),
    '10. Admin deletes banner and file is deleted from disk',
    $passed,
    $failed
);

// 11. Public GET /api/banners only returns active banners of approved comics
$publicRes = dispatchRequest('GET', '/api/banners');
$publicData = json_decode($publicRes->getContent(), true)['data'];
$allActive = true;
foreach ($publicData as $b) {
    if (!Banner::find($b['id'])->is_active) {
        $allActive = false;
    }
}
assertCondition($publicRes->getStatusCode() === 200 && $allActive, '11. Public GET /api/banners strictly isolates active banners', $passed, $failed);

echo "\n=====================================================\n";
echo "  RESULTS: $passed passed, $failed failed\n";
echo "=====================================================\n";

if ($failed > 0) exit(1);
