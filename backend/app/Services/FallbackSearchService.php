<?php

namespace App\Services;

use App\Models\Comic;
use App\Services\Contracts\AiSearchServiceInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class FallbackSearchService implements AiSearchServiceInterface
{
    /**
     * Search comics using database keyword & relational matching as a safe fallback.
     *
     * @param string $query Natural language search query
     * @param array<string, mixed> $filters Optional pre-filters (status, age_rating, genre, tag, require_published_chapters)
     * @param int $limit Maximum number of results to return
     * @return array<int, array<string, mixed>> Standardized list of search results
     */
    public function search(string $query, array $filters = [], int $limit = 15): array
    {
        $normalizedQuery = trim($query);

        // Pre-condition: Minimum query length guard (at least 2 characters)
        if (mb_strlen($normalizedQuery) < 2) {
            return [];
        }

        // Cap maximum query length to prevent abusive query sizes
        if (mb_strlen($normalizedQuery) > 300) {
            $normalizedQuery = mb_substr($normalizedQuery, 0, 300);
        }

        // Extract individual alphanumeric keywords (length >= 2) for tokenized matching
        $tokens = preg_split('/[\s,\.\?!;:\-\+]+/u', mb_strtolower($normalizedQuery), -1, PREG_SPLIT_NO_EMPTY);
        $rawKeywords = array_values(array_filter($tokens, fn ($term) => mb_strlen($term) >= 2));
        $stopwords = ['truyện', 'bộ', 'những', 'các', 'của', 'về', 'cho', 'và', 'là', 'có', 'trong', 'với', 'một', 'được', 'này', 'đó', 'tôi', 'hay', 'rất'];
        $filtered = array_values(array_filter($rawKeywords, fn ($kw) => ! in_array($kw, $stopwords, true)));
        $keywords = ! empty($filtered) ? $filtered : $rawKeywords;

        // 1. Base Query with Pre-filtering: approved comics only
        $baseQuery = Comic::query()
            ->where('approval_status', 'approved')
            ->with([
                'genres:id,name,slug',
                'tags:id,name,slug',
                'user:id,name,avatar_path',
            ]);

        // Pre-filtering: Comic status (exclude 'draft' by default, or match explicit filter)
        if (! empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $baseQuery->whereIn('status', $filters['status']);
            } else {
                $baseQuery->where('status', $filters['status']);
            }
        } else {
            $baseQuery->where('status', '!=', 'draft');
        }

        // Pre-filtering: Age rating filter
        if (! empty($filters['age_rating'])) {
            $baseQuery->where('age_rating', $filters['age_rating']);
        }

        // Pre-filtering: Genre filter (by slug or id)
        if (! empty($filters['genre'])) {
            $baseQuery->whereHas('genres', function (Builder $q) use ($filters) {
                if (is_array($filters['genre'])) {
                    $q->whereIn('genres.slug', $filters['genre']);
                } elseif (is_numeric($filters['genre'])) {
                    $q->where('genres.id', (int) $filters['genre']);
                } else {
                    $q->where('genres.slug', $filters['genre']);
                }
            });
        }

        // Pre-filtering: Tag filter (by slug or id)
        if (! empty($filters['tag'])) {
            $baseQuery->whereHas('tags', function (Builder $q) use ($filters) {
                if (is_array($filters['tag'])) {
                    $q->whereIn('tags.slug', $filters['tag']);
                } elseif (is_numeric($filters['tag'])) {
                    $q->where('tags.id', (int) $filters['tag']);
                } else {
                    $q->where('tags.slug', $filters['tag']);
                }
            });
        }

        // Pre-filtering: Optional check for published chapters
        if (! empty($filters['require_published_chapters'])) {
            $baseQuery->whereHas('chapters', function (Builder $q) {
                $q->where('status', 'published');
            });
        }

        // 2. Keyword & Relational Search Condition (deduplicated terms and consolidated subqueries)
        $searchTerms = array_values(array_unique(array_filter(array_merge([$normalizedQuery], $keywords))));

        $baseQuery->where(function (Builder $builder) use ($searchTerms) {
            foreach ($searchTerms as $term) {
                $escaped = addcslashes($term, '%_\\');
                $like = "%{$escaped}%";

                $builder->orWhereRaw('title LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('alternative_titles LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('author_name LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('description LIKE ? ESCAPE ?', [$like, '\\']);
            }

            $builder->orWhereHas('tags', function (Builder $tq) use ($searchTerms) {
                $tq->where(function (Builder $inner) use ($searchTerms) {
                    foreach ($searchTerms as $term) {
                        $escaped = addcslashes($term, '%_\\');
                        $like = "%{$escaped}%";
                        $inner->orWhereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                            ->orWhereRaw('slug LIKE ? ESCAPE ?', [$like, '\\']);
                    }
                });
            });

            $builder->orWhereHas('genres', function (Builder $gq) use ($searchTerms) {
                $gq->where(function (Builder $inner) use ($searchTerms) {
                    foreach ($searchTerms as $term) {
                        $escaped = addcslashes($term, '%_\\');
                        $like = "%{$escaped}%";
                        $inner->orWhereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                            ->orWhereRaw('slug LIKE ? ESCAPE ?', [$like, '\\']);
                    }
                });
            });
        });

        // 3. Retrieve candidates
        $candidates = $baseQuery->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        // 4. In-memory Hybrid Scoring & Reason Construction
        $scoredResults = [];
        $lowerQuery = mb_strtolower($normalizedQuery);

        foreach ($candidates as $comic) {
            $score = 0.0;
            $reasons = [];
            $matchedTags = [];
            $matchedGenres = [];

            $titleLower = mb_strtolower($comic->title);
            $altTitlesLower = mb_strtolower((string) $comic->alternative_titles);
            $authorLower = mb_strtolower($comic->author_name);
            $descLower = mb_strtolower((string) $comic->description);

            // Title exact/contain match
            if ($titleLower === $lowerQuery) {
                $score += 1.0;
                $reasons[] = 'Khớp chính xác tiêu đề';
            } elseif (str_contains($titleLower, $lowerQuery)) {
                $score += 0.8;
                $reasons[] = 'Khớp cụm từ trong tiêu đề';
            } else {
                $titleMatches = 0;
                foreach ($keywords as $kw) {
                    if (str_contains($titleLower, $kw)) {
                        $titleMatches++;
                    }
                }
                if ($titleMatches > 0) {
                    $score += min(0.6, $titleMatches * 0.25);
                    $reasons[] = "Khớp từ khóa tiêu đề ({$titleMatches})";
                }
            }

            // Alternative titles match
            if (! empty($altTitlesLower) && str_contains($altTitlesLower, $lowerQuery)) {
                $score += 0.5;
                $reasons[] = 'Khớp tên khác';
            }

            // Author match
            if (str_contains($authorLower, $lowerQuery)) {
                $score += 0.6;
                $reasons[] = 'Khớp tên tác giả';
            }

            // Tag match (key semantic bridge)
            foreach ($comic->tags as $tag) {
                $tagNameLower = mb_strtolower($tag->name);
                $tagSlugLower = mb_strtolower($tag->slug);

                if (str_contains($lowerQuery, $tagNameLower) || str_contains($tagNameLower, $lowerQuery)) {
                    $score += 0.4;
                    $matchedTags[] = $tag->name;
                } else {
                    foreach ($keywords as $kw) {
                        if (str_contains($tagNameLower, $kw) || str_contains($tagSlugLower, $kw)) {
                            $score += 0.25;
                            $matchedTags[] = $tag->name;
                            break;
                        }
                    }
                }
            }
            $matchedTags = array_values(array_unique($matchedTags));
            if (! empty($matchedTags)) {
                $reasons[] = 'Khớp đặc điểm/tag: ' . implode(', ', $matchedTags);
            }

            // Genre match
            foreach ($comic->genres as $genre) {
                $genreNameLower = mb_strtolower($genre->name);
                $genreSlugLower = mb_strtolower($genre->slug);

                if (str_contains($lowerQuery, $genreNameLower) || str_contains($genreNameLower, $lowerQuery)) {
                    $score += 0.35;
                    $matchedGenres[] = $genre->name;
                } else {
                    foreach ($keywords as $kw) {
                        if (str_contains($genreNameLower, $kw) || str_contains($genreSlugLower, $kw)) {
                            $score += 0.2;
                            $matchedGenres[] = $genre->name;
                            break;
                        }
                    }
                }
            }
            $matchedGenres = array_values(array_unique($matchedGenres));
            if (! empty($matchedGenres)) {
                $reasons[] = 'Khớp thể loại: ' . implode(', ', $matchedGenres);
            }

            // Description match
            if (! empty($descLower)) {
                if (str_contains($descLower, $lowerQuery)) {
                    $score += 0.35;
                    $reasons[] = 'Khớp mô tả truyện';
                } else {
                    $descMatches = 0;
                    foreach ($keywords as $kw) {
                        if (str_contains($descLower, $kw)) {
                            $descMatches++;
                        }
                    }
                    if ($descMatches > 0) {
                        $score += min(0.3, $descMatches * 0.1);
                        $reasons[] = "Khớp ngữ cảnh mô tả ({$descMatches})";
                    }
                }
            }

            // AI summary match (if present)
            if (! empty($comic->ai_summary)) {
                $aiSummaryLower = mb_strtolower($comic->ai_summary);
                if (str_contains($aiSummaryLower, $lowerQuery)) {
                    $score += 0.3;
                    $reasons[] = 'Khớp tóm tắt AI';
                }
            }

            // Relevancy Threshold filter (drop low-confidence noisy matches)
            $minThreshold = (float) ($filters['min_score'] ?? 0.20);
            if ($score < $minThreshold) {
                continue;
            }

            // Normalize score between 0.05 and 1.0
            $finalScore = round(min(1.0, max(0.05, $score)), 4);
            $reasonText = ! empty($reasons) ? implode('; ', $reasons) : 'Khớp từ khóa liên quan';

            $scoredResults[] = [
                'id' => $comic->id,
                'title' => $comic->title,
                'slug' => $comic->slug,
                'cover_path' => $comic->cover_path,
                'cover_url' => $comic->cover_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($comic->cover_path) : null,
                'author_name' => $comic->author_name,
                'age_rating' => $comic->age_rating,
                'status' => $comic->status,
                'score' => $finalScore,
                'is_fallback' => true,
                'match_type' => 'clue',
                'match_reason' => $reasonText,
                'matched_tags' => $matchedTags,
                'matched_genres' => $matchedGenres,
                'ai_summary' => $comic->ai_summary,
                'views_count' => (int) $comic->views_count,
            ];
        }

        // Sort by score descending, then views_count descending, then id descending
        usort($scoredResults, function ($a, $b) {
            if ($b['score'] !== $a['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($b['views_count'] !== $a['views_count']) {
                return $b['views_count'] <=> $a['views_count'];
            }
            return $b['id'] <=> $a['id'];
        });

        // Strip temporary sorting fields and apply limit
        $finalResults = array_map(function ($item) {
            unset($item['views_count']);
            return $item;
        }, array_slice($scoredResults, 0, $limit));

        return $finalResults;
    }

    /**
     * Search comics using structured AI-extracted clues (series, characters, visual clues, events).
     *
     * @param array<string, mixed> $clues Structured clues from query understanding
     * @param array<string, mixed> $filters Optional pre-filters
     * @param int $limit Maximum number of results to return
     * @param string|null $rawQuery Optional raw query string
     * @return array<int, array<string, mixed>> Standardized list of search results
     */
    public function searchWithClues(array $clues, array $filters = [], int $limit = 15, ?string $rawQuery = null): array
    {
        $series = isset($clues['series']) && is_string($clues['series']) ? trim($clues['series']) : null;
        $characters = is_array($clues['characters'] ?? null) ? array_filter($clues['characters']) : [];
        $events = is_array($clues['remembered_events'] ?? null) ? array_filter($clues['remembered_events']) : [];
        $visualClues = is_array($clues['visual_clues'] ?? null) ? array_filter($clues['visual_clues']) : [];
        $opponent = isset($clues['opponent_description']) && is_string($clues['opponent_description']) ? trim($clues['opponent_description']) : null;
        $keywords = is_array($clues['keywords'] ?? null) ? array_filter($clues['keywords']) : [];

        // Gather all search candidate terms
        $allTerms = [];
        if (! empty($series)) {
            $allTerms[] = $series;
        }
        if (! empty($opponent)) {
            $allTerms[] = $opponent;
        }
        foreach (array_merge($characters, $events, $visualClues, $keywords) as $t) {
            if (is_string($t) && mb_strlen(trim($t)) >= 2) {
                $allTerms[] = trim($t);
            }
        }
        $allTerms = array_values(array_unique($allTerms));

        if (empty($allTerms)) {
            return [];
        }

        // 1. Base query with pre-filtering
        $baseQuery = Comic::query()
            ->where('approval_status', 'approved')
            ->with([
                'genres:id,name,slug',
                'tags:id,name,slug',
                'user:id,name,avatar_path',
                'chapters:id,comic_id,chapter_number,title,slug,status',
            ]);

        if (! empty($filters['status'])) {
            $baseQuery->where('status', $filters['status']);
        } else {
            $baseQuery->where('status', '!=', 'draft');
        }

        if (! empty($filters['age_rating'])) {
            $baseQuery->where('age_rating', $filters['age_rating']);
        }

        if (! empty($filters['genre'])) {
            $baseQuery->whereHas('genres', function (Builder $q) use ($filters) {
                if (is_numeric($filters['genre'])) {
                    $q->where('genres.id', (int) $filters['genre']);
                } else {
                    $q->where('genres.slug', $filters['genre']);
                }
            });
        }

        if (! empty($filters['tag'])) {
            $baseQuery->whereHas('tags', function (Builder $q) use ($filters) {
                if (is_numeric($filters['tag'])) {
                    $q->where('tags.id', (int) $filters['tag']);
                } else {
                    $q->where('tags.slug', $filters['tag']);
                }
            });
        }

        // 2. Multi-field and relational search matching (deduplicated subqueries and safe ESCAPE)
        $baseQuery->where(function (Builder $builder) use ($allTerms) {
            foreach ($allTerms as $term) {
                $escaped = addcslashes($term, '%_\\');
                $like = "%{$escaped}%";

                $builder->orWhereRaw('title LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('alternative_titles LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('description LIKE ? ESCAPE ?', [$like, '\\']);
            }

            $builder->orWhereHas('tags', function (Builder $tq) use ($allTerms) {
                $tq->where(function (Builder $inner) use ($allTerms) {
                    foreach ($allTerms as $term) {
                        $escaped = addcslashes($term, '%_\\');
                        $like = "%{$escaped}%";
                        $inner->orWhereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                            ->orWhereRaw('slug LIKE ? ESCAPE ?', [$like, '\\']);
                    }
                });
            });

            $builder->orWhereHas('genres', function (Builder $gq) use ($allTerms) {
                $gq->where(function (Builder $inner) use ($allTerms) {
                    foreach ($allTerms as $term) {
                        $escaped = addcslashes($term, '%_\\');
                        $like = "%{$escaped}%";
                        $inner->orWhereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                            ->orWhereRaw('slug LIKE ? ESCAPE ?', [$like, '\\']);
                    }
                });
            });

            $builder->orWhereHas('chapters', function (Builder $cq) use ($allTerms) {
                $cq->where('status', 'published')
                    ->where(function (Builder $inner) use ($allTerms) {
                        foreach ($allTerms as $term) {
                            $escaped = addcslashes($term, '%_\\');
                            $like = "%{$escaped}%";
                            $inner->orWhereRaw('title LIKE ? ESCAPE ?', [$like, '\\']);
                        }
                    });
            });
        });

        $candidates = $baseQuery->get();
        if ($candidates->isEmpty()) {
            return [];
        }

        // 3. Score candidates using structured clues
        $scoredResults = [];
        foreach ($candidates as $comic) {
            $score = 0.0;
            $reasons = [];
            $matchedTags = [];
            $matchedGenres = [];

            $titleLower = mb_strtolower($comic->title);
            $altLower = mb_strtolower((string) $comic->alternative_titles);
            $descLower = mb_strtolower((string) $comic->description);

            // Series match
            if (! empty($series)) {
                $seriesLower = mb_strtolower($series);
                if (str_contains($titleLower, $seriesLower) || str_contains($altLower, $seriesLower)) {
                    $score += 0.9;
                    $reasons[] = "Tác phẩm: {$series}";
                }
            }

            // Character matches
            $matchedChars = [];
            foreach ($characters as $char) {
                $charLower = mb_strtolower($char);
                if (str_contains($titleLower, $charLower) || str_contains($descLower, $charLower) || str_contains($altLower, $charLower)) {
                    $score += 0.5;
                    $matchedChars[] = $char;
                }
            }
            if (! empty($matchedChars)) {
                $reasons[] = "Nhân vật: " . implode(', ', $matchedChars);
            }

            // Visual clues match
            $matchedVisual = [];
            foreach ($visualClues as $vc) {
                $vcLower = mb_strtolower($vc);
                if (str_contains($descLower, $vcLower)) {
                    $score += 0.4;
                    $matchedVisual[] = $vc;
                }
            }
            if (! empty($matchedVisual)) {
                $reasons[] = "Chi tiết thị giác: " . implode(', ', $matchedVisual);
            }

            // Chapter title match
            $matchedChapters = [];
            foreach ($comic->chapters as $chapter) {
                if ($chapter->status !== 'published' || empty($chapter->title)) {
                    continue;
                }
                $chapTitleLower = mb_strtolower($chapter->title);
                foreach ($allTerms as $term) {
                    if (str_contains($chapTitleLower, mb_strtolower($term))) {
                        $score += 0.35;
                        $matchedChapters[] = "Chương {$chapter->chapter_number}: {$chapter->title}";
                        break;
                    }
                }
            }
            if (! empty($matchedChapters)) {
                $reasons[] = "Khớp tên chương: " . implode(', ', array_slice($matchedChapters, 0, 2));
            }

            // Tag matches
            foreach ($comic->tags as $tag) {
                $tagNameLower = mb_strtolower($tag->name);
                foreach ($allTerms as $term) {
                    if (str_contains($tagNameLower, mb_strtolower($term))) {
                        $score += 0.3;
                        $matchedTags[] = $tag->name;
                        break;
                    }
                }
            }
            $matchedTags = array_values(array_unique($matchedTags));
            if (! empty($matchedTags)) {
                $reasons[] = "Tag: " . implode(', ', $matchedTags);
            }

            // Genre matches
            foreach ($comic->genres as $genre) {
                $genreNameLower = mb_strtolower($genre->name);
                foreach ($allTerms as $term) {
                    if (str_contains($genreNameLower, mb_strtolower($term))) {
                        $score += 0.25;
                        $matchedGenres[] = $genre->name;
                        break;
                    }
                }
            }
            $matchedGenres = array_values(array_unique($matchedGenres));

            // Keywords / Events match in description
            foreach (array_merge($events, $keywords) as $kw) {
                if (str_contains($descLower, mb_strtolower($kw))) {
                    $score += 0.2;
                }
            }

            $minThreshold = (float) ($filters['min_score'] ?? 0.20);
            if ($score < $minThreshold) {
                continue;
            }

            $finalScore = round(min(1.0, max(0.1, $score)), 4);
            $reasonText = ! empty($reasons)
                ? 'AI phân tích manh mối: ' . implode('; ', $reasons)
                : 'Khớp từ khóa do AI phân tích';

            $scoredResults[] = [
                'id' => $comic->id,
                'title' => $comic->title,
                'slug' => $comic->slug,
                'cover_path' => $comic->cover_path,
                'cover_url' => $comic->cover_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($comic->cover_path) : null,
                'author_name' => $comic->author_name,
                'age_rating' => $comic->age_rating,
                'status' => $comic->status,
                'score' => $finalScore,
                'is_fallback' => false,
                'match_type' => 'clue',
                'match_reason' => $reasonText,
                'matched_tags' => $matchedTags,
                'matched_genres' => $matchedGenres,
                'ai_summary' => $comic->ai_summary,
                'views_count' => (int) $comic->views_count,
            ];
        }

        usort($scoredResults, function ($a, $b) {
            if ($b['score'] !== $a['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($b['views_count'] !== $a['views_count']) {
                return $b['views_count'] <=> $a['views_count'];
            }
            return $b['id'] <=> $a['id'];
        });

        return array_map(function ($item) {
            unset($item['views_count']);
            return $item;
        }, array_slice($scoredResults, 0, $limit));
    }

    /**
     * Search comics using hybrid fallback: uses searchWithClues if clues are given, else plain search.
     *
     * @param string $query Natural language search query
     * @param array<string, mixed> $filters Optional pre-filters
     * @param int $limit Maximum number of results to return
     * @param array<string, mixed>|null $clues Optional structured clues
     * @return array<int, array<string, mixed>> Standardized list of search results
     */
    public function searchHybrid(string $query, array $filters = [], int $limit = 15, ?array $clues = null): array
    {
        if (! empty($clues)) {
            return $this->searchWithClues($clues, $filters, $limit, $query);
        }

        return $this->search($query, $filters, $limit);
    }

    /**
     * Generate a deterministic baseline excerpt and metadata context when AI is unavailable.
     *
     * @param string $description Comic raw description
     * @param array<int, string> $genres Genre names
     * @param array<int, string> $tags Tag names
     * @return string|null Formatted summary or null
     */
    public function generateSummary(string $description, array $genres = [], array $tags = []): ?string
    {
        $trimmed = trim($description);
        if (empty($trimmed)) {
            return null;
        }

        $excerpt = Str::limit($trimmed, 250);
        $contextParts = [];

        if (! empty($genres)) {
            $contextParts[] = 'Thể loại: ' . implode(', ', array_filter($genres));
        }

        if (! empty($tags)) {
            $contextParts[] = 'Đặc điểm: ' . implode(', ', array_filter($tags));
        }

        if (! empty($contextParts)) {
            return $excerpt . "\n[" . implode(' | ', $contextParts) . ']';
        }

        return $excerpt;
    }

    /**
     * Fallback search service is always available locally.
     */
    public function isAvailable(): bool
    {
        return true;
    }
}
