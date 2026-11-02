<?php

namespace App\Support;

/**
 * Pure helpers for turning model output labels (e.g. "Tomato___Early_blight",
 * "Apple scab", "healthy tomato leaf") into comparable parts. No framework dependencies.
 */
final class LabelNormalizer
{
    public static function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';

        return trim($value, '-');
    }

    /** Lowercase, collapse separators ("_", "-", "___", punctuation) into single spaces. */
    public static function words(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * Splits PlantVillage-style labels "Crop___Condition" into [crop, condition].
     * Labels without the triple-underscore separator return [null, whole label].
     *
     * @return array{0: ?string, 1: string}
     */
    public static function split(string $label): array
    {
        if (str_contains($label, '___')) {
            [$crop, $condition] = explode('___', $label, 2);

            return [self::words(str_replace(',', ' ', $crop)) ?: null, self::words($condition)];
        }

        return [null, self::words($label)];
    }

    public static function isHealthy(string $conditionWords): bool
    {
        return (bool) preg_match('/\b(healthy|no disease|normal)\b/', $conditionWords);
    }
}
