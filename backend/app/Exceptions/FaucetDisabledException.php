<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FaucetDisabledException extends Exception
{
    protected $message = 'Tính năng nhận Coin thử nghiệm đang tạm thời bị vô hiệu hóa.';

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error_code' => 'FAUCET_DISABLED',
            'message' => $this->getMessage(),
        ], 403);
    }
}
