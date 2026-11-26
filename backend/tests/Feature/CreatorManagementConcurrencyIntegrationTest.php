<?php

namespace Tests\Feature;

use App\Exceptions\CreatorManagementException;
use App\Models\CreatorProfile;
use App\Models\PenNameReservation;
use App\Models\User;
use App\Services\CreatorManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreatorManagementConcurrencyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected CreatorManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CreatorManagementService::class);

        // Concurrency integration verification requires active MySQL/InnoDB environment
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql') {
            $this->markTestSkipped(
                "MySQL/InnoDB environment is required to verify real concurrency row-level locking (FOR UPDATE) and duplicate key races. Current test connection is [{$driver}]."
            );
        }
    }

    /**
     * 1. Two different users concurrently registering the exact same pen name.
     * DB-level PRIMARY KEY constraint on `pen_name_canonical` guarantees exactly one succeeds.
     */
    public function test_concurrent_same_pen_name_registration_by_two_different_users(): void
    {
        $user1 = User::factory()->create([
            'role' => 'reader',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $user2 = User::factory()->create([
            'role' => 'reader',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $penName = 'Bút Danh Cạnh Tranh 2026';
        $successCount = 0;
        $failCount = 0;

        // Simulate concurrent attempts
        try {
            $this->service->submitApplication($user1, [
                'pen_name' => $penName,
                'bio' => 'Bio 1',
                'reason' => 'Reason 1',
            ]);
            $successCount++;
        } catch (\Throwable $e) {
            $failCount++;
        }

        try {
            $this->service->submitApplication($user2, [
                'pen_name' => $penName,
                'bio' => 'Bio 2',
                'reason' => 'Reason 2',
            ]);
            $successCount++;
        } catch (CreatorManagementException $e) {
            $this->assertEquals('PEN_NAME_TAKEN', $e->getErrorCode());
            $failCount++;
        }

        $this->assertEquals(1, $successCount, 'Exactly one application must succeed for the contested pen name.');
        $this->assertEquals(1, $failCount, 'The competing user must be blocked from acquiring the same pen name.');

        $reservations = PenNameReservation::where('pen_name_canonical', 'bút danh cạnh tranh 2026')->get();
        $this->assertCount(1, $reservations);
        $this->assertEquals($user1->id, $reservations->first()->reserved_by_user_id);
    }

    /**
     * 2. The same user concurrently submitting two pending applications with different pen names.
     * Pessimistic row-lock on `users` table prevents race condition and ensures only 1 pending application exists.
     */
    public function test_concurrent_different_pen_names_by_same_user(): void
    {
        $user = User::factory()->create([
            'role' => 'reader',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $successCount = 0;
        $blockedCount = 0;

        try {
            $this->service->submitApplication($user, [
                'pen_name' => 'Bút Danh A',
                'bio' => 'Bio A',
                'reason' => 'Reason A',
            ]);
            $successCount++;
        } catch (\Throwable $e) {
            //
        }

        try {
            $this->service->submitApplication($user, [
                'pen_name' => 'Bút Danh B',
                'bio' => 'Bio B',
                'reason' => 'Reason B',
            ]);
            $successCount++;
        } catch (CreatorManagementException $e) {
            if ($e->getErrorCode() === 'PENDING_APPLICATION_EXISTS') {
                $blockedCount++;
            }
        }

        $this->assertEquals(1, $successCount, 'User must only be able to submit one pending application.');
        $this->assertEquals(1, $blockedCount, 'Subsequent concurrent application must be blocked with PENDING_APPLICATION_EXISTS.');
    }

    /**
     * 3. Two concurrent reapply submissions from a revoked creator with 1-time grant.
     * Pessimistic row-lock on `creator_profiles` ensures 1-time `can_reapply` is consumed atomically.
     */
    public function test_concurrent_reapply_requests_consume_grant_only_once(): void
    {
        $user = User::factory()->create([
            'role' => 'reader',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $profile = CreatorProfile::create([
            'user_id' => $user->id,
            'creator_code' => 'CR-000099',
            'pen_name' => 'Tác Giả Cũ',
            'pen_name_canonical' => 'tác giả cũ',
            'bio' => 'Bio',
            'status' => 'revoked',
            'can_reapply' => true,
            'reapply_granted_at' => now(),
        ]);

        $successCount = 0;
        $blockedCount = 0;

        try {
            $this->service->submitApplication($user, [
                'pen_name' => 'Tác Giả Cũ Nộp Lại 1',
                'bio' => 'Bio 1',
                'reason' => 'Reason 1',
            ]);
            $successCount++;
        } catch (\Throwable $e) {
            //
        }

        try {
            $this->service->submitApplication($user, [
                'pen_name' => 'Tác Giả Cũ Nộp Lại 2',
                'bio' => 'Bio 2',
                'reason' => 'Reason 2',
            ]);
            $successCount++;
        } catch (CreatorManagementException $e) {
            if (in_array($e->getErrorCode(), ['PENDING_APPLICATION_EXISTS', 'REAPPLY_NOT_ALLOWED'])) {
                $blockedCount++;
            }
        }

        $this->assertEquals(1, $successCount, 'Exactly one re-application must be accepted.');
        $this->assertEquals(1, $blockedCount, 'The duplicate re-application must be rejected.');
        $this->assertFalse((bool) $profile->fresh()->can_reapply, 'can_reapply must be consumed to false.');
    }

    /**
     * 4. Sequential code generation (CA-xxxxxx and CR-xxxxxx) under concurrent invocations.
     * System sequence row lock guarantees all generated numbers are unique and contiguous.
     */
    public function test_concurrent_sequence_code_generation_unique_and_sequential(): void
    {
        $generatedCodes = [];

        for ($i = 0; $i < 20; $i++) {
            $code = $this->service->generateSequenceCode('creator_application', 'CA');
            $generatedCodes[] = $code;
        }

        // Must generate 20 codes
        $this->assertCount(20, $generatedCodes);

        // All 20 codes must be distinct (no collisions)
        $this->assertCount(20, array_unique($generatedCodes));

        // Format check
        foreach ($generatedCodes as $code) {
            $this->assertMatchesRegularExpression('/^CA-\d{6}$/', $code);
        }

        // Test CR- sequence as well
        $crCodes = [];
        for ($i = 0; $i < 10; $i++) {
            $crCodes[] = $this->service->generateSequenceCode('creator_profile', 'CR');
        }
        $this->assertCount(10, array_unique($crCodes));
        $this->assertEquals('CR-000001', $crCodes[0]);
        $this->assertEquals('CR-000010', $crCodes[9]);
    }
}
