<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsufficientCoinsException extends Exception
{
    protected $message = 'Số dư Coin không đủ để mở khóa chương này. Vui lòng nạp thêm hoặc nhận Coin thử nghiệm!';

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error_code' => 'INSUFFICIENT_COINS',
            'message' => $this->getMessage(),
        ], 422);
    }
}
