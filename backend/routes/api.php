<?php

use App\Http\Controllers\Api\AccountProfileController;
use App\Http\Controllers\Api\AdminBannerController;
use App\Http\Controllers\Api\AdminBroadcastNotificationController;
use App\Http\Controllers\Api\AdminChapterCatalogController;
use App\Http\Controllers\Api\AdminChapterModerationController;
use App\Http\Controllers\Api\AdminComicCatalogController;
use App\Http\Controllers\Api\AdminComicModerationController;
use App\Http\Controllers\Api\AdminCreatorApplicationController;
use App\Http\Controllers\Api\AdminCreatorController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminGenreController;
use App\Http\Controllers\Api\AdminTagController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminVipModerationController;
use App\Http\Controllers\Api\AdminWalletLedgerController;
use App\Http\Controllers\Api\AiSearchController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\ChapterController;
use App\Http\Controllers\Api\ChapterUnlockController;
use App\Http\Controllers\Api\ComicController;
use App\Http\Controllers\Api\ComicPurchaseController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CreatorApplicationController;
use App\Http\Controllers\Api\CreatorChapterController;
use App\Http\Controllers\Api\CreatorComicController;
use App\Http\Controllers\Api\CreatorProfileController;
use App\Http\Controllers\Api\GenreController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ReadingHistoryController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\UserLibraryController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\WalletController;
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
Route::get('/tags/suggestions', [TagController::class, 'suggestions'])->middleware('throttle:60,1');
Route::post('/tags', [TagController::class, 'storeCommunityTag'])->middleware(['auth:sanctum', 'active', 'role:creator', 'throttle:30,1']);
Route::get('/banners', [BannerController::class, 'index']);
Route::get('/comics', [ComicController::class, 'index']);
Route::get('/comics/{slug}', [ComicController::class, 'show']);
Route::get('/comics/{slug}/chapters', [ChapterController::class, 'index']);
Route::get('/comics/{slug}/chapters/{chapterNumber}', [ChapterController::class, 'show']);
Route::get('/ai-search', [AiSearchController::class, 'search']);

// Community Public endpoints (no authentication required)
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{id}', [PostController::class, 'show']);
Route::get('/comments', [CommentController::class, 'index']);
Route::get('/posts/{id}/comments', [CommentController::class, 'indexForPost']);
Route::get('/comics/{id}/comments', [CommentController::class, 'indexForComic']);
Route::get('/chapters/{id}/comments', [CommentController::class, 'indexForChapter']);

// User Public Profile endpoints (no authentication required)
Route::get('/users/{id}', [UserProfileController::class, 'show']);
Route::get('/users/{id}/comics', [UserProfileController::class, 'comics']);
Route::get('/users/{id}/posts', [UserProfileController::class, 'posts']);

Route::middleware(['guest.api', 'throttle:10,1,register'])->post('/register', [AuthController::class, 'register']);
Route::middleware(['guest.api', 'throttle:5,1,login'])->post('/login', [AuthController::class, 'login']);

// Email Verification (signed verification link clicked from email)
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/email/verification-notification', [AuthController::class, 'sendVerificationNotification'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // User Account Profile & Settings
    Route::get('/user/profile', [AccountProfileController::class, 'show']);
    Route::match(['put', 'patch'], '/user/profile', [AccountProfileController::class, 'update']);
    Route::post('/user/avatar', [AccountProfileController::class, 'uploadAvatar']);
    Route::delete('/user/avatar', [AccountProfileController::class, 'deleteAvatar']);
    Route::put('/user/password', [AccountProfileController::class, 'updatePassword'])->middleware('throttle:10,1');

    // Community Posts & Likes (authenticated)
    Route::post('/posts', [PostController::class, 'store']);
    Route::match(['put', 'patch', 'post'], '/posts/{id}', [PostController::class, 'update']);
    Route::delete('/posts/{id}', [PostController::class, 'destroy']);
    Route::post('/posts/{id}/like', [PostController::class, 'toggleLike']);
    Route::delete('/posts/{id}/like', [PostController::class, 'unlike']);

    // Community Comments & Replies (authenticated)
    Route::post('/comments', [CommentController::class, 'store'])->middleware('throttle:30,1');
    Route::post('/posts/{id}/comments', [CommentController::class, 'storeForPost'])->middleware('throttle:30,1');
    Route::post('/comics/{id}/comments', [CommentController::class, 'storeForComic'])->middleware('throttle:30,1');
    Route::post('/chapters/{id}/comments', [CommentController::class, 'storeForChapter'])->middleware('throttle:30,1');
    Route::post('/comments/{id}/reply', [CommentController::class, 'reply'])->middleware('throttle:30,1');
    Route::delete('/comments/{id}', [CommentController::class, 'destroy']);
    Route::post('/comments/{id}/like', [CommentController::class, 'toggleLike']);
    Route::delete('/comments/{id}/like', [CommentController::class, 'unlike']);

    // Community Reports (authenticated: reader, creator, admin)
    Route::post('/reports', [ReportController::class, 'store'])->middleware('throttle:30,1');

    // User Library: Follows & Bookmarks (authenticated)
    Route::post('/comics/{id}/follow', [UserLibraryController::class, 'toggleFollow']);
    Route::get('/user/follows', [UserLibraryController::class, 'follows']);
    Route::post('/comics/{id}/bookmark', [UserLibraryController::class, 'toggleBookmark']);
    Route::get('/user/bookmarks', [UserLibraryController::class, 'bookmarks']);

    // User Reading History (Package 9.6.2 & 9.6.5.2)
    Route::get('/comics/{comicId}/reading-history', [ReadingHistoryController::class, 'showByComic']);
    Route::post('/comics/{comicId}/reading-history', [ReadingHistoryController::class, 'record'])->middleware('throttle:60,1');
    Route::delete('/comics/{comicId}/reading-history', [ReadingHistoryController::class, 'destroy']);
    Route::get('/user/reading-history', [ReadingHistoryController::class, 'index']);
    Route::delete('/user/reading-history', [ReadingHistoryController::class, 'clear']);

    // Realtime Chat APIs (Package 9.7.3A Contract)
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::post('/conversations', [ConversationController::class, 'store'])->name('conversations.store')->middleware('throttle:30,1');
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index'])->name('messages.index');
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])->name('messages.store')->middleware('throttle:60,1');
        Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markAsRead'])->name('conversations.read')->middleware('throttle:60,1');
        Route::get('/unread-count', [ConversationController::class, 'unreadCount'])->name('unread-count');
        Route::get('/direct/{userId}', [ConversationController::class, 'findDirect'])->name('direct.find');
        Route::post('/direct/{userId}/messages', [MessageController::class, 'sendDirect'])->name('direct.messages')->middleware('throttle:60,1');
    });

    // Notification System APIs (CAI-NOTIF-01)
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::match(['post', 'patch'], '/read-all', [NotificationController::class, 'markAllAsRead'])->name('read-all')->middleware('throttle:60,1');
        Route::match(['post', 'patch'], '/{id}/read', [NotificationController::class, 'markAsRead'])->name('read')->middleware('throttle:60,1');
    });

    // Coin Wallet & VIP Comic Purchase (Phase 5)
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
    Route::post('/wallet/faucet', [WalletController::class, 'faucet'])->middleware('throttle:60,1');
    Route::post('/comics/{id}/purchase', [ComicPurchaseController::class, 'purchase']);
    Route::get('/comics/{id}/purchase-status', [ComicPurchaseController::class, 'status']);
    Route::post('/chapters/{id}/unlock', [ChapterUnlockController::class, 'unlock']);

    // Creator Application System (Package CAI-CREATOR-01)
    Route::get('/creator-applications/my', [CreatorApplicationController::class, 'myApplication']);
    Route::post('/creator-applications', [CreatorApplicationController::class, 'store'])->middleware('throttle:10,1');
    Route::post('/creator-applications/{id}/cancel', [CreatorApplicationController::class, 'cancel']);
    Route::match(['put', 'patch'], '/creator-applications/{id}/resubmit', [CreatorApplicationController::class, 'resubmit']);
});

// Creator Comic & Chapter Management endpoints (creator and admin)
Route::middleware(['auth:sanctum', 'active', 'role:creator'])->prefix('creator')->group(function () {
    // Community Tags creation/resolution
    Route::post('/tags', [TagController::class, 'storeCommunityTag'])->middleware('throttle:30,1');

    // Creator Profile
    Route::get('/profile', [CreatorProfileController::class, 'show']);
    Route::match(['put', 'patch'], '/profile', [CreatorProfileController::class, 'update']);
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

// Admin Moderation endpoints (strictly admin only)
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/moderation')->group(function () {
    // Comics moderation queue & actions
    Route::get('/comics', [AdminComicModerationController::class, 'index']);
    Route::post('/comics/{id}/approve', [AdminComicModerationController::class, 'approve']);
    Route::post('/comics/{id}/reject', [AdminComicModerationController::class, 'reject']);
    Route::post('/comics/{id}/approve-changes', [AdminComicModerationController::class, 'approveChanges']);
    Route::post('/comics/{id}/reject-changes', [AdminComicModerationController::class, 'rejectChanges']);

    // Chapters moderation queue & actions
    Route::get('/chapters', [AdminChapterModerationController::class, 'index']);
    Route::post('/chapters/{id}/approve', [AdminChapterModerationController::class, 'approve']);
    Route::post('/chapters/{id}/reject', [AdminChapterModerationController::class, 'reject']);
});

// Admin Reports moderation endpoints (strictly admin only)
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/reports')->group(function () {
    Route::get('/', [ReportController::class, 'index']);
    Route::get('/{id}', [ReportController::class, 'show']);
    Route::match(['patch', 'put'], '/{id}', [ReportController::class, 'update']);
});

// Admin VIP Moderation endpoints (strictly admin only)
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/vip')->group(function () {
    Route::post('/refund', [AdminVipModerationController::class, 'refund']);
});

// Admin Banner Management endpoints (strictly admin only)
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/banners')->group(function () {
    Route::get('/', [AdminBannerController::class, 'index']);
    Route::post('/', [AdminBannerController::class, 'store']);
    Route::patch('/reorder', [AdminBannerController::class, 'reorder']);
    Route::match(['put', 'post'], '/{banner}', [AdminBannerController::class, 'update']);
    Route::delete('/{banner}', [AdminBannerController::class, 'destroy']);
    Route::patch('/{banner}/status', [AdminBannerController::class, 'toggleStatus']);
});

// Admin Creator Management endpoints (strictly admin only)
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/creator-applications')->group(function () {
    Route::get('/', [AdminCreatorApplicationController::class, 'index']);
    Route::get('/export', [AdminCreatorApplicationController::class, 'export']);
    Route::get('/{id}', [AdminCreatorApplicationController::class, 'show']);
    Route::post('/{id}/approve', [AdminCreatorApplicationController::class, 'approve']);
    Route::post('/{id}/reject', [AdminCreatorApplicationController::class, 'reject']);
});

Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/creators')->group(function () {
    Route::get('/', [AdminCreatorController::class, 'index']);
    Route::get('/export', [AdminCreatorController::class, 'export']);
    Route::get('/{id}', [AdminCreatorController::class, 'show']);
    Route::post('/{id}/revoke', [AdminCreatorController::class, 'revoke']);
    Route::post('/{id}/grant-reapply', [AdminCreatorController::class, 'grantReapply']);
    Route::post('/{id}/revoke-reapply', [AdminCreatorController::class, 'revokeReapply']);
});

// Admin Dashboard stats
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/dashboard')->group(function () {
    Route::get('/stats', [AdminDashboardController::class, 'stats']);
});

// Admin Users Management
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/users')->group(function () {
    Route::get('/', [AdminUserController::class, 'index']);
    Route::get('/{id}', [AdminUserController::class, 'show']);
    Route::patch('/{id}/toggle-active', [AdminUserController::class, 'toggleActive']);
    Route::patch('/{id}/role', [AdminUserController::class, 'updateRole']);
});

// Admin Genres Management
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/genres')->group(function () {
    Route::get('/', [AdminGenreController::class, 'index']);
    Route::post('/', [AdminGenreController::class, 'store']);
    Route::get('/{id}', [AdminGenreController::class, 'show']);
    Route::match(['put', 'patch'], '/{id}', [AdminGenreController::class, 'update']);
    Route::patch('/{id}/toggle-status', [AdminGenreController::class, 'toggleStatus']);
    Route::delete('/{id}', [AdminGenreController::class, 'destroy']);
});

// Admin Tags Management
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/tags')->group(function () {
    Route::get('/', [AdminTagController::class, 'index']);
    Route::post('/', [AdminTagController::class, 'store']);
    Route::get('/{id}', [AdminTagController::class, 'show']);
    Route::match(['put', 'patch'], '/{id}', [AdminTagController::class, 'update']);
    Route::delete('/{id}', [AdminTagController::class, 'destroy']);
});

// Admin Comics Catalog Management
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/comics')->group(function () {
    Route::get('/', [AdminComicCatalogController::class, 'index']);
    Route::get('/{id}', [AdminComicCatalogController::class, 'show']);
    Route::patch('/{id}/status', [AdminComicCatalogController::class, 'updateStatus']);
    Route::patch('/{id}/featured', [AdminComicCatalogController::class, 'updateFeatured']);
    Route::patch('/{id}/vip', [AdminComicCatalogController::class, 'updateVip']);
    Route::patch('/{id}/approval-status', [AdminComicCatalogController::class, 'updateApprovalStatus']);

    // Contextual Chapter Management under Comic
    Route::get('/{comicId}/chapters', [AdminChapterCatalogController::class, 'indexForComic']);
    Route::get('/{comicId}/chapters/{chapterId}', [AdminChapterCatalogController::class, 'showForComic']);
    Route::patch('/{comicId}/chapters/{chapterId}/status', [AdminChapterCatalogController::class, 'updateStatusForComic']);
});

// Admin Chapters Catalog Management
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/chapters')->group(function () {
    Route::get('/', [AdminChapterCatalogController::class, 'index']);
    Route::get('/{id}', [AdminChapterCatalogController::class, 'show']);
    Route::patch('/{id}/status', [AdminChapterCatalogController::class, 'updateStatus']);
});

// Admin Wallets & Ledger
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/wallets')->group(function () {
    Route::get('/', [AdminWalletLedgerController::class, 'wallets']);
});

Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/coin-transactions')->group(function () {
    Route::get('/', [AdminWalletLedgerController::class, 'transactions']);
});

Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/comic-purchases')->group(function () {
    Route::get('/', [AdminWalletLedgerController::class, 'purchases']);
    Route::post('/refund', [AdminWalletLedgerController::class, 'refund']);
});

// Admin Broadcast Notifications
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin/notifications')->group(function () {
    Route::post('/broadcast', [AdminBroadcastNotificationController::class, 'broadcast']);
    Route::get('/broadcasts', [AdminBroadcastNotificationController::class, 'history']);
});




