<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ExcelExportHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejectCreatorApplicationRequest;
use App\Http\Resources\CreatorApplicationResource;
use App\Models\CreatorApplication;
use App\Services\CreatorManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminCreatorApplicationController extends Controller
{
    public function __construct(
        protected CreatorManagementService $service
    ) {}

    /**
     * Build the filtered query based on request parameters.
     */
    protected function buildFilteredQuery(Request $request)
    {
        $query = CreatorApplication::query()->with(['user', 'reviewer']);

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $search = trim($request->input('q'));
            $query->where(function ($q) use ($search) {
                $q->where('application_code', 'like', "%{$search}%")
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
     * List all creator applications with search, status filtering, tab counters, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $stats = [
            'all' => CreatorApplication::count(),
            'pending' => CreatorApplication::where('status', 'pending')->count(),
            'approved' => CreatorApplication::where('status', 'approved')->count(),
            'rejected' => CreatorApplication::where('status', 'rejected')->count(),
            'canceled' => CreatorApplication::where('status', 'canceled')->count(),
        ];

        $query = $this->buildFilteredQuery($request);

        // Default sort: pending applications show oldest first (FIFO review); others newest first
        $status = $request->input('status', 'all');
        if ($status === 'pending') {
            $query->orderBy('created_at', 'asc')->orderBy('id', 'asc');
        } else {
            $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 15)));
        $applications = $query->paginate($perPage);

        return response()->json([
            'stats' => $stats,
            'data' => CreatorApplicationResource::collection($applications->items()),
            'meta' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
            ],
        ]);
    }

    /**
     * View detailed application including complete version histories.
     */
    public function show(int $id): JsonResponse
    {
        $application = CreatorApplication::query()
            ->with([
                'user',
                'reviewer',
                'histories' => fn ($q) => $q->orderBy('version', 'desc')->orderBy('id', 'desc'),
            ])
            ->findOrFail($id);

        return response()->json([
            'data' => new CreatorApplicationResource($application),
        ]);
    }

    /**
     * Approve a pending application.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $application = $this->service->approveApplication($request->user(), $id);
        $application->load(['user', 'reviewer', 'histories']);

        return response()->json([
            'message' => 'Hồ sơ đã được phê duyệt thành công. Người dùng đã được cấp quyền Creator.',
            'data' => new CreatorApplicationResource($application),
        ]);
    }

    /**
     * Reject a pending application with mandatory reason.
     */
    public function reject(RejectCreatorApplicationRequest $request, int $id): JsonResponse
    {
        $application = $this->service->rejectApplication($request->user(), $id, (string) $request->input('reason'));
        $application->load(['user', 'reviewer', 'histories']);

        return response()->json([
            'message' => 'Đã từ chối hồ sơ đăng ký.',
            'data' => new CreatorApplicationResource($application),
        ]);
    }

    /**
     * Export applications to real Excel (.xlsx) file.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->buildFilteredQuery($request)
            ->orderBy('id', 'desc');

        $headers = [
            'Mã hồ sơ',
            'Tên người dùng',
            'Email',
            'Bút danh',
            'Trạng thái',
            'Phiên bản',
            'Lý do từ chối',
            'Người duyệt',
            'Ngày nộp',
            'Ngày duyệt/từ chối',
        ];

        $statusLabels = [
            'pending' => 'Chờ duyệt',
            'approved' => 'Đã duyệt',
            'rejected' => 'Bị từ chối',
            'canceled' => 'Đã hủy',
        ];

        $rowsGenerator = function () use ($query, $statusLabels) {
            foreach ($query->cursor() as $app) {
                yield [
                    $app->application_code,
                    $app->user?->name ?? 'N/A',
                    $app->user?->email ?? 'N/A',
                    $app->pen_name,
                    $statusLabels[$app->status] ?? $app->status,
                    'v'.$app->version,
                    $app->rejection_reason ?? '',
                    $app->reviewer?->name ?? '',
                    $app->created_at?->format('d/m/Y H:i:s') ?? '',
                    $app->reviewed_at?->format('d/m/Y H:i:s') ?? '',
                ];
            }
        };

        $filename = 'danh_sach_ho_so_creator_'.now()->format('Ymd_His').'.xlsx';

        return ExcelExportHelper::streamXlsx($headers, $rowsGenerator(), $filename);
    }
}
