<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminRefundRequest;
use App\Services\Contracts\WalletServiceInterface;
use Illuminate\Http\JsonResponse;

class AdminVipModerationController extends Controller
{
    public function __construct(
        protected WalletServiceInterface $walletService
    ) {}

    /**
     * Admin manual refund for a chapter unlock transaction with VIP revocation.
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
            'message' => 'Hoàn tiền cho người dùng thành công và đã thu hồi quyền đọc chương VIP.',
            'data' => $result,
        ], 200);
    }
}
