<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChapterReaderResource;
use App\Http\Resources\ChapterSummaryResource;
use App\Models\Chapter;
use App\Models\Comic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChapterController extends Controller
{
    /**
     * Display a listing of published chapters for the specified approved comic.
     */
    public function index(string $slug): JsonResponse|AnonymousResourceCollection
    {
        $comic = Comic::query()
            ->where('slug', $slug)
            ->where('approval_status', 'approved')
            ->first();

        if (! $comic) {
            return response()->json([
                'status' => 'error',
                'message' => 'Truyện không tồn tại hoặc chưa được phát hành công khai.',
            ], 404);
        }

        $chapters = Chapter::query()
            ->where('comic_id', $comic->id)
            ->where('status', 'published')
            ->orderBy('chapter_number', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return ChapterSummaryResource::collection($chapters);
    }

    /**
     * Display the specified published chapter with reading pages and navigation.
     */
    public function show(string $slug, string $chapterNumber): JsonResponse|ChapterReaderResource
    {
        // Validate chapter number against DECIMAL(6,2) range and format
        if (! preg_match('/^[0-9]{1,4}(\.[0-9]{1,2})?$/', $chapterNumber)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Chương không tồn tại hoặc chưa được phát hành.',
            ], 404);
        }

        $canonicalNumber = sprintf('%.2f', (float) $chapterNumber);

        $comic = Comic::query()
            ->where('slug', $slug)
            ->where('approval_status', 'approved')
            ->first();

        if (! $comic) {
            return response()->json([
                'status' => 'error',
                'message' => 'Truyện không tồn tại hoặc chưa được phát hành công khai.',
            ], 404);
        }

        $chapter = Chapter::query()
            ->where('comic_id', $comic->id)
            ->where('status', 'published')
            ->where('chapter_number', $canonicalNumber)
            ->with([
                'images' => function ($q) {
                    $q->orderBy('page_number', 'asc')->orderBy('id', 'asc');
                },
            ])
            ->first();

        if (! $chapter) {
            return response()->json([
                'status' => 'error',
                'message' => 'Chương không tồn tại hoặc chưa được phát hành.',
            ], 404);
        }

        // Avoid extra query for comic in resource
        $chapter->setRelation('comic', $comic);

        // Fetch adjacent published chapters for reader navigation
        $prevChapter = Chapter::query()
            ->where('comic_id', $comic->id)
            ->where('status', 'published')
            ->where('chapter_number', '<', $canonicalNumber)
            ->orderBy('chapter_number', 'desc')
            ->orderBy('id', 'desc')
            ->first(['id', 'chapter_number', 'title', 'slug']);

        $nextChapter = Chapter::query()
            ->where('comic_id', $comic->id)
            ->where('status', 'published')
            ->where('chapter_number', '>', $canonicalNumber)
            ->orderBy('chapter_number', 'asc')
            ->orderBy('id', 'asc')
            ->first(['id', 'chapter_number', 'title', 'slug']);

        $navigation = [
            'prev_chapter' => $prevChapter ? [
                'id' => $prevChapter->id,
                'chapter_number' => (string) $prevChapter->chapter_number,
                'title' => $prevChapter->title,
                'slug' => $prevChapter->slug,
            ] : null,
            'next_chapter' => $nextChapter ? [
                'id' => $nextChapter->id,
                'chapter_number' => (string) $nextChapter->chapter_number,
                'title' => $nextChapter->title,
                'slug' => $nextChapter->slug,
            ] : null,
        ];

        return new ChapterReaderResource($chapter, $navigation);
    }
}
