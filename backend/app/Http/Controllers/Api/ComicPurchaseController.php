<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comic;
use App\Services\Contracts\ComicPurchaseServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComicPurchaseController extends Controller
{
    public function __construct(
        protected ComicPurchaseServiceInterface $comicPurchaseService
    ) {}

    /**
     * Purchase a VIP comic using coins.
     */
    public function purchase(Request $request, int|string $id): JsonResponse
    {
        $comic = is_numeric($id) ? Comic::findOrFail($id) : Comic::where('slug', $id)->firstOrFail();
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');

        $result = $this->comicPurchaseService->purchaseComic(
            $request->user(),
            $comic,
            $idempotencyKey,
            $request->except(['idempotency_key'])
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Mua truyện VIP thành công. Bạn có thể đọc toàn bộ các chương của truyện này!',
            'data' => $result,
        ], 200);
    }

    /**
     * Check if authenticated user has purchased this comic.
     */
    public function status(Request $request, int|string $id): JsonResponse
    {
        $comic = is_numeric($id) ? Comic::findOrFail($id) : Comic::where('slug', $id)->firstOrFail();
        $isPurchased = $this->comicPurchaseService->hasPurchasedComic($request->user(), $comic);

        return response()->json([
            'status' => 'success',
            'data' => [
                'comic_id' => $comic->id,
                'is_vip' => (bool) $comic->is_vip,
                'price' => (int) ($comic->price ?? 0),
                'is_purchased' => $isPurchased,
            ],
        ]);
    }
}
