<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

class ReportPolicy
{
    /**
     * Determine whether the user can view any reports.
     * Admin only.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can view the specific report.
     * Admin only.
     */
    public function view(User $user, Report $report): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can create reports.
     * Allowed for all authenticated active users (reader, creator, admin).
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['reader', 'creator', 'admin'], true);
    }

    /**
     * Determine whether the user can update/resolve the report.
     * Admin only.
     */
    public function update(User $user, Report $report): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can delete the report.
     * Admin only.
     */
    public function delete(User $user, Report $report): bool
    {
        return $user->role === 'admin';
    }
}
