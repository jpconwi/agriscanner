<?php

namespace App\Services\Ai;

use App\Models\Crop;
use App\Models\Disease;
use App\Services\Ai\Dto\AnalysisResult;
use App\Support\LabelNormalizer;

/**
 * Maps a model label onto the curated reference library. A library match always wins and supplies the verified
 * recommendations. With no match, the model's own general guidance is used instead (already stripped of doses and
 * product names by AiGuidance) and is tagged source=ai so it is shown as unverified. A classifier that returns only
 * a label has no guidance, so those scans still get no text for conditions outside the library.
 */
class DiseaseMatcher
{
    /** @return array{crop: ?Crop, disease: ?Disease, crop_disease_conflict: bool, recommendations: array} */
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
        $cropDiseaseConflict = false;
        if (! $result->isHealthy && ! $disease && $crop) {
            $cropDiseaseConflict = $this->matchesDiseaseForAnotherCrop(LabelNormalizer::words($result->conditionLabel), $crop);
        }

        return [
            'crop' => $crop,
            'disease' => $disease,
            'crop_disease_conflict' => $cropDiseaseConflict,
            'recommendations' => $cropDiseaseConflict
                ? $this->conflictRecommendations()
                : $this->recommendations($disease, $result->details),
        ];
    }

    private function matchesDiseaseForAnotherCrop(string $conditionWords, Crop $crop): bool
    {
        if ($conditionWords === '' || $conditionWords === 'unknown') {
            return false;
        }

        return Disease::query()
            ->whereNotNull('crop_id')
            ->where('crop_id', '!=', $crop->id)
            ->get()
            ->contains(function (Disease $disease) use ($conditionWords): bool {
                $name = LabelNormalizer::words($disease->name);

                return $conditionWords === $name || str_contains(' '.$conditionWords.' ', ' '.$name.' ');
            });
    }

    private function conflictRecommendations(): array
    {
        return [
            'symptoms' => [],
            'contributing_factors' => [],
            'treatments' => [],
            'prevention' => [],
            'follow_up' => null,
            'summary' => null,
            'source' => 'conflict',
        ];
    }

    /** @param ?array $details sanitised AI guidance, or null */
    private function recommendations(?Disease $disease, ?array $details): array
    {
        if ($disease) {
            return $disease->recommendationSnapshot() + ['summary' => $details['summary'] ?? null, 'source' => 'library'];
        }
        if ($details === null) {
            return $this->empty();
        }

        return [
            'symptoms' => $details['symptoms'],
            'contributing_factors' => $details['causes'],
            'treatments' => $details['management'],
            'prevention' => $details['prevention'],
            'follow_up' => $details['follow_up'] ?? null,
            'summary' => $details['summary'],
            'source' => 'ai',
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
