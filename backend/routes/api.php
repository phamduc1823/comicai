<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\ChapterController;
use App\Http\Controllers\Api\ComicController;
use App\Http\Controllers\Api\CreatorChapterController;
use App\Http\Controllers\Api\CreatorComicController;
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

// Creator Comic & Chapter Management endpoints (creator and admin)
Route::middleware(['auth:sanctum', 'active', 'role:creator'])->prefix('creator')->group(function () {
    // Comics
    Route::get('/comics', [CreatorComicController::class, 'index']);
    Route::post('/comics', [CreatorComicController::class, 'store']);
    Route::get('/comics/{id}', [CreatorComicController::class, 'show']);
    Route::match(['put', 'patch', 'post'], '/comics/{id}', [CreatorComicController::class, 'update']);
    Route::post('/comics/{id}/submit-review', [CreatorComicController::class, 'submitReview']);
    Route::post('/comics/{id}/cancel-review', [CreatorComicController::class, 'cancelReview']);
    Route::delete('/comics/{id}', [CreatorComicController::class, 'destroy']);

    // Chapters
    Route::get('/comics/{comicId}/chapters', [CreatorChapterController::class, 'indexForComic']);
    Route::post('/comics/{comicId}/chapters', [CreatorChapterController::class, 'store']);
    Route::get('/chapters/{id}', [CreatorChapterController::class, 'show']);
    Route::match(['put', 'patch'], '/chapters/{id}', [CreatorChapterController::class, 'update']);
    Route::post('/chapters/{id}/submit-review', [CreatorChapterController::class, 'submitReview']);
    Route::post('/chapters/{id}/cancel-review', [CreatorChapterController::class, 'cancelReview']);
    Route::post('/chapters/{id}/recall', [CreatorChapterController::class, 'recall']);

    // Chapter Images
    Route::post('/chapters/{id}/images', [CreatorChapterController::class, 'uploadImages']);
    Route::match(['put', 'patch'], '/chapters/{id}/images/reorder', [CreatorChapterController::class, 'reorderImages']);
    Route::delete('/chapters/{id}/images/{imageId}', [CreatorChapterController::class, 'deleteImage']);
});
