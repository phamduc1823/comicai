<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'BlueToon API is running',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::middleware(['guest.api', 'throttle:10,1,register'])->post('/register', [AuthController::class, 'register']);
Route::middleware(['guest.api', 'throttle:5,1,login'])->post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
