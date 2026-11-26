<?php

namespace Tests\Feature;

use App\Models\Comic;
use App\Models\CreatorApplication;
use App\Models\CreatorAuditLog;
use App\Models\CreatorProfile;
use App\Models\PenNameReservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatorManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $reader;
    protected User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->reader = User::factory()->create([
            'role' => 'reader',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->creator = User::factory()->create([
            'role' => 'creator',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * 1. Unauthenticated users cannot access creator application endpoints.
     */
    public function test_unauthenticated_user_cannot_access_creator_application_apis(): void
    {
        $this->getJson('/api/creator-applications/my')->assertStatus(401);
        $this->postJson('/api/creator-applications', [])->assertStatus(401);
        $this->postJson('/api/creator-applications/1/cancel')->assertStatus(401);
        $this->putJson('/api/creator-applications/1/resubmit', [])->assertStatus(401);
        $this->getJson('/api/creator/profile')->assertStatus(401);
        $this->getJson('/api/admin/creator-applications')->assertStatus(401);
        $this->getJson('/api/admin/creators')->assertStatus(401);
    }

    /**
     * 2. Inactive user is blocked by active middleware.
     */
    public function test_inactive_user_is_blocked_by_active_middleware(): void
    {
        $inactiveUser = User::factory()->create([
            'role' => 'reader',
            'is_active' => false,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($inactiveUser, 'sanctum')->getJson('/api/creator-applications/my');
        $response->assertStatus(403);
    }

    /**
     * 3. Unverified email user cannot submit creator application.
     */
    public function test_unverified_email_cannot_submit_application(): void
    {
        $unverifiedReader = User::factory()->create([
            'role' => 'reader',
            'is_active' => true,
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($unverifiedReader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Chưa Verify',
            'bio' => 'Giới thiệu bản thân ngắn gọn',
            'reason' => 'Đam mê sáng tác truyện tranh',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['error_code' => 'EMAIL_NOT_VERIFIED']);
    }

    /**
     * 4. Already active creator cannot submit another application.
     */
    public function test_existing_creator_cannot_submit_application(): void
    {
        $response = $this->actingAs($this->creator, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Đã Có Role',
            'bio' => 'Giới thiệu bản thân',
            'reason' => 'Lý do đăng ký',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['error_code' => 'ALREADY_CREATOR']);
    }

    /**
     * 5. Pen name validation rules (length, disallowed symbols, valid Unicode with Vietnamese diacritics).
     */
    public function test_pen_name_validation_rules(): void
    {
        // Too short (< 3 chars)
        $resShort = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'AB',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);
        $resShort->assertStatus(422);

        // Disallowed special chars
        $resInvalidChars = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả @#$',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);
        $resInvalidChars->assertStatus(422);

        // Valid Vietnamese Unicode NFC
        $resValid = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Nguyễn Văn A - Tác Giả 01',
            'bio' => 'Tác giả truyện hành động và viễn tưởng',
            'reason' => 'Mong muốn xuất bản tác phẩm của mình lên ComicAI',
        ]);
        $resValid->assertStatus(201);
        $this->assertEquals('Nguyễn Văn A - Tác Giả 01', $resValid->json('data.pen_name'));
        $this->assertMatchesRegularExpression('/^CA-\d{6}$/', $resValid->json('data.application_code'));
    }

    /**
     * 6. Form request validates required bio, reason, and sample works URLs.
     */
    public function test_required_fields_validation(): void
    {
        $response = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Bút Danh Hợp Lệ',
            'bio' => '',
            'reason' => '',
            'sample_works' => ['not-a-valid-url'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['bio', 'reason', 'sample_works.0']);
    }

    /**
     * 7. Reader can submit application and query via GET /creator-applications/my.
     */
    public function test_reader_can_submit_application_and_view_my_application(): void
    {
        $resSubmit = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Họa Sĩ Truyện Tranh',
            'bio' => 'Kinh nghiệm vẽ 5 năm',
            'reason' => 'Muốn trở thành tác giả chính thức',
            'sample_works' => ['https://portfolio.example.com/art1', 'https://portfolio.example.com/art2'],
        ]);

        $resSubmit->assertStatus(201);
        $appId = $resSubmit->json('data.id');

        $resMy = $this->actingAs($this->reader, 'sanctum')->getJson('/api/creator-applications/my');
        $resMy->assertStatus(200);
        $this->assertEquals($appId, $resMy->json('application.id'));
        $this->assertEquals('pending', $resMy->json('application.status'));
        $this->assertCount(1, $resMy->json('application.histories'));
        $this->assertNull($resMy->json('profile'));
    }

    /**
     * 8. Reader cannot submit a second application while one is still pending.
     */
    public function test_reader_cannot_submit_second_application_while_one_is_pending(): void
    {
        // First application
        $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Bút Danh 1',
            'bio' => 'Bio 1',
            'reason' => 'Reason 1',
        ])->assertStatus(201);

        // Second application attempt
        $resSecond = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Bút Danh Khác',
            'bio' => 'Bio 2',
            'reason' => 'Reason 2',
        ]);

        $resSecond->assertStatus(422);
        $resSecond->assertJsonFragment(['error_code' => 'PENDING_APPLICATION_EXISTS']);
    }

    /**
     * 9. Reader can cancel their pending application, releasing the pen name reservation.
     */
    public function test_reader_can_cancel_pending_application(): void
    {
        $resSubmit = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tên Muốn Hủy',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);
        $appId = $resSubmit->json('data.id');

        // Cancel
        $resCancel = $this->actingAs($this->reader, 'sanctum')->postJson("/api/creator-applications/{$appId}/cancel");
        $resCancel->assertStatus(200);
        $this->assertEquals('canceled', $resCancel->json('data.status'));

        // Pen name reservation should be deleted
        $this->assertDatabaseMissing('pen_name_reservations', [
            'application_id' => $appId,
        ]);

        // Another reader can now register this pen name
        $reader2 = User::factory()->create([
            'role' => 'reader',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $resOther = $this->actingAs($reader2, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tên Muốn Hủy',
            'bio' => 'Bio reader 2',
            'reason' => 'Reason reader 2',
        ]);
        $resOther->assertStatus(201);
    }

    /**
     * 10. Non-admin cannot access admin creator endpoints.
     */
    public function test_non_admin_cannot_access_admin_creator_endpoints(): void
    {
        $this->actingAs($this->reader, 'sanctum')->getJson('/api/admin/creator-applications')->assertStatus(403);
        $this->actingAs($this->reader, 'sanctum')->getJson('/api/admin/creators')->assertStatus(403);
        $this->actingAs($this->creator, 'sanctum')->getJson('/api/admin/creator-applications')->assertStatus(403);
        $this->actingAs($this->creator, 'sanctum')->getJson('/api/admin/creators')->assertStatus(403);
    }

    /**
     * 11. Admin can list applications with filters, statistics counters, and search.
     */
    public function test_admin_can_list_applications_with_filters_stats_and_search(): void
    {
        // Create 2 applications
        $reader2 = User::factory()->create(['role' => 'reader', 'is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Tìm Kiếm X',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);

        $this->actingAs($reader2, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Tìm Kiếm Y',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);

        $resAdmin = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/creator-applications?status=pending');
        $resAdmin->assertStatus(200);
        $this->assertEquals(2, $resAdmin->json('stats.pending'));
        $this->assertEquals(2, $resAdmin->json('stats.all'));
        $this->assertCount(2, $resAdmin->json('data'));

        // Search by pen name
        $resSearch = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/creator-applications?q=Kiếm X');
        $resSearch->assertStatus(200);
        $this->assertCount(1, $resSearch->json('data'));
        $this->assertEquals('Tác Giả Tìm Kiếm X', $resSearch->json('data.0.pen_name'));
    }

    /**
     * 12. Admin can reject application with mandatory reason, recording history and audit log.
     */
    public function test_admin_can_reject_application_with_reason(): void
    {
        $resSubmit = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Sắp Bị Từ Chối',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);
        $appId = $resSubmit->json('data.id');

        // Missing rejection reason
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creator-applications/{$appId}/reject", [
            'reason' => '',
        ])->assertStatus(422);

        // Reject with valid reason
        $resReject = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creator-applications/{$appId}/reject", [
            'reason' => 'Hồ sơ chưa đạt tiêu chuẩn nội dung',
        ]);

        $resReject->assertStatus(200);
        $this->assertEquals('rejected', $resReject->json('data.status'));
        $this->assertEquals('Hồ sơ chưa đạt tiêu chuẩn nội dung', $resReject->json('data.rejection_reason'));

        // Verify audit log
        $this->assertDatabaseHas('creator_audit_logs', [
            'user_id' => $this->reader->id,
            'event_type' => 'application_rejected',
            'reason' => 'Hồ sơ chưa đạt tiêu chuẩn nội dung',
        ]);
    }

    /**
     * 13. Reader can resubmit a rejected application with incremented version.
     */
    public function test_reader_can_resubmit_rejected_application(): void
    {
        $resSubmit = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Resubmit',
            'bio' => 'Bio v1',
            'reason' => 'Reason v1',
        ]);
        $appId = $resSubmit->json('data.id');

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creator-applications/{$appId}/reject", [
            'reason' => 'Cần bổ sung thêm thông tin bio',
        ]);

        // Resubmit with updated bio
        $resResubmit = $this->actingAs($this->reader, 'sanctum')->putJson("/api/creator-applications/{$appId}/resubmit", [
            'pen_name' => 'Tác Giả Resubmit',
            'bio' => 'Bio v2 đã bổ sung đầy đủ kinh nghiệm vẽ minh họa',
            'reason' => 'Reason v2',
        ]);

        $resResubmit->assertStatus(200);
        $this->assertEquals('pending', $resResubmit->json('data.status'));
        $this->assertEquals(2, $resResubmit->json('data.version'));
        $this->assertEquals('Bio v2 đã bổ sung đầy đủ kinh nghiệm vẽ minh họa', $resResubmit->json('data.bio'));

        // Check history contains both v1 and v2
        $this->assertDatabaseCount('creator_application_histories', 3); // submitted v1, rejected v1, resubmitted v2
    }

    /**
     * 14. Admin approves application: role promotes to creator, CR- code created, audit log written.
     */
    public function test_admin_can_approve_application(): void
    {
        $resSubmit = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Thành Công',
            'bio' => 'Bio họa sĩ',
            'reason' => 'Mong muốn xuất bản tác phẩm',
        ]);
        $appId = $resSubmit->json('data.id');

        $resApprove = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creator-applications/{$appId}/approve");
        $resApprove->assertStatus(200);
        $this->assertEquals('approved', $resApprove->json('data.status'));

        // User role updated to creator
        $this->assertEquals('creator', $this->reader->fresh()->role);

        // Creator profile created
        $profile = CreatorProfile::where('user_id', $this->reader->id)->first();
        $this->assertNotNull($profile);
        $this->assertEquals('active', $profile->status);
        $this->assertMatchesRegularExpression('/^CR-\d{6}$/', $profile->creator_code);

        // Audit log created
        $this->assertDatabaseHas('creator_audit_logs', [
            'user_id' => $this->reader->id,
            'event_type' => 'approved',
        ]);
    }

    /**
     * 15. Creator updates pen name: old pen name retained as alias, comics updated, audit log written.
     */
    public function test_creator_can_update_profile_and_syncs_comic_author_name(): void
    {
        // Approve reader to become creator
        $resSubmit = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Bút Danh Cũ',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/creator-applications/'.$resSubmit->json('data.id').'/approve');

        // Create a comic under this creator
        Comic::create([
            'title' => 'Bộ Truyện Của Tác Giả',
            'slug' => 'bo-truyen-cua-tac-gia',
            'author_name' => 'Bút Danh Cũ',
            'user_id' => $this->reader->id,
            'cover_path' => 'covers/sample.webp',
            'status' => 'ongoing',
        ]);

        // Refresh reader model instance after approval updated role to creator in database
        $this->reader->refresh();

        // Creator updates pen name to new name
        $resUpdate = $this->actingAs($this->reader, 'sanctum')->putJson('/api/creator/profile', [
            'pen_name' => 'Bút Danh Mới Nhất',
            'bio' => 'Bio mới',
        ]);

        $resUpdate->assertStatus(200);
        $this->assertEquals('Bút Danh Mới Nhất', $resUpdate->json('data.pen_name'));

        // Check comic author_name was synchronized
        $this->assertDatabaseHas('comics', [
            'user_id' => $this->reader->id,
            'author_name' => 'Bút Danh Mới Nhất',
        ]);

        // Check old pen name is reserved as alias_creator
        $this->assertDatabaseHas('pen_name_reservations', [
            'pen_name' => 'Bút Danh Cũ',
            'reserved_by_user_id' => $this->reader->id,
            'reservation_type' => 'alias_creator',
        ]);

        // Check new pen name is reserved as creator
        $this->assertDatabaseHas('pen_name_reservations', [
            'pen_name' => 'Bút Danh Mới Nhất',
            'reserved_by_user_id' => $this->reader->id,
            'reservation_type' => 'creator',
        ]);

        // Check pen name history
        $this->assertDatabaseHas('pen_name_histories', [
            'user_id' => $this->reader->id,
            'old_pen_name' => 'Bút Danh Cũ',
            'new_pen_name' => 'Bút Danh Mới Nhất',
        ]);
    }

    /**
     * 16. Admin revokes creator privileges: demotes role, sets status revoked, writes audit log.
     */
    public function test_admin_can_revoke_creator_privileges(): void
    {
        // Approve reader to creator first
        $resSubmit = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Bị Thu Hồi',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/creator-applications/'.$resSubmit->json('data.id').'/approve');

        $profile = CreatorProfile::where('user_id', $this->reader->id)->first();

        // Revoke
        $resRevoke = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creators/{$profile->id}/revoke", [
            'reason' => 'Vi phạm nghiêm trọng tiêu chuẩn cộng đồng',
        ]);

        $resRevoke->assertStatus(200);
        $this->assertEquals('revoked', $resRevoke->json('data.status'));
        $this->assertEquals('reader', $this->reader->fresh()->role);

        $this->assertDatabaseHas('creator_audit_logs', [
            'user_id' => $this->reader->id,
            'event_type' => 'revoked',
            'reason' => 'Vi phạm nghiêm trọng tiêu chuẩn cộng đồng',
        ]);
    }

    /**
     * 17. Revoked creator cannot reapply without permission.
     */
    public function test_revoked_creator_cannot_reapply_without_permission(): void
    {
        $resSubmit = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Revoked Reapply',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/creator-applications/'.$resSubmit->json('data.id').'/approve');
        $profile = CreatorProfile::where('user_id', $this->reader->id)->first();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creators/{$profile->id}/revoke", ['reason' => 'Vi phạm']);

        // Attempt to re-apply
        $resReapply = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Revoked Reapply',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);

        $resReapply->assertStatus(403);
        $resReapply->assertJsonFragment(['error_code' => 'REAPPLY_NOT_ALLOWED']);
    }

    /**
     * 18. Admin can grant and revoke reapply permission.
     */
    public function test_admin_can_grant_and_revoke_reapply_permission(): void
    {
        $resSubmit = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Grant Revoke',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/creator-applications/'.$resSubmit->json('data.id').'/approve');
        $profile = CreatorProfile::where('user_id', $this->reader->id)->first();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creators/{$profile->id}/revoke", ['reason' => 'Vi phạm']);

        // Grant reapply
        $resGrant = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creators/{$profile->id}/grant-reapply", [
            'reason' => 'Đã giải quyết khiếu nại thỏa đáng',
        ]);
        $resGrant->assertStatus(200);
        $this->assertTrue($resGrant->json('data.can_reapply'));

        // Revoke reapply before user submits application
        $resRevokeReapply = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creators/{$profile->id}/revoke-reapply", [
            'reason' => 'Phát hiện thêm hành vi tái phạm',
        ]);
        $resRevokeReapply->assertStatus(200);
        $this->assertFalse($resRevokeReapply->json('data.can_reapply'));
    }

    /**
     * 19. Revoked creator reapplies when granted, consumes grant, and reapply rejection logs proper event.
     */
    public function test_revoked_creator_reapplies_consumes_grant_and_reapply_rejection_logs_proper_event(): void
    {
        $resSubmit = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Reapply Full Flow',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/creator-applications/'.$resSubmit->json('data.id').'/approve');
        $profile = CreatorProfile::where('user_id', $this->reader->id)->first();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creators/{$profile->id}/revoke", ['reason' => 'Vi phạm']);

        // Grant reapply
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creators/{$profile->id}/grant-reapply", ['reason' => 'Cho phép nộp lại']);

        // User reapplies
        $resReapply = $this->actingAs($this->reader, 'sanctum')->postJson('/api/creator-applications', [
            'pen_name' => 'Tác Giả Reapply Full Flow',
            'bio' => 'Bio nộp lại',
            'reason' => 'Cam kết tuân thủ quy định',
        ]);
        $resReapply->assertStatus(201);
        $reappliedAppId = $resReapply->json('data.id');

        // Verify can_reapply was consumed to false
        $this->assertFalse((bool) $profile->fresh()->can_reapply);
        $this->assertEquals($reappliedAppId, $profile->fresh()->reapplied_application_id);

        // Admin rejects re-application -> should log 'reapply_rejected'
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/creator-applications/{$reappliedAppId}/reject", [
            'reason' => 'Hồ sơ nộp lại vẫn chưa đạt cam kết',
        ])->assertStatus(200);

        $this->assertDatabaseHas('creator_audit_logs', [
            'user_id' => $this->reader->id,
            'event_type' => 'reapply_rejected',
            'reason' => 'Hồ sơ nộp lại vẫn chưa đạt cam kết',
        ]);
    }

    /**
     * 20. Admin Excel export endpoints stream valid .xlsx OpenXML download responses.
     */
    public function test_admin_excel_export_endpoints_stream_valid_xlsx_files(): void
    {
        // 1. Export applications
        $resAppsExport = $this->actingAs($this->admin, 'sanctum')->get('/api/admin/creator-applications/export');
        $resAppsExport->assertStatus(200);
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', (string) $resAppsExport->headers->get('content-type'));
        $this->assertStringContainsString('.xlsx', (string) $resAppsExport->headers->get('content-disposition'));

        // 2. Export creators
        $resCreatorsExport = $this->actingAs($this->admin, 'sanctum')->get('/api/admin/creators/export');
        $resCreatorsExport->assertStatus(200);
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', (string) $resCreatorsExport->headers->get('content-type'));
        $this->assertStringContainsString('.xlsx', (string) $resCreatorsExport->headers->get('content-disposition'));
    }
}
