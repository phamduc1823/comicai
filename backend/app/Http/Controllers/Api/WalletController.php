<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Contracts\WalletServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        protected WalletServiceInterface $walletService
    ) {}

    /**
     * Retrieve the authenticated user's coin wallet details.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $wallet = $this->walletService->getOrCreateWallet($user);
            $faucetRemaining = $this->walletService->getRemainingFaucetCount($user);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'user_id' => $user->id,
                    'balance' => (int) $wallet->balance,
                    'currency' => 'COIN',
                    'faucet_available_today' => $faucetRemaining,
                    'updated_at' => $wallet->updated_at?->toISOString(),
                ],
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '42S02' || str_contains($e->getMessage(), "doesn't exist")) {
                report($e);
                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'user_id' => $user->id,
                        'balance' => 0,
                        'currency' => 'COIN',
                        'faucet_available_today' => 0,
                        'updated_at' => null,
                    ],
                ]);
            }
            throw $e;
        }
    }

    /**
     * Retrieve the transaction history ledger for the user.
     */
    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = min(max($request->integer('limit', $request->integer('per_page', 15)), 1), 100);
        $type = $request->query('type');

        $transactions = $this->walletService->getTransactions($user, $limit, $type);

        return response()->json([
            'status' => 'success',
            'data' => $transactions,
        ]);
    }

    /**
     * Claim demo faucet coins (strictly server-enforced amount and daily limit).
     */
    public function faucet(Request $request): JsonResponse
    {
        $user = $request->user();
        $result = $this->walletService->claimFaucet($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Nhận 100 Coin thử nghiệm thành công!',
            'data' => $result,
        ]);
    }
}
