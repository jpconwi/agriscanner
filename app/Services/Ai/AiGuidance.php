<?php

namespace App\Services\Ai;

/**
 * Cleans the free-text guidance a vision model writes about a photo. The model is asked not to give doses or
 * product names, and this is the safety net: any line that still contains an application rate, a mixing amount
 * or a well-known pesticide active ingredient is dropped, never shown to a farmer. Best effort, not a guarantee,
 * which is why the result page labels this content as AI-generated and unverified.
 */
final class AiGuidance
{
    private const LIMITS = ['symptoms' => 5, 'causes' => 4, 'management' => 5, 'prevention' => 4];

    private const DOSE = '/\d\s*(%|percent)|\b\d+(?:[.,]\d+)?\s*(?:ml|l|litres?|liters?|g|grams?|kg|oz|lbs?|ppm|tbsp|tsp|cups?|gal|gallons?)\b|\bper\s+(?:hectare|ha|acre|litre|liter|gallon|tank|knapsack)\b|\b(?:dilute|dilution|tank[- ]mix)\b/iu';

    private const ACTIVES = '/\b(?:mancozeb|chlorothalonil|carbendazim|azoxystrobin|tricyclazole|propiconazole|difenoconazole|tebuconazole|hexaconazole|copper\s+(?:oxychloride|hydroxide|sulfate|sulphate)|bordeaux|metalaxyl|mefenoxam|imidacloprid|cypermethrin|cyhalothrin|chlorpyrifos|glyphosate|streptomycin|kasugamycin|validamycin|isoprothiolane|fosetyl|thiophanate|captan|profenofos|abamectin|emamectin)\b/iu';

    /**
     * @param  array<string, mixed>  $json  the decoded model reply
     * @return array{summary: ?string, symptoms: list<string>, causes: list<string>, management: list<string>, prevention: list<string>}|null
     */
    public static function sanitize(array $json): ?array
    {
        $summary = self::line($json['summary'] ?? null, 400);
        $out = ['summary' => $summary !== null && ! self::unsafe($summary) ? $summary : null];

        foreach (self::LIMITS as $key => $max) {
            $items = is_array($json[$key] ?? null) ? $json[$key] : [];
            $clean = [];
            foreach ($items as $item) {
                $line = self::line($item, 200);
                if ($line !== null && ! self::unsafe($line) && ! in_array($line, $clean, true)) {
                    $clean[] = $line;
                }
            }
            $out[$key] = array_slice($clean, 0, $max);
        }

        $empty = $out['summary'] === null && ! $out['symptoms'] && ! $out['causes'] && ! $out['management'] && ! $out['prevention'];

        return $empty ? null : $out;
    }

    public static function unsafe(string $text): bool
    {
        return preg_match(self::DOSE, $text) === 1 || preg_match(self::ACTIVES, $text) === 1;
    }

    private static function line(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
