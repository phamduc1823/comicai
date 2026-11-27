<?php

namespace App\Services;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

class TagService
{
    /**
     * Normalize a community tag according to ComicAI project rules:
     * - Convert to lowercase
     * - Remove Vietnamese accents
     * - Remove leading '#'
     * - Remove whitespace
     * - Allow only safe alphanumeric slug characters [a-z0-9]
     * - Max 50 characters
     */
    public static function normalizeCommunityTag(string $input): string
    {
        $text = trim($input);

        // Strip leading '#' characters
        $text = ltrim($text, '#');

        // Remove Vietnamese accents
        $text = self::stripVietnameseAccents($text);

        // Lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Remove all spaces and whitespace
        $text = preg_replace('/\s+/', '', $text);

        // Keep only alphanumeric characters [a-z0-9]
        $text = preg_replace('/[^a-z0-9]/', '', $text);

        // Max 50 characters
        return mb_substr($text, 0, 50);
    }

    /**
     * Convert Vietnamese accented string to plain ASCII.
     */
    public static function stripVietnameseAccents(string $str): string
    {
        $accents = [
            'a' => '/[àáạảãâầấậẩẫăằắặẳẵ]/u',
            'A' => '/[ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴ]/u',
            'e' => '/[èéẹẻẽêềếệểễ]/u',
            'E' => '/[ÈÉẸẺẼÊỀẾỆỂỄ]/u',
            'i' => '/[ìíịỉĩ]/u',
            'I' => '/[ÌÍỊỈĨ]/u',
            'o' => '/[òóọỏõôồốộổỗơờớợởỡ]/u',
            'O' => '/[ÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠ]/u',
            'u' => '/[ùúụủũưừứựửữ]/u',
            'U' => '/[ÙÚỤỦŨƯỪỨỰỬỮ]/u',
            'y' => '/[ỳýỵỷỹ]/u',
            'Y' => '/[ỲÝỴỶỸ]/u',
            'd' => '/đ/u',
            'D' => '/Đ/u',
        ];

        foreach ($accents as $nonAccent => $regex) {
            $str = preg_replace($regex, $nonAccent, $str);
        }

        return $str;
    }

    /**
     * Find an existing tag by normalized form.
     * Preserves legacy system tags by matching normalized equivalents.
     */
    public static function findExistingTag(string $normalized): ?Tag
    {
        if ($normalized === '') {
            return null;
        }

        // 1. Direct match on slug or name
        $tag = Tag::query()
            ->where('slug', $normalized)
            ->orWhere('name', $normalized)
            ->first();

        if ($tag) {
            return $tag;
        }

        // 2. Match with hyphens stripped in slug (e.g. 'xuyen-khong' -> 'xuyenkhong')
        $tag = Tag::query()
            ->whereRaw("REPLACE(slug, '-', '') = ?", [$normalized])
            ->first();

        if ($tag) {
            return $tag;
        }

        // 3. Match against normalized name of existing tags
        $allTags = Tag::all();
        foreach ($allTags as $existing) {
            if (self::normalizeCommunityTag($existing->name) === $normalized) {
                return $existing;
            }
        }

        return null;
    }

    /**
     * Create or retrieve a community tag safely.
     * Prevents duplicates, handles race conditions via DB catch & re-query.
     *
     * @return array{tag: Tag, is_new: bool}
     */
    public static function createOrGetCommunityTag(string $rawInput): array
    {
        $normalized = self::normalizeCommunityTag($rawInput);

        if (mb_strlen($normalized) < 2) {
            throw new \InvalidArgumentException('Tag không hợp lệ. Tag phải chứa ít nhất 2 ký tự chữ hoặc số.');
        }

        if (mb_strlen($normalized) > 50) {
            throw new \InvalidArgumentException('Tag quá dài. Tag tối đa 50 ký tự.');
        }

        // Check if matching tag already exists (reuse existing tag)
        $existing = self::findExistingTag($normalized);
        if ($existing) {
            return [
                'tag' => $existing,
                'is_new' => false,
            ];
        }

        // Attempt creation with concurrency protection
        try {
            $tag = Tag::create([
                'name' => $normalized,
                'slug' => $normalized,
                'description' => null,
            ]);

            Tag::flushCache();

            return [
                'tag' => $tag,
                'is_new' => true,
            ];
        } catch (QueryException $e) {
            // Race condition: another request just created this slug/name
            $tag = Tag::where('slug', $normalized)->orWhere('name', $normalized)->first();
            if ($tag) {
                return [
                    'tag' => $tag,
                    'is_new' => false,
                ];
            }

            throw $e;
        }
    }

    /**
     * Suggest tags for autocomplete:
     * - Prefix match first, then substring match
     * - Exclude specified IDs
     * - Max limit (default 10)
     */
    public static function suggestTags(string $rawQuery, ?string $normalizedQuery = null, array $excludeIds = [], int $limit = 10): Collection
    {
        $q = trim($rawQuery);
        $cleanQ = $normalizedQuery ?? self::normalizeCommunityTag($q);

        if ($q === '' && $cleanQ === '') {
            return new Collection();
        }

        $query = Tag::query();

        if (! empty($excludeIds)) {
            $query->whereNotIn('id', $excludeIds);
        }

        // Match against name, slug, or slug without hyphens
        $query->where(function ($sub) use ($q, $cleanQ) {
            if ($cleanQ !== '') {
                $sub->where('slug', 'like', "%{$cleanQ}%")
                    ->orWhere('name', 'like', "%{$cleanQ}%")
                    ->orWhereRaw("REPLACE(slug, '-', '') LIKE ?", ["%{$cleanQ}%"]);
            }
            if ($q !== '' && $q !== $cleanQ) {
                $sub->orWhere('name', 'like', "%{$q}%");
            }
        });

        // Order prefix matches first, then shorter names, then alphabetical
        $query->orderByRaw(
            "CASE
                WHEN slug LIKE ? OR name LIKE ? OR REPLACE(slug, '-', '') LIKE ? THEN 0
                ELSE 1
            END ASC",
            ["{$cleanQ}%", "{$q}%", "{$cleanQ}%"]
        );

        $query->orderByRaw('LENGTH(name) ASC');
        $query->orderBy('name', 'asc');

        return $query->limit($limit)->get();
    }
}
