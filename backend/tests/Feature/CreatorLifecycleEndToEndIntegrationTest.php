<?php

namespace Tests\Feature;

use App\Models\Comic;
use App\Models\CreatorApplication;
use App\Models\CreatorAuditLog;
use App\Models\CreatorProfile;
use App\Models\PenNameHistory;
use App\Models\PenNameReservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatorLifecycleEndToEndIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $reader;
    protected User $otherReader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Admin Quản Trị',
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->reader = User::factory()->create([
            'name' => 'Nguyễn Độc Giả',
            'role' => 'reader',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->otherReader = User::factory()->create([
            'name' => 'Trần Độc Giả Khác',
            'role' => 'reader',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Flow A -> B -> C -> D -> E: Complete End-to-End Creator Lifecycle Journey.
     */
    public function test_complete_creator_lifecycle_journey(): void
    {
        // 1. Reader submits valid application
        $resSubmit = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Họa Sĩ Hành Động',
            'bio' => 'Tác giả có 3 năm kinh nghiệm vẽ webtoon',
            'reason' => 'Mong muốn đóng góp tác phẩm chất lượng cao cho cộng đồng',
            'sample_works' => ['https://drive.google.com/sample1', 'https://drive.google.com/sample2'],
        ]);
        $resSubmit->assertStatus(201);
        $appId = $resSubmit->json('data.id');
        $appCode = $resSubmit->json('data.application_code');
        $this->assertMatchesRegularExpression('/^CA-\d{6}$/', $appCode);

        // Verify pending state and pen name reservation
        $this->assertDatabaseHas('creator_applications', [
            'id' => $appId,
            'status' => 'pending',
            'version' => 1,
        ]);
        $this->assertDatabaseHas('pen_name_reservations', [
            'pen_name_canonical' => 'họa sĩ hành động',
            'reserved_by_user_id' => $this->reader->id,
            'reservation_type' => 'application',
        ]);

        // 2. Admin reviews and approves application
        $resApprove = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creator-applications/{$appId}/approve");
        $resApprove->assertStatus(200);
        $this->assertEquals('approved', $resApprove->json('data.status'));

        // Verify role elevated to creator
        $this->reader->refresh();
        $this->assertEquals('creator', $this->reader->role);

        // Verify creator profile created with CR-xxxxxx code
        $profile = CreatorProfile::where('user_id', $this->reader->id)->first();
        $this->assertNotNull($profile);
        $this->assertEquals('active', $profile->status);
        $creatorCode = $profile->creator_code;
        $this->assertMatchesRegularExpression('/^CR-\d{6}$/', $creatorCode);

        // Verify reservation upgraded to 'creator'
        $this->assertDatabaseHas('pen_name_reservations', [
            'pen_name_canonical' => 'họa sĩ hành động',
            'reservation_type' => 'creator',
        ]);

        // 3. Creator creates comic and updates pen name
        Comic::create([
            'title' => 'Webtoon Cuồng Phong',
            'slug' => 'webtoon-cuong-phong',
            'author_name' => 'Họa Sĩ Hành Động',
            'user_id' => $this->reader->id,
            'cover_path' => 'covers/comic1.webp',
            'status' => 'ongoing',
        ]);

        $resUpdate = $this->actingAs($this->reader, 'sanctum')->putJson('/api/creator/profile', [
            'pen_name' => 'Họa Sĩ Siêu Cấp',
            'bio' => 'Cập nhật tiểu sử chuyên nghiệp',
        ]);
        $resUpdate->assertStatus(200);

        // Verify old name kept as alias, new name active, comic author_name synced
        $this->assertDatabaseHas('pen_name_reservations', [
            'pen_name_canonical' => 'họa sĩ hành động',
            'reserved_by_user_id' => $this->reader->id,
            'reservation_type' => 'alias_creator',
        ]);
        $this->assertDatabaseHas('pen_name_reservations', [
            'pen_name_canonical' => 'họa sĩ siêu cấp',
            'reserved_by_user_id' => $this->reader->id,
            'reservation_type' => 'creator',
        ]);
        $this->assertDatabaseHas('comics', [
            'user_id' => $this->reader->id,
            'author_name' => 'Họa Sĩ Siêu Cấp',
        ]);
        $this->assertDatabaseHas('pen_name_histories', [
            'user_id' => $this->reader->id,
            'old_pen_name' => 'Họa Sĩ Hành Động',
            'new_pen_name' => 'Họa Sĩ Siêu Cấp',
        ]);

        // 4. Admin revokes creator privileges
        $resRevoke = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creators/{$profile->id}/revoke", [
            'reason' => 'Vi phạm quy định bản quyền truyện tranh',
        ]);
        $resRevoke->assertStatus(200);
        $this->reader->refresh();
        $this->assertEquals('reader', $this->reader->role);
        $this->assertEquals('revoked', $profile->fresh()->status);

        // Verify Creator Studio access is strictly blocked for revoked creator
        $this->actingAs($this->reader, 'sanctum')->getJson('/api/creator/profile')->assertStatus(403);
        $this->actingAs($this->reader, 'sanctum')->getJson('/api/creator/comics')->assertStatus(403);

        // Verify comic remains intact in database (never deleted on revoke)
        $this->assertDatabaseHas('comics', [
            'user_id' => $this->reader->id,
            'title' => 'Webtoon Cuồng Phong',
        ]);

        // 5. Revoked creator cannot reapply without admin grant
        $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Họa Sĩ Tái Sinh',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ])->assertStatus(403);

        // 6. Admin grants 1-time reapply permission
        $resGrant = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creators/{$profile->id}/grant-reapply", [
            'reason' => 'Đã cung cấp đầy đủ giấy phép bản quyền gốc',
        ]);
        $resGrant->assertStatus(200);
        $this->assertTrue((bool) $profile->fresh()->can_reapply);

        // 7. Creator submits re-application
        $resReapply = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Họa Sĩ Siêu Cấp',
            'bio' => 'Tiểu sử nộp lại',
            'reason' => 'Đã khắc phục tranh chấp bản quyền',
        ]);
        $resReapply->assertStatus(201);
        $reapplyAppId = $resReapply->json('data.id');

        // Grant permission must be consumed atomically
        $this->assertFalse((bool) $profile->fresh()->can_reapply);

        // 8. Admin approves re-application
        $resReApprove = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creator-applications/{$reapplyAppId}/approve");
        $resReApprove->assertStatus(200);

        // Verify role restored to creator AND original CR code is preserved
        $this->reader->refresh();
        $this->assertEquals('creator', $this->reader->role);
        $this->assertEquals($creatorCode, $profile->fresh()->creator_code, 'Re-approved creator must retain original creator code');
        $this->assertEquals('active', $profile->fresh()->status);
    }

    /**
     * Flow B Edge Case: Cannot approve or reject already processed applications.
     */
    public function test_cannot_approve_or_reject_non_pending_application(): void
    {
        $res = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Họa Sĩ Test Edge',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);
        $appId = $res->json('data.id');

        // 1. Approve once -> 200
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creator-applications/{$appId}/approve")->assertStatus(200);

        // 2. Approve again -> 422
        $resDuplicateApprove = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creator-applications/{$appId}/approve");
        $resDuplicateApprove->assertStatus(422);
        $resDuplicateApprove->assertJsonFragment(['error_code' => 'CANNOT_APPROVE_NON_PENDING']);

        // 3. Reject after approved -> 422
        $resReject = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creator-applications/{$appId}/reject", [
            'reason' => 'Lý do từ chối',
        ]);
        $resReject->assertStatus(422);
        $resReject->assertJsonFragment(['error_code' => 'CANNOT_REJECT_NON_PENDING']);
    }

    /**
     * Flow C Edge Case: Creator can switch back to their own alias, but another user cannot claim it.
     */
    public function test_creator_can_switch_back_to_own_alias_and_other_user_cannot_claim_alias(): void
    {
        // Approve reader as creator
        $res = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Bút Danh Gốc',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/creator-applications/'.$res->json('data.id').'/approve');
        $this->reader->refresh();

        // Creator changes to 'Bút Danh Thứ Hai'
        $this->actingAs($this->reader, 'sanctum')->putJson('/api/creator/profile', [
            'pen_name' => 'Bút Danh Thứ Hai',
        ])->assertStatus(200);

        // Other reader attempts to claim the old alias 'Bút Danh Gốc' -> BLOCKED 422
        $resOther = $this->actingAs($this->otherReader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Bút Danh Gốc',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);
        $resOther->assertStatus(422);
        $resOther->assertJsonFragment(['error_code' => 'PEN_NAME_TAKEN']);

        // Creator switches BACK to their own alias 'Bút Danh Gốc' -> SUCCESS 200
        $resSwitchBack = $this->actingAs($this->reader, 'sanctum')->putJson('/api/creator/profile', [
            'pen_name' => 'Bút Danh Gốc',
        ]);
        $resSwitchBack->assertStatus(200);
        $this->assertEquals('Bút Danh Gốc', $resSwitchBack->json('data.pen_name'));
    }

    /**
     * Flow E Edge Case: Admin cannot revoke reapply permission if user has already submitted pending application.
     */
    public function test_admin_cannot_revoke_reapply_if_user_already_submitted_pending_reapplication(): void
    {
        // Setup revoked creator with grant
        $res = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Kiểm Thử Grant',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/creator-applications/'.$res->json('data.id').'/approve');
        $profile = CreatorProfile::where('user_id', $this->reader->id)->first();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creators/{$profile->id}/revoke", ['reason' => 'Lý do thu hồi']);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creators/{$profile->id}/grant-reapply", ['reason' => 'Cấp quyền']);

        // User submits pending re-application
        $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Kiểm Thử Grant',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ])->assertStatus(201);

        // Admin attempts to revoke reapply while application is pending -> BLOCKED 422
        $resRevokeReapply = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creators/{$profile->id}/revoke-reapply", [
            'reason' => 'Muốn hủy sau khi nộp',
        ]);
        $resRevokeReapply->assertStatus(422);
        $resRevokeReapply->assertJsonFragment(['error_code' => 'APPLICATION_ALREADY_SUBMITTED']);
    }

    /**
     * Section 4: Comprehensive Security & Authorization Matrix across all roles.
     */
    public function test_authorization_matrix_across_all_roles(): void
    {
        // 1. Guest (unauthenticated)
        $this->getJson('/api/creator-applications/my')->assertStatus(401);
        $this->postJson('/api/creator-applications', [])->assertStatus(401);
        $this->getJson('/api/creator/profile')->assertStatus(401);
        $this->getJson('/api/admin/creator-applications')->assertStatus(401);
        $this->getJson('/api/admin/creators')->assertStatus(401);

        // 2. Reader cannot access creator studio or admin endpoints
        $this->actingAs($this->reader, 'sanctum')->getJson('/api/creator/profile')->assertStatus(403);
        $this->actingAs($this->reader, 'sanctum')->getJson('/api/creator/comics')->assertStatus(403);
        $this->actingAs($this->reader, 'sanctum')->getJson('/api/admin/creator-applications')->assertStatus(403);
        $this->actingAs($this->reader, 'sanctum')->getJson('/api/admin/creators')->assertStatus(403);

        // 3. Reader cannot cancel another reader's application
        $resOtherApp = $this->actingAs($this->otherReader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Độc Giả Khác Pen',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);
        $otherAppId = $resOtherApp->json('data.id');

        $this->actingAs($this->reader, 'sanctum')->postJson("/api/creator-applications/{$otherAppId}/cancel")->assertStatus(404);
        $this->actingAs($this->reader, 'sanctum')->putJson("/api/creator-applications/{$otherAppId}/resubmit", [
            'pen_name' => 'Hack Pen',
            'bio' => 'Hack Bio',
            'reason' => 'Hack Reason',
        ])->assertStatus(404);
    }

    /**
     * Flow F: Verification that historical snapshots are strictly immutable and never mutated across versions.
     */
    public function test_flow_f_historical_snapshots_are_strictly_immutable_and_versioned(): void
    {
        // 1. Submit v1
        $resSubmit = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Bút Danh Gốc V1',
            'bio' => 'Tiểu sử tác giả phiên bản 1',
            'reason' => 'Lý do đăng ký phiên bản 1',
            'sample_works' => ['https://portfolio.com/work1'],
        ]);
        $appId = $resSubmit->json('data.id');

        // 2. Reject v1
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creator-applications/{$appId}/reject", [
            'reason' => 'Yêu cầu bổ sung thêm tác phẩm mẫu và mở rộng bio',
        ]);

        // 3. Resubmit as v2 with altered fields
        $resResubmit = $this->actingAs($this->reader, 'sanctum')->putJson("/api/creator-applications/{$appId}/resubmit", [
            'pen_name' => 'Bút Danh Cải Tiến V2',
            'bio' => 'Tiểu sử tác giả phiên bản 2 đã mở rộng chi tiết',
            'reason' => 'Lý do đăng ký phiên bản 2',
            'sample_works' => ['https://portfolio.com/work1', 'https://portfolio.com/work2'],
        ]);
        $resResubmit->assertStatus(200);

        // 4. Query database histories
        $histories = \App\Models\CreatorApplicationHistory::where('application_id', $appId)
            ->orderBy('id', 'asc')
            ->get();

        $this->assertCount(3, $histories); // submitted v1, rejected v1, resubmitted v2

        // History 1: submitted v1
        $h1 = $histories[0];
        $this->assertEquals(1, $h1->version);
        $this->assertEquals('submitted', $h1->action_type);
        $this->assertEquals('Bút Danh Gốc V1', $h1->snapshot_data['pen_name']);
        $this->assertEquals('Tiểu sử tác giả phiên bản 1', $h1->snapshot_data['bio']);
        $this->assertEquals(['https://portfolio.com/work1'], $h1->snapshot_data['sample_works']);
        $this->assertEquals('pending', $h1->snapshot_data['status']);

        // History 2: rejected v1
        $h2 = $histories[1];
        $this->assertEquals(1, $h2->version);
        $this->assertEquals('rejected', $h2->action_type);
        $this->assertEquals('rejected', $h2->snapshot_data['status']);
        $this->assertEquals('Yêu cầu bổ sung thêm tác phẩm mẫu và mở rộng bio', $h2->snapshot_data['rejection_reason']);
        // Crucial: pen name in v1 rejection snapshot remains v1
        $this->assertEquals('Bút Danh Gốc V1', $h2->snapshot_data['pen_name']);

        // History 3: resubmitted v2
        $h3 = $histories[2];
        $this->assertEquals(2, $h3->version);
        $this->assertEquals('resubmitted', $h3->action_type);
        $this->assertEquals('Bút Danh Cải Tiến V2', $h3->snapshot_data['pen_name']);
        $this->assertEquals('Tiểu sử tác giả phiên bản 2 đã mở rộng chi tiết', $h3->snapshot_data['bio']);
        $this->assertEquals(['https://portfolio.com/work1', 'https://portfolio.com/work2'], $h3->snapshot_data['sample_works']);
        $this->assertEquals('pending', $h3->snapshot_data['status']);

        // Immutability confirmation: H1 and H2 snapshot data are completely isolated from H3
        $this->assertNotEquals($h1->snapshot_data['pen_name'], $h3->snapshot_data['pen_name']);
        $this->assertNotEquals($h1->snapshot_data['bio'], $h3->snapshot_data['bio']);
        $this->assertNotEquals($h1->snapshot_data['sample_works'], $h3->snapshot_data['sample_works']);
    }

    /**
     * Flow G: Verification that Excel export streams all matching records across the entire dataset,
     * completely ignoring pagination limits (e.g. 25 matching records exported when per_page is 10).
     */
    public function test_flow_g_export_includes_all_filtered_records_beyond_pagination_limit(): void
    {
        // 1. Seed 25 pending applications and 5 approved applications
        for ($i = 1; $i <= 25; $i++) {
            $user = User::factory()->create([
                'role' => 'reader',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            CreatorApplication::create([
                'application_code' => sprintf('CA-%06d', 100 + $i),
                'user_id' => $user->id,
                'pen_name' => "Tác Giả Pending {$i}",
                'pen_name_canonical' => "tác giả pending {$i}",
                'bio' => "Bio {$i}",
                'reason' => "Lý do {$i}",
                'status' => 'pending',
                'version' => 1,
            ]);
        }

        for ($i = 1; $i <= 5; $i++) {
            $user = User::factory()->create([
                'role' => 'reader',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            CreatorApplication::create([
                'application_code' => sprintf('CA-%06d', 200 + $i),
                'user_id' => $user->id,
                'pen_name' => "Tác Giả Approved {$i}",
                'pen_name_canonical' => "tác giả approved {$i}",
                'bio' => "Bio {$i}",
                'reason' => "Lý do {$i}",
                'status' => 'approved',
                'version' => 1,
            ]);
        }

        // 2. Query paginated index with status=pending and per_page=10
        $resIndex = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/creator-applications?status=pending&per_page=10');
        $resIndex->assertStatus(200);
        $this->assertEquals(25, $resIndex->json('meta.total'));
        $this->assertEquals(10, $resIndex->json('meta.per_page'));
        $this->assertCount(10, $resIndex->json('data'), 'Index page 1 must only return 10 items');

        // 3. Query export with status=pending
        $resExport = $this->actingAs($this->admin, 'sanctum')->get('/api/admin/creator-applications/export?status=pending');
        $resExport->assertStatus(200);
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', (string) $resExport->headers->get('content-type'));

        // Capture binary content from streamed response
        ob_start();
        $resExport->sendContent();
        $binaryContent = ob_get_clean();

        // Save to temporary file and inspect via ZipArchive
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        file_put_contents($tempFile, $binaryContent);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tempFile) === true, 'Streamed content must be a valid OpenXML ZipArchive');

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertNotEmpty($sheetXml);

        // Count <row elements in sheet1.xml: 1 header row + 25 pending application rows = 26 rows
        $rowCount = preg_match_all('/<row\b/', $sheetXml);
        $this->assertEquals(26, $rowCount, 'Exported Excel file must contain exactly 26 rows (1 header + 25 pending applications), proving it exports ALL matching records, not just the 10 from page 1.');

        $zip->close();
        @unlink($tempFile);
    }
}
