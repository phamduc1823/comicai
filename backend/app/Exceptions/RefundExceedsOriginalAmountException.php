<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundExceedsOriginalAmountException extends Exception
{
    protected $message = 'Giao dịch đã được hoàn tiền hoặc số tiền hoàn vượt quá số tiền giao dịch gốc.';

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error_code' => 'REFUND_EXCEEDS_ORIGINAL_AMOUNT',
            'message' => $this->getMessage(),
        ], 422);
    }
}
