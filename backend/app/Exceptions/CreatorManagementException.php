<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreatorManagementException extends Exception
{
    protected int $statusCode;
    protected string $errorCode;

    public function __construct(string $message, int $statusCode = 422, string $errorCode = 'CREATOR_MANAGEMENT_ERROR')
    {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->statusCode);
    }
}
