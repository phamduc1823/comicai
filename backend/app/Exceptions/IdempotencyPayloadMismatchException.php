<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdempotencyPayloadMismatchException extends Exception
{
    protected $message = 'Idempotency key đã được sử dụng với thông tin yêu cầu khác. Vui lòng sử dụng key mới.';

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error_code' => 'IDEMPOTENCY_PAYLOAD_MISMATCH',
            'message' => $this->getMessage(),
        ], 422);
    }
}
