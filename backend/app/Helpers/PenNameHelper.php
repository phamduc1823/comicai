<?php

namespace App\Helpers;

use RuntimeException;

class PenNameHelper
{
    /**
     * Regex pattern enforcing 3-50 Unicode letters, numbers, spaces, and punctuation: . _ -
     * Disallows emojis and unapproved special characters.
     */
    public const VALID_PATTERN = '/^[\p{L}\p{N} ._-]{3,50}$/u';

    /**
     * Ensure the mandatory ext-intl extension is loaded for strict Unicode NFC normalization.
     *
     * @throws RuntimeException
     */
    public static function ensureIntlAvailable(): void
    {
        if (! class_exists(\Normalizer::class)) {
            throw new RuntimeException('PHP intl extension is strictly required for Creator pen name Unicode NFC normalization.');
        }
    }

    /**
     * Normalize a pen name:
     * 1. Trim leading and trailing whitespace.
     * 2. Collapse internal duplicate whitespace into a single space.
     * 3. Normalize into Unicode Form C (NFC - Canonical Composition).
     */
    public static function normalize(string $penName): string
    {
        self::ensureIntlAvailable();

        $trimmed = preg_replace('/\s+/u', ' ', trim($penName)) ?? '';

        $normalized = \Normalizer::normalize($trimmed, \Normalizer::FORM_C);

        return $normalized !== false ? $normalized : $trimmed;
    }

    /**
     * Create the lowercase canonical representation for case-insensitive uniqueness checks.
     */
    public static function canonicalize(string $penName): string
    {
        $normalized = self::normalize($penName);

        return mb_strtolower($normalized, 'UTF-8');
    }

    /**
     * Validate whether a raw pen name satisfies character and length constraints.
     */
    public static function isValid(string $penName): bool
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($penName)) ?? '';
        $length = mb_strlen($normalized, 'UTF-8');

        if ($length < 3 || $length > 50) {
            return false;
        }

        return (bool) preg_match(self::VALID_PATTERN, $normalized);
    }
}
