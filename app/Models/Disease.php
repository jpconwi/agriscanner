<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Disease extends Model
{
    protected $fillable = ['crop_id', 'name', 'slug', 'description', 'severity', 'follow_up'];

    protected static function booted(): void
    {
        static::saving(function (Disease $d) {
            $d->slug = Str::slug($d->name);
        });
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function symptomRows(): HasMany
    {
        return $this->hasMany(DiseaseSymptom::class)->orderBy('position');
    }

    public function treatmentRows(): HasMany
    {
        return $this->hasMany(DiseaseTreatment::class)->orderBy('position');
    }

    /** @return list<string> */
    private function texts($rows, string $kind): array
    {
        return $rows->where('kind', $kind)->pluck('text')->values()->all();
    }

    /** @return array{symptoms: list<string>, contributing_factors: list<string>, treatments: list<string>, prevention: list<string>, follow_up: ?string} */
    public function recommendationSnapshot(): array
    {
        $this->loadMissing(['symptomRows', 'treatmentRows']);

        return [
            'symptoms' => $this->texts($this->symptomRows, 'symptom'),
            'contributing_factors' => $this->texts($this->symptomRows, 'factor'),
            'treatments' => $this->texts($this->treatmentRows, 'treatment'),
            'prevention' => $this->texts($this->treatmentRows, 'prevention'),
            'follow_up' => $this->follow_up,
        ];
    }

    /** Create/update from validated admin input (crop, name, description, severity, follow_up + the four lists). */
    public function saveWithReference(array $v): static
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($v) {
            $this->fill(collect($v)->only(['crop_id', 'name', 'description', 'severity', 'follow_up'])->all())->save();
            $this->syncReference($v['symptoms'] ?? [], $v['contributing_factors'] ?? [], $v['treatments'] ?? [], $v['prevention'] ?? []);
        });

        return $this;
    }

    /** Replace child rows from plain string lists (used by the admin API). */
    public function syncReference(array $symptoms, array $factors, array $treatments, array $prevention): void
    {
        $this->symptomRows()->delete();
        $this->treatmentRows()->delete();
        $rows = [];
        foreach ([['symptom', $symptoms], ['factor', $factors]] as [$kind, $items]) {
            foreach (array_values($items) as $i => $text) {
                $rows[] = new DiseaseSymptom(['kind' => $kind, 'text' => $text, 'position' => $i]);
            }
        }
        $this->symptomRows()->saveMany($rows);
        $rows = [];
        foreach ([['treatment', $treatments], ['prevention', $prevention]] as [$kind, $items]) {
            foreach (array_values($items) as $i => $text) {
                $rows[] = new DiseaseTreatment(['kind' => $kind, 'text' => $text, 'position' => $i]);
            }
        }
        $this->treatmentRows()->saveMany($rows);
        $this->unsetRelation('symptomRows');
        $this->unsetRelation('treatmentRows');
    }
}
