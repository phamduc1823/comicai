<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Comic;
use App\Models\Post;
use App\Models\Comment;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

echo "=====================================================\n";
echo "  TESTING BACKEND VIOLATION REPORTING (CAI-QB1)\n";
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

// 1. Create Users
$admin = new User([
    'name' => 'Admin User',
    'email' => 'admin@test.com',
    'password' => bcrypt('password'),
]);
$admin->role = 'admin';
$admin->is_active = true;
$admin->save();

$creator = new User([
    'name' => 'Creator User',
    'email' => 'creator@test.com',
    'password' => bcrypt('password'),
]);
$creator->role = 'creator';
$creator->is_active = true;
$creator->save();

$reader = new User([
    'name' => 'Reader User',
    'email' => 'reader@test.com',
    'password' => bcrypt('password'),
]);
$reader->role = 'reader';
$reader->is_active = true;
$reader->save();

// 2. Create Target Content
$comic = new Comic([
    'title' => 'Test Comic',
    'slug' => 'test-comic',
    'author_name' => 'Author A',
    'cover_path' => 'comics/covers/test.jpg',
    'creator_id' => $creator->id,
    'status' => 'ongoing',
    'views_count' => 0,
    'follows_count' => 0,
]);
$comic->approval_status = 'approved';
$comic->save();

$post = Post::create([
    'user_id' => $creator->id,
    'content' => 'Test Community Post',
    'likes_count' => 0,
    'comments_count' => 0,
]);

$comment = Comment::create([
    'user_id' => $creator->id,
    'commentable_type' => 'post',
    'commentable_id' => $post->id,
    'content' => 'Test Comment',
    'likes_count' => 0,
]);

// Helper to dispatch request through router with Sanctum auth
function dispatchRequest($method, $uri, $user = null, $data = [], $content = null) {
    $server = [
        'HTTP_ACCEPT' => 'application/json',
    ];
    if ($content !== null) {
        $server['CONTENT_TYPE'] = 'application/json';
    }

    $request = \Illuminate\Http\Request::create($uri, $method, $data, [], [], $server, $content);
    if ($user) {
        Sanctum::actingAs($user, ['*']);
        $request->setUserResolver(fn () => $user);
        auth()->guard('sanctum')->setUser($user);
    } else {
        auth()->forgetGuards();
        $request->setUserResolver(fn () => null);
    }
    return app()->handle($request);
}

// Test 1: Guest rejected with 401
$resp = dispatchRequest('POST', '/api/reports', null, [
    'reportable_type' => 'comic',
    'reportable_id' => $comic->id,
    'reason' => 'Spam',
]);
assertCondition($resp->getStatusCode() === 401, '1. Guest rejected with 401 Unauthorized', $passed, $failed);

// Test 2: Reader submits valid report for comic
$resp = dispatchRequest('POST', '/api/reports', $reader, [
    'reportable_type' => 'comic',
    'reportable_id' => $comic->id,
    'reason' => 'Vi phạm bản quyền',
    'description' => 'Mô tả chi tiết vi phạm bản quyền',
]);
$data = json_decode($resp->getContent(), true);
assertCondition(
    $resp->getStatusCode() === 201 && ($data['status'] ?? '') === 'success' && ($data['data']['status'] ?? '') === 'pending',
    '2. Reader submits valid report for comic (201 Created)',
    $passed,
    $failed
);

// Test 3: Reader cannot submit duplicate pending report
$resp = dispatchRequest('POST', '/api/reports', $reader, [
    'reportable_type' => 'comic',
    'reportable_id' => $comic->id,
    'reason' => 'Vi phạm bản quyền',
]);
assertCondition($resp->getStatusCode() === 422, '3. Duplicate pending report rejected with 422', $passed, $failed);

// Test 4: Reader cannot self-report
$resp = dispatchRequest('POST', '/api/reports', $reader, [
    'reportable_type' => 'user',
    'reportable_id' => $reader->id,
    'reason' => 'Spam',
]);
assertCondition($resp->getStatusCode() === 422, '4. Self-reporting rejected with 422', $passed, $failed);

// Test 5: Reporting non-existent content returns 404
$resp = dispatchRequest('POST', '/api/reports', $reader, [
    'reportable_type' => 'comic',
    'reportable_id' => 999999,
    'reason' => 'Spam',
]);
assertCondition($resp->getStatusCode() === 404, '5. Reporting non-existent target returns 404 Not Found', $passed, $failed);

// Test 6: Non-admin forbidden from admin reports
$resp = dispatchRequest('GET', '/api/admin/reports', $reader);
assertCondition($resp->getStatusCode() === 403, '6. Non-admin forbidden from GET /api/admin/reports (403)', $passed, $failed);

// Test 7: Admin lists reports
$resp = dispatchRequest('GET', '/api/admin/reports', $admin);
$listData = json_decode($resp->getContent(), true);
assertCondition(
    $resp->getStatusCode() === 200 && is_array($listData['data']) && count($listData['data']) >= 1,
    '7. Admin lists all reports (200 OK)',
    $passed,
    $failed
);

$reportId = $listData['data'][0]['id'];

// Test 8: Admin views single report
$resp = dispatchRequest('GET', "/api/admin/reports/{$reportId}", $admin);
$detailData = json_decode($resp->getContent(), true);
assertCondition(
    $resp->getStatusCode() === 200 && ($detailData['data']['id'] ?? 0) === $reportId,
    '8. Admin views single report details (200 OK)',
    $passed,
    $failed
);

// Test 9: Admin updates report to resolved with admin_notes
$resp = dispatchRequest('PATCH', "/api/admin/reports/{$reportId}", $admin, [], json_encode([
    'status' => 'resolved',
    'admin_notes' => 'Đã xác minh và xử lý vi phạm bản quyền.',
]));
$resolvedData = json_decode($resp->getContent(), true);
assertCondition(
    $resp->getStatusCode() === 200 && ($resolvedData['data']['status'] ?? '') === 'resolved' && !empty($resolvedData['data']['admin_notes']),
    '9. Admin updates report to resolved with admin_notes (200 OK)',
    $passed,
    $failed
);

// Test 10: Admin cannot re-resolve already resolved report
$resp = dispatchRequest('PATCH', "/api/admin/reports/{$reportId}", $admin, [], json_encode([
    'status' => 'dismissed',
    'admin_notes' => 'Thử chuyển trạng thái lại',
]));
assertCondition($resp->getStatusCode() === 422, '10. Cannot modify already resolved report (422 Unprocessable)', $passed, $failed);

// Test 11: Reader reports comment and Admin dismisses it
$resp = dispatchRequest('POST', '/api/reports', $reader, [
    'reportable_type' => 'comment',
    'reportable_id' => $comment->id,
    'reason' => 'Spam',
]);
$cReportData = json_decode($resp->getContent(), true);
$cReportId = $cReportData['data']['id'];

$resp = dispatchRequest('PATCH', "/api/admin/reports/{$cReportId}", $admin, [], json_encode([
    'status' => 'dismissed',
    'admin_notes' => 'Không vi phạm, bình luận hợp lệ.',
]));
$dismissedData = json_decode($resp->getContent(), true);
assertCondition(
    $resp->getStatusCode() === 200 && ($dismissedData['data']['status'] ?? '') === 'dismissed',
    '11. Admin dismisses valid report (200 OK)',
    $passed,
    $failed
);

echo "\n=====================================================\n";
echo "  RESULTS: $passed passed, $failed failed\n";
echo "=====================================================\n";

exit($failed > 0 ? 1 : 0);
