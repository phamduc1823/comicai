<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Comic;
use App\Models\ComicPurchase;
use App\Models\CreatorApplication;
use App\Models\Report;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminDashboardController extends Controller
{
    /**
     * Get aggregate statistics and recent items for Admin Dashboard.
     */
    public function stats(Request $request): JsonResponse
    {
        $now = Carbon::now();
        $sevenDaysAgo = $now->copy()->subDays(7);
        $thirtyDaysAgo = $now->copy()->subDays(30);

        // User metrics
        $totalUsers = User::count();
        $newUsers7Days = User::where('created_at', '>=', $sevenDaysAgo)->count();
        $newUsers30Days = User::where('created_at', '>=', $thirtyDaysAgo)->count();
        $totalCreators = User::where('role', 'creator')->count();
        $activeUsers = User::where('is_active', true)->count();
        $lockedUsers = User::where('is_active', false)->count();

        // Moderation queue & pending items
        $pendingComics = Comic::where('approval_status', 'pending')->count();
        $pendingChapters = Chapter::where('status', 'pending')->count();
        $pendingCreatorApps = CreatorApplication::where('status', 'pending')->count();
        $pendingReports = Report::where('status', 'pending')->count();

        // Content metrics
        $totalComics = Comic::count();
        $approvedComics = Comic::where('approval_status', 'approved')->count();
        $draftComics = Comic::where('approval_status', 'draft')->count();
        $rejectedComics = Comic::where('approval_status', 'rejected')->count();
        $vipComics = Comic::where('is_vip', true)->count();

        $totalChapters = Chapter::count();
        $publishedChapters = Chapter::where('status', 'published')->count();

        // Financial & VIP metrics
        $totalCoinsInCirculation = (int) Wallet::sum('balance');
        $totalVipPurchases = ComicPurchase::count();
        $activeVipPurchases = ComicPurchase::where('status', 'active')->count();
        $revokedVipPurchases = ComicPurchase::where('status', 'revoked')->count();
        $totalVipRevenueCoins = (int) ComicPurchase::where('status', 'active')->sum('price_paid');

        // Recent items
        $recentPendingComics = Comic::with('user:id,name,email')
            ->where('approval_status', 'pending')
            ->latest('updated_at')
            ->limit(5)
            ->get(['id', 'title', 'slug', 'cover_path', 'user_id', 'created_at', 'updated_at']);

        $recentPendingReports = Report::with('reporter:id,name,email')
            ->where('status', 'pending')
            ->latest('created_at')
            ->limit(5)
            ->get();

        $recentPurchases = ComicPurchase::with(['user:id,name,email', 'comic:id,title,slug,price'])
            ->latest('purchased_at')
            ->limit(5)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'users' => [
                    'total' => $totalUsers,
                    'new_7_days' => $newUsers7Days,
                    'new_30_days' => $newUsers30Days,
                    'creators' => $totalCreators,
                    'active' => $activeUsers,
                    'locked' => $lockedUsers,
                ],
                'queue' => [
                    'pending_comics' => $pendingComics,
                    'pending_chapters' => $pendingChapters,
                    'pending_creator_applications' => $pendingCreatorApps,
                    'pending_reports' => $pendingReports,
                    'total_pending' => $pendingComics + $pendingChapters + $pendingCreatorApps + $pendingReports,
                ],
                'catalog' => [
                    'total_comics' => $totalComics,
                    'approved_comics' => $approvedComics,
                    'draft_comics' => $draftComics,
                    'rejected_comics' => $rejectedComics,
                    'vip_comics' => $vipComics,
                    'total_chapters' => $totalChapters,
                    'published_chapters' => $publishedChapters,
                ],
                'finance' => [
                    'coins_in_circulation' => $totalCoinsInCirculation,
                    'total_vip_purchases' => $totalVipPurchases,
                    'active_vip_purchases' => $activeVipPurchases,
                    'revoked_vip_purchases' => $revokedVipPurchases,
                    'vip_revenue_coins' => $totalVipRevenueCoins,
                ],
                'recent' => [
                    'pending_comics' => $recentPendingComics,
                    'pending_reports' => $recentPendingReports,
                    'vip_purchases' => $recentPurchases,
                ],
            ],
        ]);
    }
}
