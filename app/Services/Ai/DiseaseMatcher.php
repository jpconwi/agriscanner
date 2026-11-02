<?php

namespace App\Services\Ai;

use App\Models\Crop;
use App\Models\Disease;
use App\Services\Ai\Dto\AnalysisResult;
use App\Support\LabelNormalizer;

/**
 * Maps a model label onto the curated reference library. Recommendations come ONLY from the library:
 * unknown conditions produce no treatment text, never an invented one.
 */
class DiseaseMatcher
{
    /** @return array{crop: ?Crop, disease: ?Disease, recommendations: array} */
    public function match(AnalysisResult $result, ?int $cropHintId = null): array
    {
        $crop = $cropHintId ? Crop::find($cropHintId) : null;
        $crop ??= $this->matchCrop($result->cropLabel) ?? $this->matchCropInCondition($result->conditionLabel);

        $disease = null;
        if (! $result->isHealthy) {
            $disease = $this->matchDisease(LabelNormalizer::words($result->conditionLabel), $crop);
            if ($disease && ! $crop && $disease->crop_id) {
                $crop = $disease->crop;
            }
        }

        return [
            'crop' => $crop,
            'disease' => $disease,
            'recommendations' => $disease ? $disease->recommendationSnapshot() : $this->empty(),
        ];
    }

    public function empty(): array
    {
        return ['symptoms' => [], 'contributing_factors' => [], 'treatments' => [], 'prevention' => [], 'follow_up' => null];
    }

    private function matchCrop(?string $label): ?Crop
    {
        if (! $label) {
            return null;
        }
        $words = LabelNormalizer::words($label);

        return Crop::all()->first(fn (Crop $c) => LabelNormalizer::words($c->name) === $words
            || str_contains($words, LabelNormalizer::words($c->name)));
    }

    private function matchCropInCondition(string $condition): ?Crop
    {
        $words = LabelNormalizer::words($condition);

        return Crop::all()->first(fn (Crop $c) => preg_match('/\b'.preg_quote(LabelNormalizer::words($c->name), '/').'\b/', $words) === 1);
    }

    private function matchDisease(string $conditionWords, ?Crop $crop): ?Disease
    {
        if ($conditionWords === '' || $conditionWords === 'unknown') {
            return null;
        }
        $candidates = Disease::with('crop')
            ->when($crop, fn ($q) => $q->where(fn ($qq) => $qq->where('crop_id', $crop->id)->orWhereNull('crop_id')))
            ->get();

        $best = null;
        $bestScore = 0;
        foreach ($candidates as $d) {
            $name = LabelNormalizer::words($d->name);
            $score = match (true) {
                $name === $conditionWords => 100,
                // "early blight" inside "tomato early blight" (or the reverse), weighted by how much text matches.
                str_contains($conditionWords, $name) => 50 + strlen($name),
                str_contains($name, $conditionWords) => 40 + strlen($conditionWords),
                default => 0,
            };
            if ($crop && $d->crop_id === $crop->id) {
                $score += $score > 0 ? 5 : 0;
            }
            if ($score > $bestScore) {
                $best = $d;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
