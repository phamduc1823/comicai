<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportRequest;
use App\Http\Requests\UpdateReportRequest;
use App\Http\Resources\ReportResource;
use App\Models\Chapter;
use App\Models\Comic;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReportController extends Controller
{
    use AuthorizesRequests;

    /**
     * Submit a new violation report.
     * Accessible by Reader, Creator, Admin.
     */
    public function store(StoreReportRequest $request): JsonResponse
    {
        $this->authorize('create', Report::class);

        $validated = $request->validated();
        $user = $request->user();

        // 1. Verify target entity exists and is accessible
        $target = $this->resolveReportableTarget($validated['reportable_type'], $validated['reportable_id'], $user);
        if (! $target) {
            return response()->json([
                'status' => 'error',
                'message' => 'Đối tượng được báo cáo không tồn tại hoặc đã bị xóa.',
            ], 404);
        }

        // 2. Prevent self-reporting
        if ($validated['reportable_type'] === 'user' && (int) $validated['reportable_id'] === (int) $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bạn không thể tự báo cáo chính mình.',
            ], 422);
        }

        // 3. Atomically check pending duplicates and create report
        $report = null;
        $isDuplicate = false;

        try {
            $report = DB::transaction(function () use ($user, $validated, &$isDuplicate) {
                $hasPending = Report::where('reporter_id', $user->id)
                    ->where('reportable_type', $validated['reportable_type'])
                    ->where('reportable_id', $validated['reportable_id'])
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->exists();

                if ($hasPending) {
                    $isDuplicate = true;
                    return null;
                }

                return Report::create([
                    'reporter_id' => $user->id,
                    'reportable_type' => $validated['reportable_type'],
                    'reportable_id' => (int) $validated['reportable_id'],
                    'reason' => $validated['reason'],
                    'description' => $validated['description'] ?? null,
                    'status' => 'pending',
                    'resolved_by' => null,
                    'resolved_at' => null,
                    'admin_notes' => null,
                ]);
            });
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        if ($isDuplicate) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bạn đã có một báo cáo đang chờ xử lý cho nội dung này.',
            ], 422);
        }

        $report->load([
            'reporter:id,name,avatar_path,role',
            'reportable',
        ]);

        return (new ReportResource($report))
            ->additional([
                'status' => 'success',
                'message' => 'Gửi báo cáo vi phạm thành công.',
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display a paginated listing of reports for Admin moderation.
     * Strictly Admin only.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Report::class);

        $query = Report::query();

        // Filter by status
        if ($request->filled('status')) {
            $status = $request->input('status');
            if (in_array($status, ['pending', 'resolved', 'dismissed'], true)) {
                $query->where('status', $status);
            }
        }

        // Filter by reason
        if ($request->filled('reason')) {
            $query->where('reason', $request->input('reason'));
        }

        // Filter by target type
        if ($request->filled('reportable_type')) {
            $type = $request->input('reportable_type');
            if (in_array($type, ['post', 'comment', 'comic', 'chapter', 'user'], true)) {
                $query->where('reportable_type', $type);
            }
        }

        // Eager load relations safely
        $query->with([
            'reporter:id,name,avatar_path,role',
            'resolver:id,name,avatar_path,role',
            'reportable',
        ]);

        // Newest reports first, secondary sort by ID
        $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        $perPage = min(max($request->integer('per_page', 15), 1), 50);
        $reports = $query->paginate($perPage);

        return ReportResource::collection($reports);
    }

    /**
     * Display a specific report.
     * Strictly Admin only.
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        $report = Report::with([
            'reporter:id,name,avatar_path,role',
            'resolver:id,name,avatar_path,role',
            'reportable',
        ])->find($id);

        if (! $report) {
            return response()->json([
                'status' => 'error',
                'message' => 'Báo cáo không tồn tại hoặc đã bị xóa.',
            ], 404);
        }

        $this->authorize('view', $report);

        return response()->json([
            'status' => 'success',
            'data' => new ReportResource($report),
        ], 200);
    }

    /**
     * Update/resolve a report.
     * Transitions status from 'pending' to 'resolved' or 'dismissed'.
     * Strictly Admin only.
     */
    public function update(UpdateReportRequest $request, int|string $id): JsonResponse
    {
        $report = Report::find($id);

        if (! $report) {
            return response()->json([
                'status' => 'error',
                'message' => 'Báo cáo không tồn tại hoặc đã bị xóa.',
            ], 404);
        }

        $this->authorize('update', $report);

        $validated = $request->validated();
        $user = $request->user();

        $statusError = null;
        $updatedReport = null;

        DB::transaction(function () use ($id, $user, $validated, &$statusError, &$updatedReport) {
            $locked = Report::where('id', $id)->lockForUpdate()->first();

            if (! $locked) {
                $statusError = [
                    'code' => 404,
                    'message' => 'Báo cáo không tồn tại hoặc đã bị xóa.',
                ];
                return;
            }

            if ($locked->status !== 'pending') {
                $statusError = [
                    'code' => 422,
                    'message' => 'Báo cáo này đã được xử lý trước đó và không thể thay đổi trạng thái.',
                ];
                return;
            }

            $locked->status = $validated['status'];
            $locked->resolved_by = $user->id;
            $locked->resolved_at = now();
            $locked->admin_notes = $validated['admin_notes'] ?? null;
            $locked->save();

            $updatedReport = $locked;
        });

        if ($statusError) {
            return response()->json([
                'status' => 'error',
                'message' => $statusError['message'],
            ], $statusError['code']);
        }

        $updatedReport->load([
            'reporter:id,name,avatar_path,role',
            'resolver:id,name,avatar_path,role',
            'reportable',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => $updatedReport->status === 'resolved'
                ? 'Báo cáo vi phạm đã được giải quyết (resolved).'
                : 'Báo cáo vi phạm đã bị bác bỏ (dismissed).',
            'data' => new ReportResource($updatedReport),
        ], 200);
    }

    /**
     * Resolve target entity from morphMap alias and ID with authorization checks.
     * Readers and Creators can only report publicly published content.
     * Administrators can report content in any status for moderation.
     */
    private function resolveReportableTarget(string $type, int|string $id, ?User $user = null): ?Model
    {
        $isAdmin = $user && $user->role === 'admin';

        return match ($type) {
            'post' => Post::find($id),
            'comment' => Comment::find($id),
            'comic' => $isAdmin
                ? Comic::find($id)
                : Comic::where('id', $id)->where('approval_status', 'approved')->first(),
            'chapter' => $isAdmin
                ? Chapter::find($id)
                : Chapter::where('id', $id)
                    ->where('status', 'published')
                    ->whereHas('comic', fn ($q) => $q->where('approval_status', 'approved'))
                    ->first(),
            'user' => User::find($id),
            default => null,
        };
    }
}
