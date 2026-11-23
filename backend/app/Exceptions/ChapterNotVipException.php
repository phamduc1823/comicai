<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChapterNotVipException extends Exception
{
    protected $message = 'Chương này là chương miễn phí, không thể mở khóa bằng Coin.';

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error_code' => 'CHAPTER_NOT_VIP',
            'message' => $this->getMessage(),
        ], 422);
    }
}
