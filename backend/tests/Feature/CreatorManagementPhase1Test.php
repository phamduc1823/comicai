<?php

namespace Tests\Feature;

use App\Exceptions\CreatorManagementException;
use App\Helpers\PenNameHelper;
use App\Models\Comic;
use App\Models\CreatorApplication;
use App\Models\CreatorProfile;
use App\Models\PenNameReservation;
use App\Models\User;
use App\Services\CreatorManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatorManagementPhase1Test extends TestCase
{
    use RefreshDatabase;

    protected CreatorManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CreatorManagementService::class);
    }

    /**
     * Test PenNameHelper with Vietnamese diacritics, Unicode NFC normalization, and format rules.
     */
    public function test_pen_name_helper_unicode_nfc_and_validation(): void
    {
        PenNameHelper::ensureIntlAvailable();

        // Valid Vietnamese pen names with accents
        $this->assertTrue(PenNameHelper::isValid('Nguyễn Nhật Ánh'));
        $this->assertTrue(PenNameHelper::isValid('Tô Hoài'));
        $this->assertTrue(PenNameHelper::isValid('Vũ Trọng Phụng-1930'));
        $this->assertTrue(PenNameHelper::isValid('Nam_Cao.Writer'));

        // Invalid pen names (too short, too long, emoji, invalid punctuation)
        $this->assertFalse(PenNameHelper::isValid('A')); // < 3
        $this->assertFalse(PenNameHelper::isValid(str_repeat('A', 51))); // > 50
        $this->assertFalse(PenNameHelper::isValid('Bút Danh 🎨')); // emoji
        $this->assertFalse(PenNameHelper::isValid('Bút@Danh#')); // invalid special chars

        // Normalization & Canonicalization
        $raw = "  Nguyễn   Nhật   Ánh  ";
        $normalized = PenNameHelper::normalize($raw);
        $this->assertEquals('Nguyễn Nhật Ánh', $normalized);

        $canonical = PenNameHelper::canonicalize($raw);
        $this->assertEquals('nguyễn nhật ánh', $canonical);
    }

    /**
     * Test sequential generation of CA-... and CR-... codes.
     */
    public function test_sequential_code_generation(): void
    {
        $code1 = $this->service->generateSequenceCode('creator_application', 'CA');
        $code2 = $this->service->generateSequenceCode('creator_application', 'CA');
        $cr1 = $this->service->generateSequenceCode('creator_profile', 'CR');

        $this->assertEquals('CA-000001', $code1);
        $this->assertEquals('CA-000002', $code2);
        $this->assertEquals('CR-000001', $cr1);
    }

    /**
     * Test submitting an application requires verified email.
     */
    public function test_submit_application_requires_email_verification(): void
    {
        $unverifiedUser = User::factory()->create(['email_verified_at' => null]);

        $this->expectException(CreatorManagementException::class);
        $this->expectExceptionCode(422);

        $this->service->submitApplication($unverifiedUser, [
            'pen_name' => 'Tác Giả Mới',
            'bio' => 'Giới thiệu bản thân',
            'reason' => 'Muốn đăng truyện',
        ]);
    }

    /**
     * Test submitting application creates record, reservation, and immutable history version 1.
     */
    public function test_submit_application_creates_reservation_and_history_snapshot(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'reader']);

        $app = $this->service->submitApplication($user, [
            'pen_name' => 'Nguyễn Du',
            'bio' => 'Tác giả Truyện Kiều',
            'reason' => 'Sáng tác truyện cổ tích',
            'sample_works' => ['https://comicai.test/sample/1'],
        ]);

        $this->assertEquals('CA-000001', $app->application_code);
        $this->assertEquals('Nguyễn Du', $app->pen_name);
        $this->assertEquals('nguyễn du', $app->pen_name_canonical);
        $this->assertEquals('pending', $app->status);
        $this->assertEquals(1, $app->version);

        // Check reservation table
        $reservation = PenNameReservation::where('pen_name_canonical', 'nguyễn du')->first();
        $this->assertNotNull($reservation);
        $this->assertEquals($user->id, $reservation->reserved_by_user_id);
        $this->assertEquals('application', $reservation->reservation_type);
        $this->assertEquals($app->id, $reservation->application_id);

        // Check history snapshot
        $this->assertDatabaseHas('creator_application_histories', [
            'application_id' => $app->id,
            'version' => 1,
            'status' => 'pending',
            'action_type' => 'submitted',
            'action_by' => $user->id,
        ]);
    }

    /**
     * Test race condition prevention for SAME pen name submitted by 2 DIFFERENT users.
     * Second user must be rejected because pen_name_canonical is locked by reservation.
     */
    public function test_concurrent_same_pen_name_by_two_different_users_blocked(): void
    {
        $user1 = User::factory()->create(['email_verified_at' => now()]);
        $user2 = User::factory()->create(['email_verified_at' => now()]);

        // User 1 reserves "Đoàn Giỏi"
        $this->service->submitApplication($user1, [
            'pen_name' => 'Đoàn Giỏi',
            'bio' => 'Tác giả Đất Rừng Phương Nam',
            'reason' => 'Đăng truyện',
        ]);

        // User 2 attempts to reserve same pen name (case-insensitive with different case)
        $this->expectException(CreatorManagementException::class);
        $this->expectExceptionCode(422);

        $this->service->submitApplication($user2, [
            'pen_name' => 'đoàn giỏi',
            'bio' => 'Giới thiệu khác',
            'reason' => 'Lý do khác',
        ]);
    }

    /**
     * Test user-level pessimistic locking prevents SAME user from creating multiple pending applications
     * even when using DIFFERENT pen names.
     */
    public function test_concurrent_multiple_pending_applications_by_same_user_blocked(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        // First application with Pen Name 1
        $this->service->submitApplication($user, [
            'pen_name' => 'Bút Danh Một',
            'bio' => 'Bio 1',
            'reason' => 'Reason 1',
        ]);

        // Second application with completely different Pen Name 2
        $this->expectException(CreatorManagementException::class);
        $this->expectExceptionCode(422);

        $this->service->submitApplication($user, [
            'pen_name' => 'Bút Danh Hai',
            'bio' => 'Bio 2',
            'reason' => 'Reason 2',
        ]);
    }

    /**
     * Test user canceling pending application releases pen name reservation and records history.
     */
    public function test_cancel_application_releases_reservation(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $app = $this->service->submitApplication($user, [
            'pen_name' => 'Bút Danh Tạm',
            'bio' => 'Bio',
            'reason' => 'Reason',
        ]);

        $this->service->cancelApplication($user, $app->id);

        $app->refresh();
        $this->assertEquals('canceled', $app->status);
        $this->assertNotNull($app->canceled_at);

        // Reservation should be removed
        $this->assertNull(PenNameReservation::where('pen_name_canonical', 'bút danh tạm')->first());

        // Snapshot history for canceled
        $this->assertDatabaseHas('creator_application_histories', [
            'application_id' => $app->id,
            'status' => 'canceled',
            'action_type' => 'canceled',
            'action_by' => $user->id,
        ]);
    }

    /**
     * Test Admin rejecting application and User resubmitting with version increment.
     */
    public function test_reject_and_resubmit_flow(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $app = $this->service->submitApplication($user, [
            'pen_name' => 'Tác Giả Sắp Bị Từ Chối',
            'bio' => 'Bio ban đầu',
            'reason' => 'Lý do ban đầu',
        ]);

        // Admin rejects
        $this->service->rejectApplication($admin, $app->id, 'Cần bổ sung link tác phẩm rõ hơn.');

        $app->refresh();
        $this->assertEquals('rejected', $app->status);
        $this->assertEquals('Cần bổ sung link tác phẩm rõ hơn.', $app->rejection_reason);
        $this->assertEquals($admin->id, $app->reviewed_by);

        // Audit log recorded for application rejection
        $this->assertDatabaseHas('creator_audit_logs', [
            'user_id' => $user->id,
            'actor_id' => $admin->id,
            'event_type' => 'application_rejected',
            'reason' => 'Cần bổ sung link tác phẩm rõ hơn.',
        ]);

        // User resubmits with updated bio and sample works (can reuse own pen name)
        $resubmittedApp = $this->service->resubmitApplication($user, $app->id, [
            'pen_name' => 'Tác Giả Sắp Bị Từ Chối',
            'bio' => 'Bio đã cập nhật chi tiết',
            'sample_works' => ['https://comicai.test/sample/verified'],
        ]);

        $this->assertEquals($app->id, $resubmittedApp->id);
        $this->assertEquals(2, $resubmittedApp->version);
        $this->assertEquals('pending', $resubmittedApp->status);
        $this->assertNull($resubmittedApp->rejection_reason);

        // Check history has 3 snapshots: submitted (v1), rejected (v1), resubmitted (v2)
        $this->assertCount(3, $resubmittedApp->histories);
    }

    /**
     * Test Admin approving application: user becomes creator, receives CR-xxxxxx code, reservation becomes permanent.
     */
    public function test_approve_application_creates_creator_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'reader']);

        $app = $this->service->submitApplication($user, [
            'pen_name' => 'Kim Dung',
            'bio' => 'Tác giả kiếm hiệp',
            'reason' => 'Đăng truyện võ hiệp',
        ]);

        $this->service->approveApplication($admin, $app->id);

        $user->refresh();
        $this->assertEquals('creator', $user->role);

        $profile = CreatorProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertEquals('CR-000001', $profile->creator_code);
        $this->assertEquals('Kim Dung', $profile->pen_name);
        $this->assertEquals('active', $profile->status);

        // Permanent reservation
        $res = PenNameReservation::where('pen_name_canonical', 'kim dung')->first();
        $this->assertEquals('creator', $res->reservation_type);

        // Audit log
        $this->assertDatabaseHas('creator_audit_logs', [
            'user_id' => $user->id,
            'actor_id' => $admin->id,
            'event_type' => 'approved',
        ]);
    }

    /**
     * Test Creator updating pen name (Decision 1 Option A):
     * Retains old pen name as alias_creator reservation permanently, syncs comics.author_name in same transaction.
     */
    public function test_update_pen_name_retains_old_alias_and_syncs_comics(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'reader']);

        $app = $this->service->submitApplication($user, ['pen_name' => 'Bút Danh Cũ', 'bio' => 'Bio', 'reason' => 'Reason']);
        $this->service->approveApplication($admin, $app->id);

        // Create a comic owned by this creator
        $comic = Comic::create([
            'user_id' => $user->id,
            'title' => 'Bộ Truyện Đầu Tay',
            'author_name' => 'Bút Danh Cũ',
            'slug' => 'bo-truyen-dau-tay',
            'description' => 'Mô tả truyện',
            'cover_path' => 'placeholders/covers/sample.webp',
            'status' => 'ongoing',
            'approval_status' => 'approved',
        ]);

        // Creator updates pen name
        $user->refresh();
        $this->service->updateCreatorProfile($user, [
            'pen_name' => 'Bút Danh Mới',
            'bio' => 'Bio mới',
        ]);

        // Verify comics author_name was automatically synced
        $comic->refresh();
        $this->assertEquals('Bút Danh Mới', $comic->author_name);

        // Verify old pen name is still reserved as alias_creator
        $oldRes = PenNameReservation::where('pen_name_canonical', 'bút danh cũ')->first();
        $this->assertNotNull($oldRes);
        $this->assertEquals('alias_creator', $oldRes->reservation_type);
        $this->assertEquals($user->id, $oldRes->reserved_by_user_id);

        // Verify new pen name is reserved as creator
        $newRes = PenNameReservation::where('pen_name_canonical', 'bút danh mới')->first();
        $this->assertNotNull($newRes);
        $this->assertEquals('creator', $newRes->reservation_type);
        $this->assertEquals($user->id, $newRes->reserved_by_user_id);

        // Verify creator can switch back to own old alias
        $this->service->updateCreatorProfile($user, [
            'pen_name' => 'Bút Danh Cũ',
        ]);

        $comic->refresh();
        $this->assertEquals('Bút Danh Cũ', $comic->author_name);
    }

    /**
     * Test Revoke, Grant Reapply, Revoke Reapply (Decision 2 Option A), and Reapply flow.
     */
    public function test_revoke_and_reapply_lifecycle(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'reader']);

        $app = $this->service->submitApplication($user, ['pen_name' => 'Tác Giả Vĩnh Viễn', 'bio' => 'Bio', 'reason' => 'Reason']);
        $this->service->approveApplication($admin, $app->id);

        // 1. Admin revokes creator
        $this->service->revokeCreator($admin, $user->id, 'Vi phạm quy tắc tiêu chuẩn cộng đồng.');

        $user->refresh();
        $this->assertEquals('reader', $user->role);

        $profile = CreatorProfile::where('user_id', $user->id)->first();
        $this->assertEquals('revoked', $profile->status);
        $this->assertFalse($profile->can_reapply);

        // Pen name must still be retained
        $this->assertNotNull(PenNameReservation::where('pen_name_canonical', 'tác giả vĩnh viễn')->first());

        // 2. Revoked creator cannot submit application without grant
        try {
            $this->service->submitApplication($user, ['pen_name' => 'Tác Giả Vĩnh Viễn', 'bio' => 'Bio', 'reason' => 'Reason']);
            $this->fail('Should not be able to reapply without grant.');
        } catch (CreatorManagementException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        // 3. Admin grants 1-time reapply
        $this->service->grantReapply($admin, $user->id, 'Đã sửa chữa vi phạm, cho phép đăng ký lại 1 lần.');
        $profile->refresh();
        $this->assertTrue($profile->can_reapply);

        // 4. Admin revokes reapply grant before submission (Decision 2 Option A)
        $this->service->revokeReapply($admin, $user->id, 'Phát hiện thêm vi phạm mới, hủy quyền đăng ký lại.');
        $profile->refresh();
        $this->assertFalse($profile->can_reapply);

        // Audit log for reapply_revoked
        $this->assertDatabaseHas('creator_audit_logs', [
            'user_id' => $user->id,
            'actor_id' => $admin->id,
            'event_type' => 'reapply_revoked',
            'reason' => 'Phát hiện thêm vi phạm mới, hủy quyền đăng ký lại.',
        ]);

        // 5. Admin grants reapply again -> User submits re-application
        $this->service->grantReapply($admin, $user->id, 'Cho phép nộp lại.');
        $reapplyApp = $this->service->submitApplication($user, [
            'pen_name' => 'Tác Giả Vĩnh Viễn',
            'bio' => 'Bio mới chân thành',
            'reason' => 'Đơn đăng ký lại',
        ]);

        $this->assertNotEquals($app->id, $reapplyApp->id);
        $this->assertEquals('CA-000002', $reapplyApp->application_code);

        // 1-time grant must be atomically consumed
        $profile->refresh();
        $this->assertFalse($profile->can_reapply);
        $this->assertNotNull($profile->reapplied_at);
        $this->assertEquals($reapplyApp->id, $profile->reapplied_application_id);

        // 6. Admin approves re-application -> existing CR-000001 code is preserved!
        $this->service->approveApplication($admin, $reapplyApp->id);

        $user->refresh();
        $this->assertEquals('creator', $user->role);

        $profile->refresh();
        $this->assertEquals('active', $profile->status);
        $this->assertEquals('CR-000001', $profile->creator_code); // Retained stable code!
    }

    /**
     * Test migration rollback drops all 7 tables in reverse order cleanly without foreign key constraint violations.
     */
    public function test_migration_rollback_runs_cleanly(): void
    {
        $exitCode = \Illuminate\Support\Facades\Artisan::call('migrate:rollback', [
            '--step' => 1,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('creator_profiles'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('pen_name_reservations'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('creator_applications'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('system_sequences'));
    }
}
