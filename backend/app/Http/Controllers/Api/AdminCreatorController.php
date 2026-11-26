<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ExcelExportHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\GrantReapplyRequest;
use App\Http\Requests\RevokeCreatorRequest;
use App\Http\Requests\RevokeReapplyRequest;
use App\Http\Resources\CreatorAuditLogResource;
use App\Http\Resources\CreatorProfileResource;
use App\Models\Comic;
use App\Models\CreatorAuditLog;
use App\Models\CreatorProfile;
use App\Models\PenNameHistory;
use App\Services\CreatorManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminCreatorController extends Controller
{
    public function __construct(
        protected CreatorManagementService $service
    ) {}

    /**
     * Build the filtered query based on request parameters.
     */
    protected function buildFilteredQuery(Request $request)
    {
        $query = CreatorProfile::query()->with('user');

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $search = trim($request->input('q'));
            $query->where(function ($q) use ($search) {
                $q->where('creator_code', 'like', "%{$search}%")
                    ->orWhere('pen_name', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    /**
     * List creators with search, status filters, counts, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $stats = [
            'all' => CreatorProfile::count(),
            'active' => CreatorProfile::where('status', 'active')->count(),
            'revoked' => CreatorProfile::where('status', 'revoked')->count(),
        ];

        $query = $this->buildFilteredQuery($request);

        $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        $perPage = max(1, min(100, (int) $request->input('per_page', 15)));
        $creators = $query->paginate($perPage);

        // Attach comic counts
        foreach ($creators->items() as $creator) {
            $creator->comics_count = Comic::where('user_id', $creator->user_id)->count();
        }

        return response()->json([
            'stats' => $stats,
            'data' => CreatorProfileResource::collection($creators->items()),
            'meta' => [
                'current_page' => $creators->currentPage(),
                'last_page' => $creators->lastPage(),
                'per_page' => $creators->perPage(),
                'total' => $creators->total(),
            ],
        ]);
    }

    /**
     * Show creator details, comic counts, pen name history, and full audit logs timeline.
     */
    public function show(int $id): JsonResponse
    {
        $profile = CreatorProfile::query()
            ->with('user')
            ->findOrFail($id);

        $comicsQuery = Comic::query()->where('user_id', $profile->user_id);
        $profile->comics_count = (clone $comicsQuery)->count();
        $profile->approved_comics_count = (clone $comicsQuery)->where('approval_status', 'approved')->count();
        $profile->pending_comics_count = (clone $comicsQuery)->where('approval_status', 'pending')->count();
        $profile->rejected_comics_count = (clone $comicsQuery)->where('approval_status', 'rejected')->count();

        // Count chapters across creator's comics
        $comicIds = (clone $comicsQuery)->pluck('id');
        $profile->chapters_count = \App\Models\Chapter::whereIn('comic_id', $comicIds)->count();

        // Recent comics
        $comics = (clone $comicsQuery)
            ->select('id', 'title', 'slug', 'approval_status', 'created_at')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();

        // Audit logs timeline
        $auditLogs = CreatorAuditLog::query()
            ->where('user_id', $profile->user_id)
            ->with(['actor', 'application'])
            ->orderBy('id', 'desc')
            ->get();

        // Pen name history
        $penNameHistories = PenNameHistory::query()
            ->where('user_id', $profile->user_id)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'data' => new CreatorProfileResource($profile),
            'comics' => $comics,
            'pen_name_history' => $penNameHistories,
            'audit_logs' => CreatorAuditLogResource::collection($auditLogs),
        ]);
    }

    /**
     * Admin revokes Creator privileges.
     */
    public function revoke(RevokeCreatorRequest $request, int $id): JsonResponse
    {
        $profile = CreatorProfile::findOrFail($id);
        $updated = $this->service->revokeCreator($request->user(), $profile->user_id, (string) $request->input('reason'));
        $updated->load('user');

        return response()->json([
            'message' => 'Đã thu hồi quyền Creator thành công.',
            'data' => new CreatorProfileResource($updated),
        ]);
    }

    /**
     * Admin grants 1-time reapply privilege to a revoked Creator.
     */
    public function grantReapply(GrantReapplyRequest $request, int $id): JsonResponse
    {
        $profile = CreatorProfile::findOrFail($id);
        $updated = $this->service->grantReapply($request->user(), $profile->user_id, (string) $request->input('reason'));
        $updated->load('user');

        return response()->json([
            'message' => 'Đã cấp phép đăng ký lại cho Creator.',
            'data' => new CreatorProfileResource($updated),
        ]);
    }

    /**
     * Admin revokes 1-time reapply privilege before the user resubmits an application.
     */
    public function revokeReapply(RevokeReapplyRequest $request, int $id): JsonResponse
    {
        $profile = CreatorProfile::findOrFail($id);
        $updated = $this->service->revokeReapply($request->user(), $profile->user_id, (string) $request->input('reason'));
        $updated->load('user');

        return response()->json([
            'message' => 'Đã thu hồi quyền đăng ký lại của Creator.',
            'data' => new CreatorProfileResource($updated),
        ]);
    }

    /**
     * Export creators list to real Excel (.xlsx) file.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->buildFilteredQuery($request)
            ->orderBy('id', 'desc');

        $headers = [
            'Mã Creator',
            'Tên người dùng',
            'Email',
            'Bút danh',
            'Trạng thái',
            'Số truyện',
            'Ngày duyệt',
            'Ngày thu hồi',
            'Được phép nộp lại',
        ];

        $statusLabels = [
            'active' => 'Hoạt động',
            'revoked' => 'Đã thu hồi',
        ];

        $rowsGenerator = function () use ($query, $statusLabels) {
            foreach ($query->cursor() as $creator) {
                $comicCount = Comic::where('user_id', $creator->user_id)->count();

                yield [
                    $creator->creator_code,
                    $creator->user?->name ?? 'N/A',
                    $creator->user?->email ?? 'N/A',
                    $creator->pen_name,
                    $statusLabels[$creator->status] ?? $creator->status,
                    $comicCount,
                    $creator->approved_at?->format('d/m/Y H:i:s') ?? '',
                    $creator->revoked_at?->format('d/m/Y H:i:s') ?? '',
                    $creator->can_reapply ? 'Có' : 'Không',
                ];
            }
        };

        $filename = 'danh_sach_creator_'.now()->format('Ymd_His').'.xlsx';

        return ExcelExportHelper::streamXlsx($headers, $rowsGenerator(), $filename);
    }
}
