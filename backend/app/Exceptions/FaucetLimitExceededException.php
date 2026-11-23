<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FaucetLimitExceededException extends Exception
{
    protected $message = 'Bạn đã nhận Coin thử nghiệm tối đa 5 lần trong ngày. Vui lòng quay lại vào ngày mai!';

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error_code' => 'FAUCET_LIMIT_EXCEEDED',
            'message' => $this->getMessage(),
        ], 422);
    }
}
