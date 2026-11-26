<?php

namespace App\Services;

use App\Exceptions\CreatorManagementException;
use App\Helpers\PenNameHelper;
use App\Models\Comic;
use App\Models\CreatorApplication;
use App\Models\CreatorApplicationHistory;
use App\Models\CreatorAuditLog;
use App\Models\CreatorProfile;
use App\Models\PenNameHistory;
use App\Models\PenNameReservation;
use App\Models\SystemSequence;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

class CreatorManagementService
{
    /**
     * Atomically generate the next sequential code with pessimistic row-locking on system_sequences.
     */
    public function generateSequenceCode(string $sequenceName, string $prefix): string
    {
        // Must be called within an active transaction or will self-wrap in transaction
        $callback = function () use ($sequenceName, $prefix) {
            $seq = SystemSequence::query()
                ->where('name', $sequenceName)
                ->lockForUpdate()
                ->first();

            if (! $seq) {
                $seq = SystemSequence::create([
                    'name' => $sequenceName,
                    'current_val' => 0,
                    'updated_at' => now(),
                ]);
            }

            $nextVal = $seq->current_val + 1;
            $seq->current_val = $nextVal;
            $seq->updated_at = now();
            $seq->save();

            return sprintf('%s-%06d', $prefix, $nextVal);
        };

        return DB::transactionLevel() > 0 ? $callback() : DB::transaction($callback);
    }

    /**
     * Submit a creator application (first-time or re-application).
     * Protected by User-level lock and PenNameReservation primary key lock.
     *
     * @throws CreatorManagementException
     */
    public function submitApplication(User $user, array $data): CreatorApplication
    {
        return DB::transaction(function () use ($user, $data) {
            // 1. Check email verification requirement
            if (! $user->email_verified_at) {
                throw new CreatorManagementException('Bạn cần xác minh email trước khi đăng ký làm tác giả.', 422, 'EMAIL_NOT_VERIFIED');
            }

            // 2. Prevent already active creator from applying
            if ($user->role === 'creator') {
                throw new CreatorManagementException('Tài khoản của bạn đã là Creator.', 422, 'ALREADY_CREATOR');
            }

            // 3. User-level pessimistic lock to prevent concurrent applications from the same user (even with different pen names)
            User::query()->where('id', $user->id)->lockForUpdate()->first();

            // 4. Check if user already has an active pending application
            $hasPending = CreatorApplication::query()
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->exists();

            if ($hasPending) {
                throw new CreatorManagementException('Bạn đã có một hồ sơ đăng ký đang chờ xét duyệt.', 422, 'PENDING_APPLICATION_EXISTS');
            }

            // 5. If user has a revoked creator profile: verify and consume 1-time reapply grant
            $creatorProfile = CreatorProfile::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            $isReapplying = false;
            if ($creatorProfile && $creatorProfile->status === 'revoked') {
                if (! $creatorProfile->can_reapply) {
                    throw new CreatorManagementException(
                        'Tài khoản của bạn đã bị thu hồi quyền Creator và chưa được phép đăng ký lại.',
                        403,
                        'REAPPLY_NOT_ALLOWED'
                    );
                }

                $isReapplying = true;
                // Atomically consume the 1-time reapply grant
                $creatorProfile->can_reapply = false;
                $creatorProfile->reapplied_at = now();
            }

            // 6. Normalize and validate pen name
            $rawPenName = $data['pen_name'] ?? '';
            if (! PenNameHelper::isValid($rawPenName)) {
                throw new CreatorManagementException(
                    'Bút danh không hợp lệ. Bút danh phải từ 3 đến 50 ký tự, chỉ gồm chữ cái, số, khoảng trắng và các ký tự . _ -',
                    422,
                    'INVALID_PEN_NAME'
                );
            }

            $normalizedPenName = PenNameHelper::normalize($rawPenName);
            $canonicalPenName = PenNameHelper::canonicalize($normalizedPenName);

            // 7. Atomic pen name reservation check / acquisition
            $existingReservation = PenNameReservation::query()
                ->where('pen_name_canonical', $canonicalPenName)
                ->lockForUpdate()
                ->first();

            if ($existingReservation) {
                // If reserved by someone else -> throw error
                if ($existingReservation->reserved_by_user_id != $user->id) {
                    throw new CreatorManagementException(
                        'Bút danh này đã được sử dụng hoặc đang chờ xét duyệt bởi tác giả khác.',
                        422,
                        'PEN_NAME_TAKEN'
                    );
                }
                // If already reserved by this user (e.g. historical creator alias): update type to application
                $existingReservation->reservation_type = 'application';
                $existingReservation->save();
            } else {
                // Acquire reservation atomic
                $existingReservation = PenNameReservation::create([
                    'pen_name_canonical' => $canonicalPenName,
                    'pen_name' => $normalizedPenName,
                    'reserved_by_user_id' => $user->id,
                    'reservation_type' => 'application',
                ]);
            }

            // 8. Generate application code CA-xxxxxx
            $appCode = $this->generateSequenceCode('creator_application', 'CA');

            // 9. Create application record
            $application = CreatorApplication::create([
                'application_code' => $appCode,
                'user_id' => $user->id,
                'pen_name' => $normalizedPenName,
                'pen_name_canonical' => $canonicalPenName,
                'bio' => trim($data['bio'] ?? ''),
                'reason' => trim($data['reason'] ?? ''),
                'sample_works' => $data['sample_works'] ?? null,
                'status' => 'pending',
                'version' => 1,
            ]);

            // Link reservation to application
            $existingReservation->application_id = $application->id;
            $existingReservation->save();

            // 10. If reapplying, link profile to this application and save
            if ($isReapplying && $creatorProfile) {
                $creatorProfile->reapplied_application_id = $application->id;
                $creatorProfile->save();

                // Ghi audit log sự kiện nộp lại hồ sơ sau khi được cấp phép
                CreatorAuditLog::create([
                    'user_id' => $user->id,
                    'actor_id' => $user->id,
                    'application_id' => $application->id,
                    'event_type' => 'reapplied',
                    'old_values' => ['application_code' => null],
                    'new_values' => ['application_code' => $appCode],
                    'reason' => 'Nộp lại hồ sơ đăng ký sau khi được Admin cấp phép',
                    'created_at' => now(),
                ]);
            }

            // 11. Create immutable snapshot history for version 1
            CreatorApplicationHistory::create([
                'application_id' => $application->id,
                'version' => 1,
                'pen_name' => $application->pen_name,
                'bio' => $application->bio,
                'reason' => $application->reason,
                'sample_works' => $application->sample_works,
                'status' => 'pending',
                'action_type' => 'submitted',
                'action_by' => $user->id,
                'action_at' => now(),
                'snapshot_data' => [
                    'application_id' => $application->id,
                    'application_code' => $application->application_code,
                    'version' => 1,
                    'pen_name' => $application->pen_name,
                    'pen_name_canonical' => $application->pen_name_canonical,
                    'bio' => $application->bio,
                    'reason' => $application->reason,
                    'sample_works' => $application->sample_works,
                    'status' => 'pending',
                    'action_type' => 'submitted',
                    'action_by' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'action_at' => now()->toIso8601String(),
                ],
                'created_at' => now(),
            ]);

            return $application;
        });
    }

    /**
     * Cancel an active pending application by the applicant.
     */
    public function cancelApplication(User $user, int $applicationId): CreatorApplication
    {
        return DB::transaction(function () use ($user, $applicationId) {
            $application = CreatorApplication::query()
                ->where('id', $applicationId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $application) {
                throw new CreatorManagementException('Hồ sơ không tồn tại hoặc bạn không có quyền truy cập.', 404, 'APPLICATION_NOT_FOUND');
            }

            if ($application->status !== 'pending') {
                throw new CreatorManagementException('Chỉ có thể hủy hồ sơ đang chờ xét duyệt.', 422, 'CANNOT_CANCEL_NON_PENDING');
            }

            $application->status = 'canceled';
            $application->canceled_at = now();
            $application->save();

            // Release application reservation
            PenNameReservation::query()
                ->where('application_id', $application->id)
                ->where('reservation_type', 'application')
                ->delete();

            // Create immutable snapshot history for cancellation
            CreatorApplicationHistory::create([
                'application_id' => $application->id,
                'version' => $application->version,
                'pen_name' => $application->pen_name,
                'bio' => $application->bio,
                'reason' => $application->reason,
                'sample_works' => $application->sample_works,
                'status' => 'canceled',
                'action_type' => 'canceled',
                'action_by' => $user->id,
                'action_at' => now(),
                'snapshot_data' => [
                    'application_id' => $application->id,
                    'application_code' => $application->application_code,
                    'version' => $application->version,
                    'pen_name' => $application->pen_name,
                    'status' => 'canceled',
                    'action_type' => 'canceled',
                    'action_by' => [
                        'id' => $user->id,
                        'name' => $user->name,
                    ],
                    'action_at' => now()->toIso8601String(),
                ],
                'created_at' => now(),
            ]);

            return $application;
        });
    }

    /**
     * Resubmit a rejected application with edits.
     * Uses the exact same application record, increments version, and creates new snapshot history.
     */
    public function resubmitApplication(User $user, int $applicationId, array $data): CreatorApplication
    {
        return DB::transaction(function () use ($user, $applicationId, $data) {
            User::query()->where('id', $user->id)->lockForUpdate()->first();

            $application = CreatorApplication::query()
                ->where('id', $applicationId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $application) {
                throw new CreatorManagementException('Hồ sơ không tồn tại hoặc bạn không có quyền truy cập.', 404, 'APPLICATION_NOT_FOUND');
            }

            if ($application->status !== 'rejected') {
                throw new CreatorManagementException('Chỉ có thể chỉnh sửa và gửi lại hồ sơ đã bị từ chối.', 422, 'CANNOT_RESUBMIT_NON_REJECTED');
            }

            // Normalize and validate pen name
            $rawPenName = $data['pen_name'] ?? $application->pen_name;
            if (! PenNameHelper::isValid($rawPenName)) {
                throw new CreatorManagementException('Bút danh không hợp lệ.', 422, 'INVALID_PEN_NAME');
            }

            $normalizedPenName = PenNameHelper::normalize($rawPenName);
            $canonicalPenName = PenNameHelper::canonicalize($normalizedPenName);

            // Handle pen name reservation if changed
            if ($canonicalPenName !== $application->pen_name_canonical) {
                $existingReservation = PenNameReservation::query()
                    ->where('pen_name_canonical', $canonicalPenName)
                    ->lockForUpdate()
                    ->first();

                if ($existingReservation && $existingReservation->reserved_by_user_id != $user->id) {
                    throw new CreatorManagementException('Bút danh này đã được sử dụng bởi tác giả khác.', 422, 'PEN_NAME_TAKEN');
                }

                if (! $existingReservation) {
                    PenNameReservation::create([
                        'pen_name_canonical' => $canonicalPenName,
                        'pen_name' => $normalizedPenName,
                        'reserved_by_user_id' => $user->id,
                        'reservation_type' => 'application',
                        'application_id' => $application->id,
                    ]);
                } else {
                    $existingReservation->application_id = $application->id;
                    $existingReservation->reservation_type = 'application';
                    $existingReservation->save();
                }
            } else {
                // Re-ensure reservation exists
                PenNameReservation::query()->firstOrCreate(
                    ['pen_name_canonical' => $canonicalPenName],
                    [
                        'pen_name' => $normalizedPenName,
                        'reserved_by_user_id' => $user->id,
                        'reservation_type' => 'application',
                        'application_id' => $application->id,
                    ]
                );
            }

            $newVersion = $application->version + 1;
            $application->version = $newVersion;
            $application->pen_name = $normalizedPenName;
            $application->pen_name_canonical = $canonicalPenName;
            $application->bio = trim($data['bio'] ?? $application->bio);
            $application->reason = trim($data['reason'] ?? $application->reason);
            $application->sample_works = $data['sample_works'] ?? $application->sample_works;
            $application->status = 'pending';
            $application->rejection_reason = null;
            $application->reviewed_by = null;
            $application->reviewed_at = null;
            $application->save();

            // Create immutable snapshot history for new version
            CreatorApplicationHistory::create([
                'application_id' => $application->id,
                'version' => $newVersion,
                'pen_name' => $application->pen_name,
                'bio' => $application->bio,
                'reason' => $application->reason,
                'sample_works' => $application->sample_works,
                'status' => 'pending',
                'action_type' => 'resubmitted',
                'action_by' => $user->id,
                'action_at' => now(),
                'snapshot_data' => [
                    'application_id' => $application->id,
                    'application_code' => $application->application_code,
                    'version' => $newVersion,
                    'pen_name' => $application->pen_name,
                    'pen_name_canonical' => $application->pen_name_canonical,
                    'bio' => $application->bio,
                    'reason' => $application->reason,
                    'sample_works' => $application->sample_works,
                    'status' => 'pending',
                    'action_type' => 'resubmitted',
                    'action_by' => [
                        'id' => $user->id,
                        'name' => $user->name,
                    ],
                    'action_at' => now()->toIso8601String(),
                ],
                'created_at' => now(),
            ]);

            return $application;
        });
    }

    /**
     * Approve a creator application (first-time or re-application).
     * Grants role 'creator', generates CR-xxxxxx (or keeps existing for re-application), creates audit log.
     */
    public function approveApplication(User $admin, int $applicationId): CreatorApplication
    {
        $application = DB::transaction(function () use ($admin, $applicationId) {
            $application = CreatorApplication::query()
                ->where('id', $applicationId)
                ->lockForUpdate()
                ->first();

            if (! $application) {
                throw new CreatorManagementException('Hồ sơ không tồn tại.', 404, 'APPLICATION_NOT_FOUND');
            }

            if ($application->status !== 'pending') {
                throw new CreatorManagementException('Chỉ có thể xét duyệt hồ sơ đang chờ duyệt.', 422, 'CANNOT_APPROVE_NON_PENDING');
            }

            $application->status = 'approved';
            $application->reviewed_by = $admin->id;
            $application->reviewed_at = now();
            $application->save();

            // Elevate target user role to creator
            User::query()->where('id', $application->user_id)->update(['role' => 'creator']);

            // Check if creator profile already exists (re-application flow)
            $profile = CreatorProfile::query()
                ->where('user_id', $application->user_id)
                ->lockForUpdate()
                ->first();

            if ($profile) {
                // Re-activating existing creator: keep stable creator_code
                $profile->status = 'active';
                $profile->approved_at = now();
                $profile->pen_name = $application->pen_name;
                $profile->pen_name_canonical = $application->pen_name_canonical;
                $profile->bio = $application->bio;
                $profile->can_reapply = false;
                $profile->save();
            } else {
                // First-time creator: generate sequential CR-xxxxxx code
                $creatorCode = $this->generateSequenceCode('creator_profile', 'CR');

                $profile = CreatorProfile::create([
                    'user_id' => $application->user_id,
                    'creator_code' => $creatorCode,
                    'pen_name' => $application->pen_name,
                    'pen_name_canonical' => $application->pen_name_canonical,
                    'bio' => $application->bio,
                    'status' => 'active',
                    'approved_at' => now(),
                    'can_reapply' => false,
                ]);
            }

            // Upgrade reservation to permanent creator type
            PenNameReservation::query()->updateOrCreate(
                ['pen_name_canonical' => $application->pen_name_canonical],
                [
                    'pen_name' => $application->pen_name,
                    'reserved_by_user_id' => $application->user_id,
                    'reservation_type' => 'creator',
                    'application_id' => $application->id,
                ]
            );

            // Create immutable snapshot history for approval
            CreatorApplicationHistory::create([
                'application_id' => $application->id,
                'version' => $application->version,
                'pen_name' => $application->pen_name,
                'bio' => $application->bio,
                'reason' => $application->reason,
                'sample_works' => $application->sample_works,
                'status' => 'approved',
                'action_type' => 'approved',
                'action_by' => $admin->id,
                'action_at' => now(),
                'snapshot_data' => [
                    'application_id' => $application->id,
                    'application_code' => $application->application_code,
                    'version' => $application->version,
                    'pen_name' => $application->pen_name,
                    'creator_code' => $profile->creator_code,
                    'status' => 'approved',
                    'action_type' => 'approved',
                    'action_by' => [
                        'id' => $admin->id,
                        'name' => $admin->name,
                    ],
                    'action_at' => now()->toIso8601String(),
                ],
                'created_at' => now(),
            ]);

            // Create audit log event
            CreatorAuditLog::create([
                'user_id' => $application->user_id,
                'actor_id' => $admin->id,
                'application_id' => $application->id,
                'event_type' => 'approved',
                'old_values' => ['role' => 'reader', 'status' => 'pending'],
                'new_values' => ['role' => 'creator', 'status' => 'active', 'creator_code' => $profile->creator_code],
                'reason' => 'Admin phê duyệt hồ sơ đăng ký Creator',
                'created_at' => now(),
            ]);

            return $application;
        });

        // Trigger approval notification after transaction commits
        try {
            app(NotificationService::class)->notifyCreatorApplicationApproved($application, $admin);
        } catch (\Throwable $e) {
            report($e);
        }

        return $application;
    }

    /**
     * Reject a creator application with mandatory reason.
     * Records application history snapshot, releases application pen name reservation, and writes audit log.
     */
    public function rejectApplication(User $admin, int $applicationId, string $reason): CreatorApplication
    {
        $application = DB::transaction(function () use ($admin, $applicationId, $reason) {
            $reason = trim($reason);
            if (empty($reason)) {
                throw new CreatorManagementException('Lý do từ chối là bắt buộc.', 422, 'REASON_REQUIRED');
            }

            $application = CreatorApplication::query()
                ->where('id', $applicationId)
                ->lockForUpdate()
                ->first();

            if (! $application) {
                throw new CreatorManagementException('Hồ sơ không tồn tại.', 404, 'APPLICATION_NOT_FOUND');
            }

            if ($application->status !== 'pending') {
                throw new CreatorManagementException('Chỉ có thể từ chối hồ sơ đang chờ duyệt.', 422, 'CANNOT_REJECT_NON_PENDING');
            }

            $application->status = 'rejected';
            $application->reviewed_by = $admin->id;
            $application->reviewed_at = now();
            $application->rejection_reason = $reason;
            $application->save();

            // Release application reservation (only if it is of type 'application')
            PenNameReservation::query()
                ->where('application_id', $application->id)
                ->where('reservation_type', 'application')
                ->delete();

            // Record immutable history snapshot for rejection
            CreatorApplicationHistory::create([
                'application_id' => $application->id,
                'version' => $application->version,
                'pen_name' => $application->pen_name,
                'bio' => $application->bio,
                'reason' => $application->reason,
                'sample_works' => $application->sample_works,
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'action_type' => 'rejected',
                'action_by' => $admin->id,
                'action_at' => now(),
                'snapshot_data' => [
                    'application_id' => $application->id,
                    'application_code' => $application->application_code,
                    'version' => $application->version,
                    'pen_name' => $application->pen_name,
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                    'action_type' => 'rejected',
                    'action_by' => [
                        'id' => $admin->id,
                        'name' => $admin->name,
                    ],
                    'action_at' => now()->toIso8601String(),
                ],
                'created_at' => now(),
            ]);

            // Determine if this is a reapplication rejection or regular application rejection
            $profile = CreatorProfile::query()->where('user_id', $application->user_id)->first();
            $isReapplyRejection = $profile && $profile->reapplied_application_id == $application->id;
            $eventType = $isReapplyRejection ? 'reapply_rejected' : 'application_rejected';

            CreatorAuditLog::create([
                'user_id' => $application->user_id,
                'actor_id' => $admin->id,
                'application_id' => $application->id,
                'event_type' => $eventType,
                'old_values' => ['status' => 'pending'],
                'new_values' => ['status' => 'rejected'],
                'reason' => $reason,
                'created_at' => now(),
            ]);

            return $application;
        });

        // Trigger rejection notification after transaction commits
        try {
            app(NotificationService::class)->notifyCreatorApplicationRejected($application, $admin, $reason);
        } catch (\Throwable $e) {
            report($e);
        }

        return $application;
    }

    /**
     * Creator updates profile (pen name and/or bio).
     * Decision 1 (Option A): Old pen name is retained permanently as an alias reservation.
     * All comics authored by the creator have author_name automatically synced in the same ACID transaction.
     */
    public function updateCreatorProfile(User $creatorUser, array $data): CreatorProfile
    {
        return DB::transaction(function () use ($creatorUser, $data) {
            $profile = CreatorProfile::query()
                ->where('user_id', $creatorUser->id)
                ->lockForUpdate()
                ->first();

            if (! $profile || $profile->status !== 'active') {
                throw new CreatorManagementException('Chỉ Creator đang hoạt động mới có thể cập nhật hồ sơ.', 403, 'NOT_ACTIVE_CREATOR');
            }

            $rawNewPenName = $data['pen_name'] ?? $profile->pen_name;
            if (! PenNameHelper::isValid($rawNewPenName)) {
                throw new CreatorManagementException('Bút danh không hợp lệ.', 422, 'INVALID_PEN_NAME');
            }

            $normalizedNew = PenNameHelper::normalize($rawNewPenName);
            $canonicalNew = PenNameHelper::canonicalize($normalizedNew);
            $oldPenName = $profile->pen_name;
            $oldCanonical = $profile->pen_name_canonical;

            if ($canonicalNew !== $oldCanonical) {
                // Check reservation for new pen name
                $res = PenNameReservation::query()
                    ->where('pen_name_canonical', $canonicalNew)
                    ->lockForUpdate()
                    ->first();

                if ($res && $res->reserved_by_user_id != $creatorUser->id) {
                    throw new CreatorManagementException('Bút danh này đã được sử dụng bởi tác giả khác.', 422, 'PEN_NAME_TAKEN');
                }

                // 1. Convert old pen name reservation to 'alias_creator' (retained permanently)
                PenNameReservation::query()
                    ->where('pen_name_canonical', $oldCanonical)
                    ->where('reserved_by_user_id', $creatorUser->id)
                    ->update(['reservation_type' => 'alias_creator']);

                // 2. Insert or promote new pen name reservation to 'creator'
                PenNameReservation::query()->updateOrCreate(
                    ['pen_name_canonical' => $canonicalNew],
                    [
                        'pen_name' => $normalizedNew,
                        'reserved_by_user_id' => $creatorUser->id,
                        'reservation_type' => 'creator',
                    ]
                );

                // 3. Sync comics.author_name across all comics owned by this creator
                Comic::query()
                    ->where('user_id', $creatorUser->id)
                    ->update(['author_name' => $normalizedNew]);

                // 4. Record pen name history
                PenNameHistory::create([
                    'user_id' => $creatorUser->id,
                    'old_pen_name' => $oldPenName,
                    'new_pen_name' => $normalizedNew,
                    'created_at' => now(),
                ]);

                // 5. Record audit log
                CreatorAuditLog::create([
                    'user_id' => $creatorUser->id,
                    'actor_id' => $creatorUser->id,
                    'event_type' => 'pen_name_changed',
                    'old_values' => ['pen_name' => $oldPenName],
                    'new_values' => ['pen_name' => $normalizedNew],
                    'reason' => 'Tác giả tự đổi bút danh',
                    'created_at' => now(),
                ]);

                $profile->pen_name = $normalizedNew;
                $profile->pen_name_canonical = $canonicalNew;
            }

            if (array_key_exists('bio', $data)) {
                $profile->bio = trim($data['bio'] ?? '');
            }

            $profile->save();

            return $profile;
        });
    }

    /**
     * Admin revokes Creator privileges.
     * Role becomes 'reader', creator profile status becomes 'revoked', pen name reservation remains retained.
     */
    public function revokeCreator(User $admin, int $targetUserId, string $reason): CreatorProfile
    {
        return DB::transaction(function () use ($admin, $targetUserId, $reason) {
            $reason = trim($reason);
            if (empty($reason)) {
                throw new CreatorManagementException('Lý do thu hồi quyền là bắt buộc.', 422, 'REASON_REQUIRED');
            }

            $profile = CreatorProfile::query()
                ->where('user_id', $targetUserId)
                ->lockForUpdate()
                ->first();

            if (! $profile || $profile->status !== 'active') {
                throw new CreatorManagementException('Creator không tồn tại hoặc đã bị thu hồi trước đó.', 422, 'CANNOT_REVOKE');
            }

            $profile->status = 'revoked';
            $profile->revoked_at = now();
            $profile->can_reapply = false;
            $profile->save();

            // Demote user role to reader
            User::query()->where('id', $targetUserId)->update(['role' => 'reader']);

            // Record audit log
            CreatorAuditLog::create([
                'user_id' => $targetUserId,
                'actor_id' => $admin->id,
                'event_type' => 'revoked',
                'old_values' => ['role' => 'creator', 'status' => 'active'],
                'new_values' => ['role' => 'reader', 'status' => 'revoked'],
                'reason' => $reason,
                'created_at' => now(),
            ]);

            return $profile;
        });
    }

    /**
     * Admin grants 1-time reapply privilege to a revoked Creator.
     */
    public function grantReapply(User $admin, int $targetUserId, string $reason): CreatorProfile
    {
        return DB::transaction(function () use ($admin, $targetUserId, $reason) {
            $reason = trim($reason);
            if (empty($reason)) {
                throw new CreatorManagementException('Lý do cấp phép đăng ký lại là bắt buộc.', 422, 'REASON_REQUIRED');
            }

            $profile = CreatorProfile::query()
                ->where('user_id', $targetUserId)
                ->lockForUpdate()
                ->first();

            if (! $profile || $profile->status !== 'revoked') {
                throw new CreatorManagementException('Chỉ có thể cấp phép đăng ký lại cho Creator đã bị thu hồi.', 422, 'CANNOT_GRANT_REAPPLY');
            }

            $profile->can_reapply = true;
            $profile->reapply_granted_at = now();
            $profile->reapply_granted_by = $admin->id;
            $profile->reapply_grant_reason = $reason;
            $profile->reapplied_at = null;
            $profile->reapplied_application_id = null;
            $profile->save();

            CreatorAuditLog::create([
                'user_id' => $targetUserId,
                'actor_id' => $admin->id,
                'event_type' => 'reapply_granted',
                'old_values' => ['can_reapply' => false],
                'new_values' => ['can_reapply' => true],
                'reason' => $reason,
                'created_at' => now(),
            ]);

            return $profile;
        });
    }

    /**
     * Admin revokes 1-time reapply privilege before the Creator submits an application.
     * Decision 2 (Option A): Not allowed if creator already submitted a pending re-application.
     */
    public function revokeReapply(User $admin, int $targetUserId, string $reason): CreatorProfile
    {
        return DB::transaction(function () use ($admin, $targetUserId, $reason) {
            $reason = trim($reason);
            if (empty($reason)) {
                throw new CreatorManagementException('Lý do thu hồi quyền đăng ký lại là bắt buộc.', 422, 'REASON_REQUIRED');
            }

            $profile = CreatorProfile::query()
                ->where('user_id', $targetUserId)
                ->lockForUpdate()
                ->first();

            if (! $profile || $profile->status !== 'revoked') {
                throw new CreatorManagementException('Creator không ở trạng thái bị thu hồi.', 422, 'CANNOT_REVOKE_REAPPLY');
            }

            // Cannot revoke if creator has already submitted a pending application
            if ($profile->reapplied_application_id) {
                $hasPending = CreatorApplication::query()
                    ->where('id', $profile->reapplied_application_id)
                    ->where('status', 'pending')
                    ->exists();

                if ($hasPending) {
                    throw new CreatorManagementException(
                        'Không thể hủy quyền khi người dùng đã nộp hồ sơ đăng ký lại đang chờ duyệt.',
                        422,
                        'APPLICATION_ALREADY_SUBMITTED'
                    );
                }
            }

            if (! $profile->can_reapply) {
                throw new CreatorManagementException('Người dùng hiện không có quyền đăng ký lại để thu hồi.', 422, 'NO_REAPPLY_GRANT_TO_REVOKE');
            }

            $profile->can_reapply = false;
            $profile->save();

            CreatorAuditLog::create([
                'user_id' => $targetUserId,
                'actor_id' => $admin->id,
                'event_type' => 'reapply_revoked',
                'old_values' => ['can_reapply' => true],
                'new_values' => ['can_reapply' => false],
                'reason' => $reason,
                'created_at' => now(),
            ]);

            return $profile;
        });
    }
}
