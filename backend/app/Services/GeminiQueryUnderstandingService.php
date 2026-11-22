<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeminiQueryUnderstandingService
{
    protected ?string $apiKey;
    protected string $model;
    protected int $timeout;
    protected string $baseUrl;

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?int $timeout = null,
        ?string $baseUrl = null
    ) {
        $this->apiKey = $apiKey ?? config('ai.gemini.api_key');
        $this->model = $model ?? config('ai.gemini.model', 'gemini-2.5-flash');
        $this->timeout = $timeout ?? (int) config('ai.gemini.timeout', 8);
        $this->baseUrl = rtrim($baseUrl ?? config('ai.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
    }

    /**
     * Check if Gemini API credentials and model are configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Analyze a natural language Vietnamese query using Gemini to extract structured clues.
     *
     * @param string $query Natural language search query
     * @return array<string, mixed>|null Structured clues or null if failed/timeout/unconfigured
     */
    public function understand(string $query): ?array
    {
        $normalizedQuery = trim($query);

        if (mb_strlen($normalizedQuery) < 2) {
            return null;
        }

        if (! $this->isConfigured()) {
            Log::info('Gemini query understanding skipped: API key is not configured.');
            return null;
        }

        try {
            $prompt = $this->buildPrompt($normalizedQuery);
            $url = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";

            // Enforce real network socket timeout (max 8 seconds per CAI-002F)
            $effectiveTimeout = max(1, min(8, $this->timeout));

            $response = Http::timeout($effectiveTimeout)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.1,
                        'response_mime_type' => 'application/json',
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Gemini API query understanding returned error status', [
                    'status' => $response->status(),
                    'query_length' => mb_strlen($normalizedQuery),
                ]);
                return null;
            }

            $rawText = $response->json('candidates.0.content.parts.0.text');

            if (! is_string($rawText) || empty(trim($rawText))) {
                Log::warning('Gemini API returned empty text part');
                return null;
            }

            return $this->parseAndNormalizeResponse($rawText);

        } catch (Throwable $e) {
            // Sanitize any possible API key occurrence in URLs or exception messages
            $sanitizedMessage = preg_replace('/key=[a-zA-Z0-9_\-]+/', 'key=[REDACTED]', $e->getMessage());

            Log::warning('Gemini query understanding exception occurred', [
                'error_type' => get_class($e),
                'error_message' => $sanitizedMessage,
                'query_length' => mb_strlen($normalizedQuery),
            ]);

            return null;
        }
    }

    /**
     * Build system and instruction prompt for Gemini query extraction.
     */
    protected function buildPrompt(string $query): string
    {
        return <<<PROMPT
Bạn là trợ lý AI chuyên phân tích trí nhớ và câu hỏi của độc giả để tìm truyện tranh (Manga, Manhwa, Manhua, Webtoon, Comic).
Người dùng nhớ mang máng một chi tiết hoặc tình huống truyện bằng tiếng Việt:
"{$query}"

Nhiệm vụ của bạn là trích xuất các manh mối tìm kiếm thành một JSON duy nhất có cấu trúc:
{
  "series": "Tên bộ truyện hoặc tác phẩm được người dùng nhắc tới hoặc suy đoán (string hoặc null)",
  "characters": ["Danh sách tên các nhân vật được nhắc tới"],
  "remembered_events": ["Danh sách các sự kiện, tình huống, hành động cốt truyện được nhớ lại"],
  "opponent_description": "Mô tả đối thủ, quái vật, phản diện nếu có (string hoặc null)",
  "visual_clues": ["Danh sách các chi tiết nhận dạng thị giác như màu sắc, trang phục, vũ khí"],
  "time_reference": "Mốc thời gian nếu có nhắc tới (string hoặc null)",
  "keywords": ["Danh sách các từ khóa tiếng Việt then chốt nhất để tìm kiếm trong CSDL truyện"],
  "search_intent": "comic_or_chapter",
  "uncertainty_notes": ["Những điểm mơ hồ người dùng chưa chắc chắn"]
}

QUY TẮC BẮT BUỘC:
1. Chỉ trả về một đối tượng JSON hợp lệ, không bọc markdown ```json, không thêm bất kỳ văn bản nào khác.
2. Nếu không có thông tin của trường nào, hãy để null hoặc []. Tuyệt đối không tự bịa đặt thông tin.
PROMPT;
    }

    /**
     * Parse, validate, and normalize the raw JSON response from Gemini.
     *
     * @param string $rawJson
     * @return array<string, mixed>|null
     */
    protected function parseAndNormalizeResponse(string $rawJson): ?array
    {
        // Clean potential markdown code blocks if provider added them
        $cleaned = trim(preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', trim($rawJson)));

        $data = json_decode($cleaned, true);

        if (! is_array($data)) {
            Log::warning('Failed to decode Gemini response as valid JSON');
            return null;
        }

        // Normalize each field to expected types
        $series = isset($data['series']) && is_string($data['series']) ? trim($data['series']) : null;
        if ($series === '') {
            $series = null;
        }

        $characters = $this->normalizeStringArray($data['characters'] ?? []);
        $rememberedEvents = $this->normalizeStringArray($data['remembered_events'] ?? []);
        $opponentDescription = isset($data['opponent_description']) && is_string($data['opponent_description'])
            ? trim($data['opponent_description'])
            : null;
        if ($opponentDescription === '') {
            $opponentDescription = null;
        }

        $visualClues = $this->normalizeStringArray($data['visual_clues'] ?? []);
        $timeReference = isset($data['time_reference']) && is_string($data['time_reference'])
            ? trim($data['time_reference'])
            : null;
        if ($timeReference === '') {
            $timeReference = null;
        }

        $keywords = $this->normalizeStringArray($data['keywords'] ?? []);
        $searchIntent = isset($data['search_intent']) && is_string($data['search_intent'])
            ? trim($data['search_intent'])
            : 'comic_or_chapter';
        $uncertaintyNotes = $this->normalizeStringArray($data['uncertainty_notes'] ?? []);

        return [
            'series' => $series,
            'characters' => $characters,
            'remembered_events' => $rememberedEvents,
            'opponent_description' => $opponentDescription,
            'visual_clues' => $visualClues,
            'time_reference' => $timeReference,
            'keywords' => $keywords,
            'search_intent' => $searchIntent,
            'uncertainty_notes' => $uncertaintyNotes,
        ];
    }

    /**
     * Helper to guarantee an array of trimmed non-empty strings.
     *
     * @param mixed $input
     * @return array<int, string>
     */
    protected function normalizeStringArray(mixed $input): array
    {
        if (! is_array($input)) {
            if (is_string($input) && trim($input) !== '') {
                return [trim($input)];
            }
            return [];
        }

        $result = [];
        foreach ($input as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }

        return array_values(array_unique($result));
    }
}
