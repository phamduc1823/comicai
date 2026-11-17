<?php

namespace App\Models;

use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;

class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'reports';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * The attributes that are mass assignable.
     * Note: 'status', 'resolved_by', 'resolved_at', and 'admin_notes' are typically
     * modified through Admin resolution workflows.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reporter_id',
        'reportable_type',
        'reportable_id',
        'reason',
        'description',
        'status',
        'resolved_by',
        'resolved_at',
        'admin_notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * The user who submitted the report.
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * The administrator who resolved or dismissed the report.
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Polymorphic target being reported (Post, Comment, Comic, Chapter).
     */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope query to pending reports waiting for moderation.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope query to resolved reports.
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', 'resolved');
    }

    /**
     * Scope query to dismissed reports.
     */
    public function scopeDismissed(Builder $query): Builder
    {
        return $query->where('status', 'dismissed');
    }

    /**
     * Bootstrap the model and its events.
     * Enforces that a reporter can only have one active pending report per target.
     */
    protected static function booted(): void
    {
        static::creating(function (Report $report) {
            if ($report->status === 'pending') {
                $hasPending = static::where('reporter_id', $report->reporter_id)
                    ->where('reportable_type', $report->reportable_type)
                    ->where('reportable_id', $report->reportable_id)
                    ->where('status', 'pending')
                    ->exists();

                if ($hasPending) {
                    throw new InvalidArgumentException('Bạn đã có một báo cáo đang chờ xử lý cho nội dung này.');
                }
            }
        });
    }
}
