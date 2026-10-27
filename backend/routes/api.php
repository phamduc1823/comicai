<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\ChapterController;
use App\Http\Controllers\Api\ComicController;
use App\Http\Controllers\Api\GenreController;
use App\Http\Controllers\Api\TagController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'BlueToon API is running',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Public Catalog endpoints (no authentication required)
Route::get('/genres', [GenreController::class, 'index']);
Route::get('/tags', [TagController::class, 'index']);
Route::get('/banners', [BannerController::class, 'index']);
Route::get('/comics', [ComicController::class, 'index']);
Route::get('/comics/{slug}', [ComicController::class, 'show']);
Route::get('/comics/{slug}/chapters', [ChapterController::class, 'index']);
Route::get('/comics/{slug}/chapters/{chapterNumber}', [ChapterController::class, 'show']);

Route::middleware(['guest.api', 'throttle:10,1,register'])->post('/register', [AuthController::class, 'register']);
Route::middleware(['guest.api', 'throttle:5,1,login'])->post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
