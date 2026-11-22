<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiSearchRequest;
use App\Services\Contracts\AiSearchServiceInterface;
use App\Services\GeminiQueryUnderstandingService;
use Illuminate\Http\JsonResponse;

class AiSearchController extends Controller
{
    public function __construct(
        protected AiSearchServiceInterface $searchService,
        protected GeminiQueryUnderstandingService $geminiService
    ) {}

    /**
     * Search comics using semantic search, Gemini query understanding, or safe keyword/tag fallback.
     */
    public function search(AiSearchRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $query = (string) $validated['q'];
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : 15;
        $mode = (string) ($validated['mode'] ?? 'normal');

        // Extract filters excluding 'q', 'limit', and 'mode'
        $filters = array_filter([
            'genre' => $validated['genre'] ?? null,
            'tag' => $validated['tag'] ?? null,
            'age_rating' => $validated['age_rating'] ?? null,
            'status' => $validated['status'] ?? null,
        ], fn ($val) => ! is_null($val) && $val !== '');

        $results = [];
        $isFallback = false;
        $aiUsed = false;
        $fallbackNotice = null;

        if ($mode === 'ai') {
            // Attempt Gemini query understanding
            $clues = $this->geminiService->understand($query);

            if ($clues !== null) {
                // AI successfully extracted structured clues
                $results = $this->searchService->searchWithClues($clues, $filters, $limit);
                $aiUsed = true;
                $isFallback = false;
            } else {
                // Gemini failed, timed out, or unconfigured -> Graceful fallback to normal search
                $results = $this->searchService->search($query, $filters, $limit);
                $aiUsed = false;
                $isFallback = true;
                $fallbackNotice = 'Dịch vụ AI phân tích truy vấn tạm thời gián đoạn hoặc quá thời gian chờ; đã tự động chuyển sang tìm kiếm từ khóa thông thường.';
            }
        } else {
            // Mode is normal: use existing search flow without invoking Gemini
            $results = $this->searchService->search($query, $filters, $limit);
            $aiUsed = false;
            $isFallback = true;
        }

        return response()->json([
            'data' => $results,
            'meta' => [
                'total' => count($results),
                'query' => $query,
                'limit' => $limit,
                'mode' => $mode,
                'is_fallback' => $isFallback,
                'ai_used' => $aiUsed,
                'fallback_notice' => $fallbackNotice,
            ],
        ]);
    }
}
