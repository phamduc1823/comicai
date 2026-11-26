<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminRefundRequest;
use App\Models\CoinTransaction;
use App\Models\ComicPurchase;
use App\Models\Wallet;
use App\Services\Contracts\WalletServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWalletLedgerController extends Controller
{
    public function __construct(
        protected WalletServiceInterface $walletService
    ) {}

    /**
     * List user wallets with balances and user info.
     */
    public function wallets(Request $request): JsonResponse
    {
        $query = Wallet::query()->with('user:id,name,email,role,is_active');

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->whereHas('user', function ($u) use ($q) {
                $u->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
                if (is_numeric($q)) {
                    $u->orWhere('id', (int) $q);
                }
            });
        }

        $sortBy = $request->input('sort_by', 'balance');
        $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, ['id', 'balance', 'created_at', 'updated_at'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('balance', 'desc');
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * List immutable coin transactions ledger.
     */
    public function transactions(Request $request): JsonResponse
    {
        $query = CoinTransaction::query()->with([
            'user:id,name,email',
            'wallet:id,user_id,balance',
        ]);

        // Filter by user_id
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        // Filter by type
        if ($request->filled('type') && $request->input('type') !== 'all') {
            $query->where('type', $request->input('type'));
        }

        // Search by user or transaction code
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('description', 'like', "%{$q}%");
                if (is_numeric($q)) {
                    $sub->orWhere('id', (int) $q)
                        ->orWhere('user_id', (int) $q);
                }
                $sub->orWhereHas('user', function ($u) use ($q) {
                    $u->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                });
            });
        }

        $query->orderBy('id', 'desc');

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * List all comic VIP purchases.
     */
    public function purchases(Request $request): JsonResponse
    {
        $query = ComicPurchase::query()->with([
            'user:id,name,email',
            'comic:id,title,slug,cover_path,price',
            'transaction:id,amount,created_at',
            'refundTransaction:id,amount,created_at',
        ]);

        // Filter status
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        // Filter user_id
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        // Filter comic_id
        if ($request->filled('comic_id')) {
            $query->where('comic_id', (int) $request->input('comic_id'));
        }

        // Search
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($sub) use ($q) {
                $sub->whereHas('comic', function ($c) use ($q) {
                    $c->where('title', 'like', "%{$q}%");
                })->orWhereHas('user', function ($u) use ($q) {
                    $u->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                });
            });
        }

        $query->orderBy('purchased_at', 'desc');

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Process refund & revoke access for a comic purchase or chapter unlock.
     */
    public function refund(AdminRefundRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? ($validated['idempotency_key'] ?? null);

        $result = $this->walletService->refund(
            (int) $validated['transaction_id'],
            $validated['reason'],
            $idempotencyKey,
            $request->user()
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Hoàn tiền cho người dùng thành công và đã thu hồi quyền đọc VIP.',
            'data' => $result,
        ], 200);
    }
}
